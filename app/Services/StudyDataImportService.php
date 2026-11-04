<?php

namespace App\Services;

use App\Models\CurriculumSubject;
use App\Models\CurriculumVersion;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudyDataImportService
{
    /**
     * @return array{kind: string, version_id: int, version_name: string, specialty_name: string, rows: array<int, array<string, mixed>>}
     */
    public function preview(string $kind, string $html, CurriculumVersion $version): array
    {
        if (! in_array($kind, ['curriculum', 'teachers'], true)) {
            throw ValidationException::withMessages([
                'kind' => 'Изберете учебен план или преподавателска обезпеченост.',
            ]);
        }

        $tables = $this->parseTables($html);
        $rows = $kind === 'curriculum'
            ? $this->curriculumRows($tables, $version)
            : $this->teacherRows($tables);

        if ($rows === []) {
            throw ValidationException::withMessages([
                'file' => 'Във файла не са открити редове с дисциплини и данни за импорт.',
            ]);
        }

        return [
            'kind' => $kind,
            'version_id' => $version->id,
            'version_name' => $version->name,
            'specialty_name' => $version->specialty->name,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $preview
     * @param  array<string, mixed>  $resolutions
     * @return array{created: int, skipped: int}
     */
    public function confirm(array $preview, array $resolutions): array
    {
        return DB::transaction(function () use ($preview, $resolutions): array {
            $version = CurriculumVersion::query()->findOrFail($preview['version_id']);
            $created = 0;
            $skipped = 0;
            $createdSubjects = [];
            $createdTeachers = [];

            foreach ($preview['rows'] as $index => $row) {
                if ($preview['kind'] === 'curriculum') {
                    $choice = (string) ($resolutions['subjects'][$index] ?? 'skip');

                    if ($choice === 'skip' || ! $row['valid']) {
                        $skipped++;

                        continue;
                    }

                    $subject = $this->resolveSubject($choice, $row['subject_candidates'], $row, $createdSubjects);
                    $exists = CurriculumSubject::query()
                        ->where('curriculum_version_id', $version->id)
                        ->where('subject_id', $subject->id)
                        ->where('semester_no', $row['semester_no'])
                        ->where('course_year', $row['course_year'])
                        ->exists();

                    if ($exists) {
                        $skipped++;

                        continue;
                    }

                    CurriculumSubject::query()->create([
                        'curriculum_version_id' => $version->id,
                        'subject_id' => $subject->id,
                        'semester_no' => $row['semester_no'],
                        'course_year' => $row['course_year'],
                        'contact_hours' => $row['contact_hours'] ?? 0,
                        'self_study_hours' => $row['self_study_hours'] ?? 0,
                        'scheduled_hours' => $row['scheduled_hours'] ?? 0,
                        'cycle_weeks' => null,
                        'control_type' => $row['control_type'],
                        'source_data' => [
                            'columns' => $row['source'],
                            'warnings' => $row['warnings'],
                        ],
                    ]);
                    $created++;

                    continue;
                }

                $subjectChoice = (string) ($resolutions['subjects'][$index] ?? 'skip');
                $teacherChoice = (string) ($resolutions['teachers'][$index] ?? 'skip');

                if ($subjectChoice === 'skip' || $teacherChoice === 'skip' || ! $row['valid']) {
                    $skipped++;

                    continue;
                }

                $subject = $this->resolveSubject($subjectChoice, $row['subject_candidates'], $row, $createdSubjects);
                $teacher = $this->resolveTeacher($teacherChoice, $row['teacher_candidates'], $row, $createdTeachers);
                $assignment = TeacherAssignment::query()->firstOrCreate([
                    'teacher_id' => $teacher->id,
                    'subject_id' => $subject->id,
                    'curriculum_version_id' => $version->id,
                    'course_year' => $row['course_year'],
                    'semester' => $row['semester_no'] % 2 === 1 ? 'winter' : 'summer',
                    'coverage_type' => $row['coverage_type'] ?: null,
                ], [
                    'source_data' => [
                        'columns' => $row['source'],
                        'warnings' => $row['warnings'],
                    ],
                ]);

                if ($assignment->wasRecentlyCreated) {
                    $created++;
                } else {
                    $skipped++;
                }
            }

            return compact('created', 'skipped');
        });
    }

    /**
     * @param  array<int, array<int, array{text: string, header: bool, bold: bool}>>  $tables
     * @return array<int, array<string, mixed>>
     */
    private function curriculumRows(array $tables, CurriculumVersion $version): array
    {
        $table = $this->findCurriculumTable($tables);

        if ($table === null) {
            throw ValidationException::withMessages([
                'file' => 'Не е намерена таблица с дисциплини и семестри. Проверете, че сте избрали запазената страница на учебния план.',
            ]);
        }

        [$headers, $dataRows] = $this->tableHeadersAndRows($table);
        $indexes = $this->curriculumHeaderIndexes($headers);

        if ($indexes['name'] === null || $indexes['semester'] === null) {
            throw ValidationException::withMessages([
                'file' => 'Таблицата трябва да съдържа колони за наименование на дисциплина и семестър.',
            ]);
        }

        $subjects = Subject::query()->get(['id', 'code', 'name']);
        $result = [];

        foreach ($dataRows as $cells) {
            $source = $this->sourceColumns($headers, $cells);
            $name = $this->cell($cells, $indexes['name']);
            $code = $this->cell($cells, $indexes['code']);

            if ($name === '' && $code === '') {
                continue;
            }

            $semester = $this->number($this->cell($cells, $indexes['semester']));
            $courseYear = $this->number($this->cell($cells, $indexes['course_year']));
            $semesterNo = $semester !== null && $semester >= 1 && $semester <= 12 ? $semester : null;
            $courseYear = $courseYear !== null && $courseYear >= 1 && $courseYear <= 6
                ? $courseYear
                : ($semesterNo === null ? null : (int) ceil($semesterNo / 2));
            $contactHours = $this->number($this->cell($cells, $indexes['contact_hours']));
            $selfStudyHours = $this->number($this->cell($cells, $indexes['self_study_hours']));
            $scheduledHours = $this->number($this->cell($cells, $indexes['scheduled_hours']));
            $sourceType = $this->sourceSubjectType($this->cell($cells, $indexes['source_type']));
            $controlType = $this->cell($cells, $indexes['control_type']) ?: null;
            $candidateSubjects = $this->findSubjectCandidates($subjects, $code, $name);
            $warnings = [];
            $isSemesterSummary = $code === '0'
                && preg_match(
                    '/^(?:всичко за )?(?:първи|втори|трети|четвърти|пети|шести|седми|осми) семестър всички$/u',
                    $this->normalize($name),
                ) === 1;

            if ($semesterNo === null) {
                $warnings[] = 'Не е разпознат валиден номер на семестър; редът не може да бъде записан.';
            }
            if ($isSemesterSummary) {
                $warnings[] = 'Редът е обобщение за семестър, а не дисциплина; не може да бъде записан.';
            }
            if ($name === '' || mb_strlen($name) > 255) {
                $warnings[] = 'Наименованието на дисциплината липсва или е по-дълго от допустимите 255 знака.';
            }
            if (mb_strlen($code) > 50) {
                $warnings[] = 'Кодът на дисциплината надвишава допустимите 50 знака.';
            }
            if (mb_strlen($controlType ?? '') > 255) {
                $warnings[] = 'Формата на контрол надвишава допустимите 255 знака.';
            }
            if ($contactHours === null) {
                $warnings[] = 'Контактните часове липсват в източника и ще останат 0.';
            }
            if ($selfStudyHours === null) {
                $warnings[] = 'Часовете за самоподготовка липсват в източника и ще останат 0.';
            }
            if ($scheduledHours === null) {
                $warnings[] = 'Седмичните часове за разписание липсват в източника и ще останат 0.';
            }
            $warnings[] = 'Седмиците от цикъла липсват в източника и трябва да бъдат зададени преди генериране на разписание.';
            if ($candidateSubjects === [] && $sourceType === null) {
                $warnings[] = 'Типът на дисциплината липсва в източника; при създаване ще се използва системният тип „лекция“.';
            }
            if ($candidateSubjects === []) {
                $warnings[] = 'Часовете по подразбиране за новата дисциплина липсват в източника и ще останат 0.';
            }

            $duplicate = count($candidateSubjects) === 1 && $semesterNo !== null && $courseYear !== null
                ? CurriculumSubject::query()
                    ->where('curriculum_version_id', $version->id)
                    ->where('subject_id', $candidateSubjects[0]['id'])
                    ->where('semester_no', $semesterNo)
                    ->where('course_year', $courseYear)
                    ->exists()
                : false;

            $result[] = [
                'source' => $source,
                'subject_name' => $name,
                'subject_code' => $code,
                'source_type' => $sourceType,
                'semester_no' => $semesterNo,
                'course_year' => $courseYear,
                'contact_hours' => $contactHours,
                'self_study_hours' => $selfStudyHours,
                'scheduled_hours' => $scheduledHours,
                'control_type' => $controlType,
                'subject_candidates' => $candidateSubjects,
                'status' => $duplicate ? 'already_imported' : $this->candidateStatus($candidateSubjects),
                'valid' => $name !== ''
                    && mb_strlen($name) <= 255
                    && mb_strlen($code) <= 50
                    && mb_strlen($controlType ?? '') <= 255
                    && $semesterNo !== null
                    && $courseYear !== null
                    && ! $isSemesterSummary,
                'warnings' => $warnings,
            ];
        }

        return $result;
    }

    /**
     * @param  array<int, array<int, array{text: string, header: bool, bold: bool}>>  $tables
     * @return array<int, array<string, mixed>>
     */
    private function teacherRows(array $tables): array
    {
        $table = $this->findTeacherTable($tables);

        if ($table === null) {
            throw ValidationException::withMessages([
                'file' => 'Не е намерена таблица за преподавателска обезпеченост (семестър, дисциплина/преподавател, вид и катедра).',
            ]);
        }

        [$headers, $dataRows] = $this->tableHeadersAndRows($table);
        $semesterIndex = $this->headerIndex($headers, fn (string $header): bool => str_contains($header, 'сем'));
        $personIndex = $this->headerIndex($headers, fn (string $header): bool => str_contains($header, 'дисциплин') || str_contains($header, 'преподавател'));
        $coverageIndex = $this->headerIndex($headers, fn (string $header): bool => str_contains($header, 'статут') || str_contains($header, 'вид') || str_contains($header, 'заетост'));
        $departmentIndex = $this->headerIndex($headers, fn (string $header): bool => str_contains($header, 'катедр'));

        if ($personIndex === null || $coverageIndex === null) {
            throw ValidationException::withMessages([
                'file' => 'Таблицата не съдържа разпознаваеми колони за дисциплина/преподавател и вид обезпеченост.',
            ]);
        }

        $subjects = Subject::query()->get(['id', 'code', 'name']);
        $teachers = Teacher::query()->get(['id', 'first_name', 'last_name', 'title']);
        $currentSubject = '';
        $currentSemester = null;
        $result = [];

        foreach ($dataRows as $cells) {
            $name = $this->cell($cells, $personIndex);
            $coverage = $this->cell($cells, $coverageIndex);
            $rowSemester = $this->number($this->cell($cells, $semesterIndex));

            if ($rowSemester !== null && $rowSemester >= 1 && $rowSemester <= 12) {
                $currentSemester = $rowSemester;
            }

            if ($name === '') {
                continue;
            }

            if ($this->subjectHeading($cells, $personIndex, $coverage)) {
                $currentSubject = $name;

                continue;
            }

            if ($currentSubject === '') {
                continue;
            }

            $semesterNo = $currentSemester;
            $courseYear = $semesterNo === null ? null : (int) ceil($semesterNo / 2);
            $person = $this->splitTeacherName($name);
            $source = $this->sourceColumns($headers, $cells, true);
            $warnings = [];

            if ($semesterNo === null) {
                $warnings[] = 'Не е разпознат семестър за назначението; редът не може да бъде записан.';
            }
            if ($person === null) {
                $warnings[] = $this->normalize($name) === 'хоноруван'
                    ? 'Източникът отбелязва „хоноруван“, но не посочва име на преподавател; назначението не може да бъде импортирано.'
                    : 'Името на преподавателя не може да бъде разделено на име и фамилия.';
            }
            if ($person !== null && (mb_strlen($person['first_name']) > 100 || mb_strlen($person['last_name']) > 100 || mb_strlen($person['title'] ?? '') > 100)) {
                $warnings[] = 'Името или академичната длъжност надвишава допустимата дължина.';
            }
            if (mb_strlen($name) > 255) {
                $warnings[] = 'Изходното име надвишава допустимите 255 знака.';
            }
            if (mb_strlen($coverage) > 255) {
                $warnings[] = 'Видът на обезпечеността надвишава допустимите 255 знака.';
            }

            $candidateSubjects = $this->findSubjectCandidates($subjects, '', $currentSubject);
            $candidateTeachers = $person === null ? [] : $this->findTeacherCandidates($teachers, $name, $person);

            if ($candidateSubjects === []) {
                $warnings[] = 'Дисциплината не е намерена; при създаване типът и часовете ще останат системни стойности, защото липсват в този източник.';
            }
            if ($candidateTeachers === []) {
                $warnings[] = 'Преподавателят не е намерен; дневната и седмичната натовареност липсват в източника и ще се използват системните стойности 8/0.';
            }
            if ($coverage === '') {
                $warnings[] = 'Видът на обезпечеността липсва в източника.';
            }
            $subjectStatus = $this->candidateStatus($candidateSubjects);
            $teacherStatus = $this->candidateStatus($candidateTeachers);

            $result[] = [
                'source' => $source,
                'subject_name' => $currentSubject,
                'subject_code' => '',
                'source_type' => null,
                'subject_candidates' => $candidateSubjects,
                'teacher_name' => $name,
                'teacher_parts' => $person,
                'teacher_candidates' => $candidateTeachers,
                'semester_no' => $semesterNo,
                'course_year' => $courseYear,
                'coverage_type' => $coverage,
                'department' => $this->cell($cells, $departmentIndex),
                'status' => $subjectStatus === 'ambiguous' || $teacherStatus === 'ambiguous'
                    ? 'ambiguous'
                    : ($subjectStatus === 'matched' && $teacherStatus === 'matched' ? 'matched' : 'missing'),
                'subject_status' => $subjectStatus,
                'teacher_status' => $teacherStatus,
                'valid' => $semesterNo !== null
                    && $person !== null
                    && mb_strlen($person['first_name']) <= 100
                    && mb_strlen($person['last_name']) <= 100
                    && mb_strlen($person['title'] ?? '') <= 100
                    && mb_strlen($name) <= 255
                    && mb_strlen($coverage) <= 255,
                'warnings' => $warnings,
            ];
        }

        return $result;
    }

    /**
     * @param  array<int, array<int, array{text: string, header: bool, bold: bool}>>  $tables
     * @return array<int, array{text: string, header: bool, bold: bool}>|null
     */
    private function findCurriculumTable(array $tables): ?array
    {
        foreach ($tables as $table) {
            [$headers, $rows] = $this->tableHeadersAndRows($table);
            $indexes = $this->curriculumHeaderIndexes($headers);

            if (count($rows) >= 1 && count($headers) >= 6
                && $indexes['name'] !== null
                && $indexes['semester'] !== null) {
                return $table;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<int, array{text: string, header: bool, bold: bool}>>  $tables
     * @return array<int, array{text: string, header: bool, bold: bool}>|null
     */
    private function findTeacherTable(array $tables): ?array
    {
        foreach ($tables as $table) {
            [$headers] = $this->tableHeadersAndRows($table);
            $combined = $this->normalize(implode(' ', $headers));

            if (count($headers) >= 4
                && str_contains($combined, 'сем')
                && (str_contains($combined, 'дисциплин') || str_contains($combined, 'преподавател'))
                && (str_contains($combined, 'заетост') || str_contains($combined, 'статут') || str_contains($combined, 'вид'))) {
                return $table;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{text: string, header: bool, bold: bool}>  $row
     */
    private function subjectHeading(array $row, int $personIndex, string $coverage): bool
    {
        $normalized = $this->normalize($coverage);
        $hasStatus = preg_match('/задължител|избираем|факултатив|optional|elective/u', $normalized) === 1;
        $isBold = (bool) ($row[$personIndex]['bold'] ?? false);
        $hasTeacherKind = preg_match('/лекц|упражнен|практик|семинар/u', $normalized) === 1;

        return $hasStatus || ($isBold && ! $hasTeacherKind);
    }

    /**
     * @param  array<int, array<int, array{text: string, header: bool, bold: bool}>>  $table
     * @return array{0: array<int, string>, 1: array<int, array<int, array{text: string, header: bool, bold: bool}>>}
     */
    private function tableHeadersAndRows(array $table): array
    {
        $headerStart = null;

        foreach ($table as $index => $row) {
            if (array_filter($row, fn (array $cell): bool => $cell['header']) !== []) {
                $headerStart = $index;

                break;
            }
        }

        if ($headerStart === null) {
            return [[], []];
        }

        $lastHeader = $headerStart;
        for ($index = $headerStart + 1; $index < min(count($table), $headerStart + 4); $index++) {
            if (array_filter($table[$index], fn (array $cell): bool => $cell['header']) === []) {
                break;
            }

            $lastHeader = $index;
        }

        $headerRows = array_slice($table, $headerStart, $lastHeader - $headerStart + 1);
        $width = max(array_map('count', $headerRows));
        $headers = [];

        for ($column = 0; $column < $width; $column++) {
            $parts = [];

            foreach ($headerRows as $row) {
                $text = trim($row[$column]['text'] ?? '');
                if ($text !== '' && ($parts === [] || end($parts) !== $text)) {
                    $parts[] = $text;
                }
            }

            $headers[$column] = implode(' ', $parts);
        }

        $rows = array_slice($table, $lastHeader + 1);
        $rows = array_values(array_filter($rows, function (array $row): bool {
            return ! array_filter($row, fn (array $cell): bool => $cell['header'])
                && array_filter($row, fn (array $cell): bool => $cell['text'] !== '');
        }));

        return [$headers, $rows];
    }

    /**
     * @return array<int, array<int, array{text: string, header: bool, bold: bool}>>
     */
    private function parseTables(string $html): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">'.$html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw ValidationException::withMessages([
                'file' => 'HTML файлът не може да бъде прочетен.',
            ]);
        }

        $xpath = new DOMXPath($document);
        $tables = [];

        foreach ($xpath->query('//table') ?: [] as $table) {
            if (! $table instanceof DOMElement) {
                continue;
            }

            $rows = [];
            foreach ($xpath->query('.//tr', $table) ?: [] as $tr) {
                if (! $tr instanceof DOMElement) {
                    continue;
                }

                $nearestTable = $tr->parentNode;
                while ($nearestTable instanceof DOMElement && $nearestTable->tagName !== 'table') {
                    $nearestTable = $nearestTable->parentNode;
                }
                if ($nearestTable !== $table) {
                    continue;
                }

                $cells = [];
                foreach ($xpath->query('./th|./td', $tr) ?: [] as $cell) {
                    if (! $cell instanceof DOMElement) {
                        continue;
                    }

                    $text = preg_replace('/\s+/u', ' ', trim($cell->textContent)) ?? '';
                    $text = $this->repairMojibake($text);
                    $header = $cell->tagName === 'th';
                    $bold = $header
                        || str_contains(mb_strtolower($tr->getAttribute('style')), 'bold')
                        || ($xpath->query('.//b|.//strong', $cell)?->length ?? 0) > 0;
                    $colspan = min(20, max(1, (int) $cell->getAttribute('colspan')));
                    $rowspan = min(20, max(1, (int) $cell->getAttribute('rowspan')));
                    $cells[] = compact('text', 'header', 'bold', 'colspan', 'rowspan');
                }

                if ($cells !== []) {
                    if (count($cells) > 100) {
                        throw ValidationException::withMessages([
                            'file' => 'Таблицата съдържа повече от 100 колони и не може да бъде прегледана.',
                        ]);
                    }
                    $rows[] = $cells;
                    if (count($rows) > 5000) {
                        throw ValidationException::withMessages([
                            'file' => 'Таблицата съдържа повече от 5000 реда и не може да бъде прегледана.',
                        ]);
                    }
                }
            }

            if ($rows === []) {
                continue;
            }

            $grid = [];
            $occupied = [];
            foreach ($rows as $rowIndex => $cells) {
                $column = 0;

                foreach ($cells as $cell) {
                    while (isset($occupied[$rowIndex][$column])) {
                        $column++;
                    }

                    for ($rowOffset = 0; $rowOffset < $cell['rowspan']; $rowOffset++) {
                        for ($columnOffset = 0; $columnOffset < $cell['colspan']; $columnOffset++) {
                            $row = $rowIndex + $rowOffset;
                            $col = $column + $columnOffset;
                            $grid[$row][$col] = [
                                'text' => $cell['text'],
                                'header' => $cell['header'],
                                'bold' => $cell['bold'],
                            ];
                            $occupied[$row][$col] = true;
                        }
                    }

                    $column += $cell['colspan'];
                }
            }

            ksort($grid);
            foreach ($grid as &$row) {
                ksort($row);
                $row = array_values($row);
            }
            unset($row);
            $tables[] = array_values($grid);
        }

        return $tables;
    }

    /**
     * @param  array<int, string>  $headers
     * @return array<string, int|null>
     */
    private function curriculumHeaderIndexes(array $headers): array
    {
        $indexes = [
            'code' => null,
            'name' => null,
            'semester' => null,
            'course_year' => null,
            'contact_hours' => null,
            'self_study_hours' => null,
            'scheduled_hours' => null,
            'control_type' => null,
            'source_type' => null,
        ];

        foreach ($headers as $index => $header) {
            $normalized = $this->normalize($header);

            if ($indexes['code'] === null && (str_contains($normalized, 'код') || str_contains($normalized, 'шифр') || str_contains($normalized, 'code'))) {
                $indexes['code'] = $index;
            } elseif ($indexes['semester'] === null && str_contains($normalized, 'сем')
                && ! str_contains($normalized, 'контрол') && ! str_contains($normalized, 'контр')) {
                $indexes['semester'] = $index;
            } elseif ($indexes['course_year'] === null && preg_match('/^(?:курс(?:\s+обучения)?|год(?:\s+обучения)?)$/u', $normalized) === 1) {
                $indexes['course_year'] = $index;
            } elseif ($indexes['name'] === null && (str_contains($normalized, 'дисциплин') || str_contains($normalized, 'предмет') || str_contains($normalized, 'наименование'))) {
                $indexes['name'] = $index;
            } elseif ($indexes['control_type'] === null && str_contains($normalized, 'контрол') && (str_contains($normalized, 'форма') || str_contains($normalized, 'вид'))) {
                $indexes['control_type'] = $index;
            } elseif ($indexes['self_study_hours'] === null && (str_contains($normalized, 'самостоятел') || str_contains($normalized, 'извънаудитор'))) {
                $indexes['self_study_hours'] = $index;
            } elseif ($indexes['scheduled_hours'] === null && (str_contains($normalized, 'седмич') || str_contains($normalized, 'хорариум'))) {
                $indexes['scheduled_hours'] = $index;
            } elseif ($indexes['contact_hours'] === null && (
                in_array($normalized, ['аз', 'а з'], true)
                || str_contains($normalized, 'аудиторна заетост')
                || str_contains($normalized, 'контактни часове')
                || str_contains($normalized, 'общо аудиторни')
                || str_contains($normalized, 'аудиторни занятия')
            )) {
                $indexes['contact_hours'] = $index;
            } elseif ($indexes['source_type'] === null && (str_contains($normalized, 'тип дисциплин') || str_contains($normalized, 'вид дисциплин'))) {
                $indexes['source_type'] = $index;
            }
        }

        return $indexes;
    }

    /**
     * @param  callable(string): bool  $predicate
     * @param  array<int, string>  $headers
     */
    private function headerIndex(array $headers, callable $predicate): ?int
    {
        foreach ($headers as $index => $header) {
            if ($predicate($this->normalize($header))) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array{text: string, header: bool, bold: bool}>  $cells
     * @return array<string, string>
     */
    private function sourceColumns(array $headers, array $cells, bool $omitAge = false): array
    {
        $source = [];

        foreach ($headers as $index => $header) {
            if ($omitAge && ($index === 4 || preg_match('/възраст|age/u', $this->normalize($header)) === 1)) {
                continue;
            }

            $label = trim($header) !== '' ? trim($header) : 'Колона '.($index + 1);
            if (array_key_exists($label, $source)) {
                $label .= ' '.($index + 1);
            }
            $source[$label] = $this->cell($cells, $index);
        }

        return $source;
    }

    /**
     * @param  array<int, array{text: string, header: bool, bold: bool}>  $cells
     */
    private function cell(array $cells, ?int $index): string
    {
        return $index === null ? '' : trim($cells[$index]['text'] ?? '');
    }

    private function number(string $value): ?int
    {
        $value = str_replace(',', '.', trim($value));

        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return $number >= 0 && $number <= 10000 && floor($number) === $number ? (int) $number : null;
    }

    private function sourceSubjectType(string $value): ?string
    {
        $value = $this->normalize($value);

        return match (true) {
            str_contains($value, 'практик') => 'practice',
            str_contains($value, 'упражнен') => 'exercise',
            str_contains($value, 'ателие'), str_contains($value, 'студио') => 'atelier',
            str_contains($value, 'поточна лекц') => 'stream_lecture',
            str_contains($value, 'самостоятел') => 'selfstudy',
            str_contains($value, 'лекц') => 'lecture',
            default => null,
        };
    }

    /**
     * @param  Collection<int, Subject>  $subjects
     * @return array<int, array{id: int, name: string, code: ?string}>
     */
    private function findSubjectCandidates(Collection $subjects, string $code, string $name): array
    {
        $normalizedName = $this->normalize($name);
        $matches = $code !== ''
            ? $subjects->filter(fn (Subject $subject): bool => $subject->code !== null && mb_strtolower(trim($subject->code)) === mb_strtolower(trim($code)))
            : collect();

        if ($matches->isEmpty() && $normalizedName !== '') {
            $matches = $subjects->filter(fn (Subject $subject): bool => $this->normalize($subject->name) === $normalizedName);
        }

        return $matches->map(fn (Subject $subject): array => [
            'id' => $subject->id,
            'name' => $subject->name,
            'code' => $subject->code,
        ])->values()->all();
    }

    /**
     * @param  Collection<int, Teacher>  $teachers
     * @param  array{first_name: string, last_name: string, title: ?string}  $person
     * @return array<int, array{id: int, name: string}>
     */
    private function findTeacherCandidates(Collection $teachers, string $sourceName, array $person): array
    {
        $source = $this->normalize($sourceName);
        $firstLast = $this->normalize($person['first_name'].' '.$person['last_name']);
        $exact = $teachers->filter(fn (Teacher $teacher): bool => $this->normalize(trim($teacher->first_name.' '.$teacher->last_name)) === $source);
        $matches = $exact->isNotEmpty()
            ? $exact
            : $teachers->filter(fn (Teacher $teacher): bool => $this->normalize(trim($teacher->first_name.' '.$teacher->last_name)) === $firstLast);

        return $matches->map(fn (Teacher $teacher): array => [
            'id' => $teacher->id,
            'name' => trim(($teacher->title ? $teacher->title.' ' : '').$teacher->first_name.' '.$teacher->last_name),
        ])->values()->all();
    }

    /**
     * @return array{first_name: string, last_name: string, title: ?string}|null
     */
    private function splitTeacherName(string $name): ?array
    {
        $value = trim($name);
        $title = null;

        while (preg_match('/^(проф(?:есор)?\.?|доц(?:ент)?\.?|гл\.?\s*ас\.?|ас\.?|д-р|доктор)\s*/iu', $value, $matches) === 1) {
            $title = trim(($title ? $title.' ' : '').trim($matches[1]));
            $value = trim(substr($value, strlen($matches[0])));
        }

        $parts = preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) < 2) {
            return null;
        }

        return [
            'first_name' => array_shift($parts),
            'last_name' => array_pop($parts),
            'title' => $title,
        ];
    }

    /**
     * @param  array<int, array{id: int, name: string, code?: ?string}>  $candidates
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $createdSubjects
     */
    private function resolveSubject(string $choice, array $candidates, array $row, array &$createdSubjects): Subject
    {
        if (ctype_digit($choice)) {
            if (! in_array((int) $choice, array_column($candidates, 'id'), true)) {
                throw ValidationException::withMessages([
                    'subjects' => 'Избраното съвпадение не принадлежи към този преглед. Качете файла отново.',
                ]);
            }

            return Subject::query()->findOrFail((int) $choice);
        }

        if ($choice !== 'create' || $row['subject_name'] === '') {
            throw ValidationException::withMessages([
                'subjects' => 'Изберете валидно съвпадение или създаване за всеки ред.',
            ]);
        }

        $key = $this->normalize(($row['subject_code'] ?? '') ?: $row['subject_name']);

        if (isset($createdSubjects[$key])) {
            return Subject::query()->findOrFail($createdSubjects[$key]);
        }

        $subjectCode = $row['subject_code'] ?? '';
        $existing = $this->findSubjectCandidates(Subject::query()->get(['id', 'code', 'name']), $subjectCode, $row['subject_name']);

        if (count($existing) === 1) {
            return Subject::query()->findOrFail($existing[0]['id']);
        }
        if (count($existing) > 1) {
            throw ValidationException::withMessages([
                'subjects' => 'Съвпаденията за дисциплината са се променили. Направете нов преглед.',
            ]);
        }

        $subject = Subject::query()->create([
            'code' => $subjectCode ?: null,
            'name' => $row['subject_name'],
            'type' => $row['source_type'] ?: 'lecture',
            'default_hours' => 0,
        ]);
        $createdSubjects[$key] = $subject->id;

        return $subject;
    }

    /**
     * @param  array<int, array{id: int, name: string}>  $candidates
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $createdTeachers
     */
    private function resolveTeacher(string $choice, array $candidates, array $row, array &$createdTeachers): Teacher
    {
        if (ctype_digit($choice)) {
            if (! in_array((int) $choice, array_column($candidates, 'id'), true)) {
                throw ValidationException::withMessages([
                    'teachers' => 'Избраният преподавател не принадлежи към този преглед. Качете файла отново.',
                ]);
            }

            return Teacher::query()->findOrFail((int) $choice);
        }

        $person = $row['teacher_parts'];
        if ($choice !== 'create' || ! is_array($person)) {
            throw ValidationException::withMessages([
                'teachers' => 'Изберете валиден преподавател или създаване за всеки ред.',
            ]);
        }

        $key = $this->normalize($row['teacher_name']);
        if (isset($createdTeachers[$key])) {
            return Teacher::query()->findOrFail($createdTeachers[$key]);
        }

        $existing = $this->findTeacherCandidates(
            Teacher::query()->get(['id', 'first_name', 'last_name', 'title']),
            $row['teacher_name'],
            $person,
        );
        if (count($existing) === 1) {
            return Teacher::query()->findOrFail($existing[0]['id']);
        }
        if (count($existing) > 1) {
            throw ValidationException::withMessages([
                'teachers' => 'Съвпаденията за преподавателя са се променили. Направете нов преглед.',
            ]);
        }

        $teacher = Teacher::query()->create([
            ...$person,
            'max_daily_hours' => 8,
            'weekly_workload_hours' => 0,
            'active' => true,
        ]);
        $createdTeachers[$key] = $teacher->id;

        return $teacher;
    }

    /**
     * @param  array<int, array{id: int}>  $candidates
     */
    private function candidateStatus(array $candidates): string
    {
        return match (count($candidates)) {
            0 => 'missing',
            1 => 'matched',
            default => 'ambiguous',
        };
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($this->repairMojibake($value)));

        return preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
    }

    private function repairMojibake(string $value): string
    {
        if (preg_match('/(?:Р.{1,2}Р|С[ЃЉЊћџ])/u', $value) === 1) {
            return mb_convert_encoding($value, 'Windows-1251', 'UTF-8');
        }

        return $value;
    }
}
