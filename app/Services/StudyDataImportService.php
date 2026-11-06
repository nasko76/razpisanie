<?php

namespace App\Services;

use App\Models\CurriculumSubject;
use App\Models\CurriculumVersion;
use App\Models\Specialty;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudyDataImportService
{
    /**
     * @return array{kind: string, version_id: int|null, version_name: string|null, specialty_name: string|null, employment_category: string|null, academic_year: string|null, rows: array<int, array<string, mixed>>}
     */
    public function preview(
        string $kind,
        string $html,
        ?CurriculumVersion $version,
        ?string $employmentCategory = null,
        ?string $academicYear = null,
    ): array {
        if (! in_array($kind, ['curriculum', 'teachers'], true)) {
            throw ValidationException::withMessages([
                'kind' => 'Изберете учебен план или преподавателска обезпеченост.',
            ]);
        }
        if ($kind === 'curriculum' && $version === null) {
            throw ValidationException::withMessages([
                'curriculum_version_id' => 'Изберете версия на учебния план.',
            ]);
        }
        if ($kind === 'teachers' && (! in_array($employmentCategory, ['full_time', 'honorary'], true) || $academicYear === null)) {
            throw ValidationException::withMessages([
                'employment_category' => 'Изберете категория преподаватели и учебна година.',
            ]);
        }

        $tables = $this->parseTables($html);
        $rows = $kind === 'curriculum'
            ? $this->curriculumRows($tables, $version)
            : $this->planAzTeacherRows($html, $tables, $employmentCategory, $academicYear);

        if ($rows === []) {
            throw ValidationException::withMessages([
                'file' => 'Във файла не са открити редове с дисциплини и данни за импорт.',
            ]);
        }

        return [
            'kind' => $kind,
            'version_id' => $version?->id,
            'version_name' => $kind === 'curriculum'
                ? $version?->name
                : $academicYear.' · '.($employmentCategory === 'honorary' ? 'хонорувани' : 'щатни'),
            'specialty_name' => $version?->specialty->name,
            'employment_category' => $employmentCategory,
            'academic_year' => $academicYear,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $preview
     * @param  array<string, mixed>  $resolutions
     * @return array{created: int, updated: int, skipped: int}
     */
    public function confirm(array $preview, array $resolutions): array
    {
        return DB::transaction(function () use ($preview, $resolutions): array {
            $version = $preview['kind'] === 'curriculum'
                ? CurriculumVersion::query()->findOrFail($preview['version_id'])
                : null;
            $created = 0;
            $updated = 0;
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

                    $subject->update([
                        'auditorium_hours' => $row['auditorium_hours'] ?? $row['contact_hours'] ?? 0,
                        'lecture_hours' => $row['lecture_hours'] ?? 0,
                        'exercise_hours' => $row['exercise_hours'] ?? 0,
                        'self_study_hours' => $row['self_study_hours'] ?? 0,
                        'control_type' => $row['normalized_control_type'],
                    ]);

                    if ($exists) {
                        $skipped++;

                        continue;
                    }

                    CurriculumSubject::query()->create([
                        'curriculum_version_id' => $version->id,
                        'subject_id' => $subject->id,
                        'semester_no' => $row['semester_no'],
                        'course_year' => $row['course_year'],
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
                if ($preview['employment_category'] === 'honorary') {
                    $teacher->forceFill([
                        'title' => $this->honoraryTeacherTitle($teacher->title),
                    ])->save();
                }
                $specialty = $this->resolveSpecialty($row['specialty_name']);
                $group = $this->resolveStudentGroup($specialty, $row);
                $assignment = TeacherAssignment::query()->firstOrNew([
                    'teacher_id' => $teacher->id,
                    'subject_id' => $subject->id,
                    'curriculum_version_id' => $group->curriculum_version_id,
                    'student_group_id' => $group->id,
                    'course_year' => $row['course_year'],
                    'semester' => $row['semester_no'] % 2 === 1 ? 'winter' : 'summer',
                    'coverage_type' => $row['coverage_type'],
                    'academic_year' => $preview['academic_year'],
                    'employment_category' => $preview['employment_category'],
                ]);
                $wasExisting = $assignment->exists;
                $assignment->fill([
                    'planned_hours' => $row['planned_hours'],
                    'source_data' => [
                        'columns' => $row['source'],
                        'warnings' => $row['warnings'],
                        'teacher_total_assigned_hours' => $row['teacher_total_assigned_hours'],
                    ],
                ]);

                if (! $wasExisting) {
                    $assignment->save();
                    $created++;
                } elseif ($assignment->isDirty()) {
                    $assignment->save();
                    $updated++;
                } else {
                    $skipped++;
                }
            }

            return compact('created', 'updated', 'skipped');
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
            $auditoriumHours = $this->number($this->cell($cells, $indexes['auditorium_hours']));
            $lectureHours = $this->number($this->cell($cells, $indexes['lecture_hours']));
            $exerciseHours = $this->number($this->cell($cells, $indexes['exercise_hours']));
            $selfStudyHours = $this->number($this->cell($cells, $indexes['self_study_hours']));
            $scheduledHours = $this->number($this->cell($cells, $indexes['scheduled_hours']));
            $controlType = $this->cell($cells, $indexes['control_type']) ?: null;
            $normalizedControlType = $this->normalizeControlType($controlType);
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
            if ($lectureHours === null) {
                $warnings[] = 'Часовете за лекции (Л) липсват в източника; стойността остава неизвестна.';
            }
            if ($exerciseHours === null) {
                $warnings[] = 'Часовете за упражнения (У) липсват в източника; стойността остава неизвестна.';
            }
            if ($auditoriumHours === null) {
                $warnings[] = 'Аудиторната заетост (АЗ) липсва в източника; стойността остава неизвестна.';
            }
            if ($selfStudyHours === null) {
                $warnings[] = 'Часовете за самоподготовка липсват в източника и ще останат 0.';
            }
            if ($controlType !== null && $normalizedControlType === null) {
                $warnings[] = 'Формата на контрол не е разпозната; оригиналната стойност е запазена в импортираните данни.';
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
                'semester_no' => $semesterNo,
                'course_year' => $courseYear,
                'contact_hours' => $contactHours,
                'auditorium_hours' => $auditoriumHours,
                'lecture_hours' => $lectureHours,
                'exercise_hours' => $exerciseHours,
                'self_study_hours' => $selfStudyHours,
                'scheduled_hours' => $scheduledHours,
                'control_type' => $controlType,
                'normalized_control_type' => $normalizedControlType,
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
    private function planAzTeacherRows(
        string $html,
        array $tables,
        string $employmentCategory,
        string $academicYear,
    ): array {
        $contexts = $this->planAzTableContexts($html);
        $subjects = Subject::query()->get(['id', 'code', 'name']);
        $teachers = Teacher::query()->get(['id', 'first_name', 'last_name', 'title']);
        $result = [];
        $foundPlanAzTable = false;

        foreach ($tables as $tableIndex => $table) {
            [$headers, $dataRows] = $this->planAzTableHeadersAndRows($table);
            $indexes = $this->planAzHeaderIndexes($headers);

            if ($indexes['subject'] === null
                || $indexes['specialty'] === null
                || $indexes['course_year'] === null
                || $indexes['semester'] === null
                || $indexes['group'] === null
                || $indexes['coverage'] === null
                || $indexes['planned_hours'] === null) {
                continue;
            }

            $foundPlanAzTable = true;
            $context = $contexts[$tableIndex] ?? ['teacher_name' => '', 'total_assigned_hours' => null, 'header_text' => ''];
            $teacherName = $context['teacher_name'];
            $teacher = $this->splitTeacherName($teacherName);
            if ($teacher !== null && $employmentCategory === 'honorary') {
                $teacher['title'] = $this->honoraryTeacherTitle($teacher['title']);
            }
            $candidateTeachers = $teacher === null
                ? []
                : $this->findTeacherCandidates($teachers, $teacherName, $teacher);

            foreach ($dataRows as $cells) {
                $subjectName = $this->cell($cells, $indexes['subject']);
                $specialtyName = $this->cleanSpecialtyName($this->cell($cells, $indexes['specialty']));

                if ($subjectName === '' || $specialtyName === '') {
                    continue;
                }

                $courseYear = $this->planAzNumber($this->cell($cells, $indexes['course_year']));
                $semesterNo = $this->planAzNumber($this->cell($cells, $indexes['semester']));
                $groupName = $this->cleanGroupName($this->cell($cells, $indexes['group']));
                $coverageLabel = $this->cell($cells, $indexes['coverage']);
                $coverageType = $this->normalizeCoverageType($coverageLabel);
                $plannedHours = $this->planAzNumber($this->cell($cells, $indexes['planned_hours']));
                $studentCount = $this->planAzNumber($this->cell($cells, $indexes['student_count']));
                $source = $this->sourceColumns($headers, $cells);
                $warnings = [];

                if ($teacher === null) {
                    $warnings[] = 'Не е разпознато името на преподавателя пред таблицата.';
                } elseif (mb_strlen($teacher['first_name']) > 100
                    || mb_strlen($teacher['last_name']) > 100
                    || mb_strlen($teacher['title'] ?? '') > 100) {
                    $warnings[] = 'Името или академичната длъжност надвишава допустимата дължина.';
                }
                if ($courseYear === null || $courseYear < 1 || $courseYear > 6) {
                    $warnings[] = 'Не е разпознат валиден курс (1–6).';
                }
                if ($semesterNo === null || $semesterNo < 1 || $semesterNo > 12) {
                    $warnings[] = 'Не е разпознат валиден семестър (1–12).';
                }
                if ($coverageType === null) {
                    $warnings[] = 'Колоната „Л/У“ трябва да указва лекции или упражнения.';
                }
                if ($plannedHours === null) {
                    $warnings[] = 'Не са разпознати планираните часове от колоната „Пл.ч.“.';
                }
                if ($studentCount === null && $indexes['student_count'] !== null) {
                    $warnings[] = 'Броят на студентите не е разпознат; при създаване на групата ще бъде 0.';
                }
                if ($candidateTeachers === []) {
                    $warnings[] = 'Преподавателят не е намерен и ще бъде създаден след потвърждение.';
                }

                $candidateSubjects = $this->findSubjectCandidates($subjects, '', $subjectName);
                if ($candidateSubjects === []) {
                    $warnings[] = 'Дисциплината не е намерена и ще бъде създадена след потвърждение.';
                }

                $subjectStatus = $this->candidateStatus($candidateSubjects);
                $teacherStatus = $this->candidateStatus($candidateTeachers);
                $result[] = [
                    'source' => $source,
                    'subject_name' => $subjectName,
                    'subject_code' => '',
                    'subject_candidates' => $candidateSubjects,
                    'teacher_name' => $teacherName,
                    'teacher_parts' => $teacher,
                    'teacher_candidates' => $candidateTeachers,
                    'specialty_name' => $specialtyName,
                    'course_year' => $courseYear !== null && $courseYear >= 1 && $courseYear <= 6 ? $courseYear : null,
                    'semester_no' => $semesterNo !== null && $semesterNo >= 1 && $semesterNo <= 12 ? $semesterNo : null,
                    'group_name' => $groupName,
                    'student_count' => $studentCount !== null && $studentCount <= 10000 ? $studentCount : 0,
                    'coverage_type' => $coverageType,
                    'coverage_label' => $coverageLabel,
                    'planned_hours' => $plannedHours,
                    'teacher_total_assigned_hours' => $context['total_assigned_hours'],
                    'employment_category' => $employmentCategory,
                    'academic_year' => $academicYear,
                    'subject_status' => $subjectStatus,
                    'teacher_status' => $teacherStatus,
                    'status' => $subjectStatus === 'ambiguous' || $teacherStatus === 'ambiguous'
                        ? 'ambiguous'
                        : ($subjectStatus === 'matched' && $teacherStatus === 'matched' ? 'matched' : 'missing'),
                    'valid' => $teacher !== null
                        && mb_strlen($teacher['first_name']) <= 100
                        && mb_strlen($teacher['last_name']) <= 100
                        && mb_strlen($teacher['title'] ?? '') <= 100
                        && $courseYear !== null && $courseYear >= 1 && $courseYear <= 6
                        && $semesterNo !== null && $semesterNo >= 1 && $semesterNo <= 12
                        && $coverageType !== null
                        && $plannedHours !== null
                        && mb_strlen($subjectName) <= 255
                        && mb_strlen($specialtyName) <= 255
                        && mb_strlen($teacherName) <= 255
                        && mb_strlen($groupName ?? '') <= 255,
                    'warnings' => $warnings,
                ];
            }
        }

        if (! $foundPlanAzTable) {
            throw ValidationException::withMessages([
                'file' => 'Не е намерена таблица PlanAZ с колони за дисциплина, специалност, курс, семестър, група, Л/У и планирани часове (Пл.ч.).',
            ]);
        }

        return $result;
    }

    /**
     * @param  array<int, array<int, array{text: string, header: bool, bold: bool}>>  $table
     * @return array{0: array<int, string>, 1: array<int, array<int, array{text: string, header: bool, bold: bool}>>}
     */
    private function planAzTableHeadersAndRows(array $table): array
    {
        [$headers, $rows] = $this->tableHeadersAndRows($table);
        $indexes = $this->planAzHeaderIndexes($headers);

        if ($indexes['subject'] !== null && $indexes['specialty'] !== null && $indexes['planned_hours'] !== null) {
            return [$headers, $rows];
        }

        foreach ($table as $headerIndex => $row) {
            $candidateHeaders = array_map(fn (array $cell): string => $cell['text'], $row);
            $indexes = $this->planAzHeaderIndexes($candidateHeaders);
            if ($indexes['subject'] === null || $indexes['specialty'] === null || $indexes['planned_hours'] === null) {
                continue;
            }

            return [$candidateHeaders, array_slice($table, $headerIndex + 1)];
        }

        return [$headers, $rows];
    }

    /**
     * @param  array<int, string>  $headers
     * @return array<string, int|null>
     */
    private function planAzHeaderIndexes(array $headers): array
    {
        $indexes = [
            'subject' => null,
            'specialty' => null,
            'course_year' => null,
            'semester' => null,
            'group' => null,
            'coverage' => null,
            'planned_hours' => null,
            'student_count' => null,
        ];

        foreach ($headers as $index => $header) {
            $normalized = trim($this->normalize($header));

            if ($indexes['subject'] === null && (str_contains($normalized, 'дисциплин') || str_contains($normalized, 'предмет') || str_contains($normalized, 'наименование'))) {
                $indexes['subject'] = $index;
            } elseif ($indexes['specialty'] === null && str_contains($normalized, 'специалност')) {
                $indexes['specialty'] = $index;
            } elseif ($indexes['course_year'] === null && preg_match('/^курс(?:\s|$)/u', $normalized) === 1) {
                $indexes['course_year'] = $index;
            } elseif ($indexes['semester'] === null && str_contains($normalized, 'сем')) {
                $indexes['semester'] = $index;
            } elseif ($indexes['group'] === null && (in_array($normalized, ['гр', 'група'], true) || str_contains($normalized, 'група'))) {
                $indexes['group'] = $index;
            } elseif ($indexes['coverage'] === null && (
                in_array($normalized, ['л у', 'л у занятие', 'л у вид'], true)
                || str_contains($normalized, 'вид занят')
                || str_contains($normalized, 'тип занят')
            )) {
                $indexes['coverage'] = $index;
            } elseif ($indexes['planned_hours'] === null && (
                in_array($normalized, ['пл ч', 'пл час', 'пл часове'], true)
                || (str_contains($normalized, 'пл') && str_contains($normalized, 'час'))
            )) {
                $indexes['planned_hours'] = $index;
            } elseif ($indexes['student_count'] === null && (
                str_contains($normalized, 'брой студ')
                || (str_starts_with($normalized, 'бр') && str_contains($normalized, 'студ'))
                || str_contains($normalized, 'студенти')
            )) {
                $indexes['student_count'] = $index;
            }
        }

        return $indexes;
    }

    /**
     * @return array<int, array{teacher_name: string, total_assigned_hours: int|null, header_text: string}>
     */
    private function planAzTableContexts(string $html): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw ValidationException::withMessages([
                'file' => 'HTML файлът не може да бъде прочетен.',
            ]);
        }

        $xpath = new DOMXPath($document);
        $contexts = [];

        foreach ($xpath->query('//table') ?: [] as $table) {
            if (! $table instanceof DOMElement) {
                continue;
            }

            $hasRows = false;
            foreach ($xpath->query('.//tr', $table) ?: [] as $row) {
                if (! $row instanceof DOMElement) {
                    continue;
                }

                $nearestTable = $row->parentNode;
                while ($nearestTable instanceof DOMElement && $nearestTable->tagName !== 'table') {
                    $nearestTable = $nearestTable->parentNode;
                }
                if ($nearestTable === $table && ($xpath->query('./th|./td', $row)?->length ?? 0) > 0) {
                    $hasRows = true;

                    break;
                }
            }

            if (! $hasRows) {
                continue;
            }

            $preceding = $xpath->query(
                'preceding::*[self::h1 or self::h2 or self::h3 or self::h4 or self::p or self::div or self::span or self::strong or self::b][normalize-space(.) != ""]',
                $table,
            );
            $contextTexts = [];
            $teacherName = '';

            $precedingParagraphs = $xpath->query('preceding::p[not(ancestor::table)]', $table);
            if ($precedingParagraphs !== false && $precedingParagraphs->length > 0) {
                $paragraph = $precedingParagraphs->item($precedingParagraphs->length - 1);
                if ($paragraph instanceof DOMElement) {
                    $teacherName = $this->teacherNameFromParagraph($paragraph, $xpath);
                    $paragraphText = preg_replace('/\s+/u', ' ', trim($paragraph->textContent)) ?? '';
                    if ($teacherName === '') {
                        $teacherName = $this->teacherNameFromContext($paragraphText);
                    }
                    $contextTexts[] = $this->repairMojibake($paragraphText);
                }
            }

            if ($preceding !== false) {
                for ($index = $preceding->length - 1; $index >= max(0, $preceding->length - 8); $index--) {
                    $node = $preceding->item($index);
                    if (! $node instanceof DOMNode) {
                        continue;
                    }

                    $ancestor = $node->parentNode;
                    $insideTable = false;
                    while ($ancestor instanceof DOMElement) {
                        if ($ancestor->tagName === 'table') {
                            $insideTable = true;

                            break;
                        }
                        $ancestor = $ancestor->parentNode;
                    }
                    if ($insideTable || ($xpath->query('.//table', $node)?->length ?? 0) > 0) {
                        continue;
                    }

                    $text = preg_replace('/\s+/u', ' ', trim($node->textContent)) ?? '';
                    $text = $this->repairMojibake($text);
                    if ($text === '') {
                        continue;
                    }

                    $contextTexts[] = $text;
                    if ($teacherName === '') {
                        $teacherName = $this->teacherNameFromContext($text);
                    }
                }
            }

            $headerText = implode(' ', $contextTexts);
            $totalAssignedHours = null;
            if (preg_match('/възложен(?:и)?(?:\s+и\s+редуциран\w*)?[^0-9]{0,40}([0-9]{1,4})/iu', $headerText, $matches) === 1) {
                $totalAssignedHours = (int) $matches[1];
            }

            $contexts[] = compact('teacherName', 'totalAssignedHours', 'headerText');
        }

        return array_map(fn (array $context): array => [
            'teacher_name' => $context['teacherName'],
            'total_assigned_hours' => $context['totalAssignedHours'],
            'header_text' => $context['headerText'],
        ], $contexts);
    }

    private function teacherNameFromParagraph(DOMElement $paragraph, DOMXPath $xpath): string
    {
        $nameElements = $xpath->query(
            './/*[contains(concat(" ", normalize-space(@class), " "), " h3 ")]',
            $paragraph,
        );
        if ($nameElements === false) {
            return '';
        }

        $parts = [];
        foreach ($nameElements as $element) {
            $part = preg_replace('/\s+/u', ' ', trim($element->textContent)) ?? '';
            if ($part !== '') {
                $parts[] = $this->repairMojibake($part);
            }
        }

        if (count($parts) < 3) {
            return '';
        }

        return implode(' ', $parts);
    }

    private function teacherNameFromContext(string $context): string
    {
        $context = preg_replace('/(?:задължителен\s+норматив|възложен(?:\s+и\s+редуциран)?|редуциран|общо|работно време|натовареност).*/iu', '', $context) ?? $context;
        $context = preg_replace('/^(?:преподавател|име)\s*[:\-]\s*/iu', '', trim($context)) ?? trim($context);
        $context = trim($context, " \t\n\r\0\x0B·-–—,:;.");
        $normalized = $this->normalize($context);

        if ($context === '' || preg_match('/щатни|хоноруван|преподавател|раздел|специалност|катедра/u', $normalized) === 1) {
            return '';
        }

        return $this->splitTeacherName($context) === null ? '' : $context;
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
            'auditorium_hours' => null,
            'lecture_hours' => null,
            'exercise_hours' => null,
            'self_study_hours' => null,
            'scheduled_hours' => null,
            'control_type' => null,
        ];

        foreach ($headers as $index => $header) {
            $normalized = $this->normalize($header);

            if (
                $indexes['lecture_hours'] === null
                && (in_array($normalized, ['л', 'л.'], true) || str_contains($normalized, 'лекци'))
            ) {
                $indexes['lecture_hours'] = $index;
            } elseif (
                $indexes['exercise_hours'] === null
                && (in_array($normalized, ['у', 'у.'], true) || str_contains($normalized, 'упражнен'))
            ) {
                $indexes['exercise_hours'] = $index;
            }

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
            }
        }

        $indexes['auditorium_hours'] = $indexes['contact_hours'];

        return $indexes;
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array{text: string, header: bool, bold: bool}>  $cells
     * @return array<string, string>
     */
    private function sourceColumns(array $headers, array $cells): array
    {
        $source = [];

        foreach ($headers as $index => $header) {
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

    private function planAzNumber(string $value): ?int
    {
        $number = $this->number($value);
        if ($number !== null) {
            return $number;
        }

        if (preg_match('/\d{1,4}/u', $value, $matches) === 1) {
            return (int) $matches[0];
        }

        $romanNumerals = [
            'I' => 1, 'І' => 1,
            'II' => 2, 'ІІ' => 2,
            'III' => 3, 'ІІІ' => 3,
            'IV' => 4, 'ІV' => 4,
            'V' => 5,
            'VI' => 6, 'VІ' => 6,
            'VII' => 7, 'VІІ' => 7,
            'VIII' => 8, 'VІІІ' => 8,
            'IX' => 9, 'ІX' => 9,
            'X' => 10, 'Х' => 10,
            'XI' => 11, 'ХІ' => 11,
            'XII' => 12, 'ХІІ' => 12,
        ];

        return $romanNumerals[mb_strtoupper(trim($value))] ?? null;
    }

    private function cleanSpecialtyName(string $name): string
    {
        $name = trim($this->repairMojibake(str_replace("\u{00A0}", ' ', $name)));

        return trim(preg_replace('/\s*-\s*ССО\s*$/iu', '', $name) ?? $name);
    }

    private function cleanGroupName(string $name): ?string
    {
        $name = trim($this->repairMojibake(str_replace("\u{00A0}", ' ', $name)));
        if ($name === '' || in_array($this->normalize($name), ['0', 'няма', 'обща', 'без група'], true)) {
            return null;
        }

        $name = preg_replace('/^(?:гр\.?|група)\s*/iu', '', $name) ?? $name;

        return trim($name, " \t\n\r\0\x0B-–—") ?: null;
    }

    private function resolveSpecialty(string $name): Specialty
    {
        $normalizedName = $this->normalize($name);
        $specialty = Specialty::query()->get()->first(
            fn (Specialty $specialty): bool => $this->normalize($this->cleanSpecialtyName($specialty->name)) === $normalizedName,
        );

        return $specialty ?? Specialty::query()->create(['name' => $name]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveStudentGroup(Specialty $specialty, array $row): StudentGroup
    {
        $curriculumVersion = $specialty->curriculumVersions()
            ->where('is_active', true)
            ->orderByDesc('version_year')
            ->orderByDesc('id')
            ->first();

        $groupQuery = StudentGroup::query()
            ->where('specialty_id', $specialty->id)
            ->where('course_year', $row['course_year']);
        if ($curriculumVersion === null) {
            $groupQuery->whereNull('curriculum_version_id');
        } else {
            $groupQuery->where('curriculum_version_id', $curriculumVersion->id);
        }
        if ($row['group_name'] === null) {
            $groupQuery->whereNull('group_name');
        } else {
            $groupQuery->where('group_name', $row['group_name']);
        }

        $group = $groupQuery->orderBy('id')->first();

        if ($group === null) {
            return StudentGroup::query()->create([
                'specialty_id' => $specialty->id,
                'curriculum_version_id' => $curriculumVersion?->id,
                'course_year' => $row['course_year'],
                'group_name' => $row['group_name'],
                'student_count' => $row['student_count'],
            ]);
        }

        if ($group->student_count === 0 && $row['student_count'] > 0) {
            $group->update(['student_count' => $row['student_count']]);
        }

        return $group;
    }

    private function normalizeControlType(?string $value): ?string
    {
        $normalized = $this->normalize($value ?? '');

        return match (true) {
            str_contains($normalized, 'изпит') => 'И',
            str_contains($normalized, 'текущ') => 'ТО',
            str_contains($normalized, 'продължаващ') => 'П',
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

        while (preg_match('/^(хон\.?\s*пр\.?|проф(?:есор)?\.?|доц(?:ент)?\.?|гл\.?\s*ас\.?|ас\.?|д-р|доктор)\s*/iu', $value, $matches) === 1) {
            $title = trim(($title ? $title.' ' : '').trim($matches[1]));
            $value = trim(substr($value, strlen($matches[0])));
        }

        $parts = preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) < 2) {
            return null;
        }

        return [
            'first_name' => implode(' ', array_slice($parts, 0, -1)),
            'last_name' => array_pop($parts),
            'title' => $title,
        ];
    }

    private function honoraryTeacherTitle(?string $title): string
    {
        $title = trim((string) $title);
        if (preg_match('/^хон\.?\s*пр\.?(?:\s|$)/iu', $title) === 1) {
            return $title;
        }

        return trim('хон. пр. '.$title);
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

    private function normalizeCoverageType(string $value): ?string
    {
        return match (true) {
            in_array($this->normalize($value), ['л', 'лекции', 'лекция', 'lecture'], true) => 'lecture',
            in_array($this->normalize($value), ['у', 'упр', 'упражнения', 'упражнение', 'exercise'], true) => 'exercise',
            default => null,
        };
    }

    private function repairMojibake(string $value): string
    {
        if (preg_match('/(?:Р.{1,2}Р|С[ЃЉЊћџ])/u', $value) === 1) {
            return mb_convert_encoding($value, 'Windows-1251', 'UTF-8');
        }

        return $value;
    }
}
