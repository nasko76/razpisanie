<?php

namespace App\Services;

use App\Models\CurriculumSubject;
use App\Models\Room;
use App\Models\StudentFlow;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAbsence;
use App\Models\TeacherAssignment;
use App\Models\TeacherDayPreference;
use App\Models\TimetableEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleService
{
    private const COURSE_LENGTH_WEEKS = 15;

    private const CYCLE_LENGTH_WEEKS = 3;

    /**
     * @var array<int, string>
     */
    public const WEEKDAYS = [
        1 => 'Понеделник',
        2 => 'Вторник',
        3 => 'Сряда',
        4 => 'Четвъртък',
        5 => 'Петък',
        6 => 'Събота',
        7 => 'Неделя',
    ];

    public function teachingHours(string $startTime, string $endTime): int
    {
        [$startHour, $startMinute] = array_map('intval', explode(':', substr($startTime, 0, 5)));
        [$endHour, $endMinute] = array_map('intval', explode(':', substr($endTime, 0, 5)));
        $elapsedMinutes = ($endHour * 60 + $endMinute) - ($startHour * 60 + $startMinute);
        $hours = max(1, (int) ceil(($elapsedMinutes - 15) / 60));

        if ($hours > 16) {
            throw ValidationException::withMessages([
                'duration_hours' => 'Продължителността не може да надвишава 16 учебни часа.',
            ]);
        }

        return $hours;
    }

    /**
     * @return array{
     *     entries: array<int, TimetableEntry>,
     *     unscheduled: array<int, array{subject: string, reason: string, suggestion: string}>,
     *     warnings: array<int, array{subject: string, reason: string, suggestion: string}>,
     *     analysis: array<int, array{subject: string, auditorium_hours: int, scheduled_hours: int, self_study_hours: int, cycle_week: int|null, period: string|null, weekdays: array<int, int>}>
     * }
     */
    public function generateForGroup(StudentGroup $group, string $academicYear, string $semesterType): array
    {
        $pairedGroups = $this->pairedGroupsFor($group);

        if ($pairedGroups->count() !== 2) {
            return $this->generateForGroupIndividually($group, $academicYear, $semesterType);
        }

        return DB::transaction(function () use ($pairedGroups, $academicYear, $semesterType): array {
            $firstGroup = $pairedGroups->first();
            $secondGroup = $pairedGroups->last();
            $firstResult = $this->generateForGroupIndividually($firstGroup, $academicYear, $semesterType, false, null, true);
            $secondResult = $this->generateForGroupIndividually($secondGroup, $academicYear, $semesterType, false, $firstGroup->id, true);

            foreach ($pairedGroups as $pairedGroup) {
                $this->recordRevision($pairedGroup, $academicYear, $semesterType, 'Автоматично генериране');
                $this->saveScheduleInputSnapshot($pairedGroup, $academicYear, $semesterType);
            }

            return [
                'entries' => [...$firstResult['entries'], ...$secondResult['entries']],
                'unscheduled' => [...$firstResult['unscheduled'], ...$secondResult['unscheduled']],
                'warnings' => [...$firstResult['warnings'], ...$secondResult['warnings']],
                'analysis' => [...$firstResult['analysis'], ...$secondResult['analysis']],
            ];
        });
    }

    /**
     * Reconcile curriculum-derived lesson blocks while leaving every retained block in its current slot.
     *
     * @return array{added:int, pooled:int, removed:int, updated:int, messages:array<int, string>, warnings:array<int, string>}
     */
    public function refreshForGroup(StudentGroup $group, string $academicYear, string $semesterType): array
    {
        $result = ['added' => 0, 'pooled' => 0, 'removed' => 0, 'updated' => 0, 'messages' => [], 'warnings' => []];

        DB::transaction(function () use ($group, $academicYear, $semesterType, &$result): void {
            $poolPeriodIndex = 0;
            $semesterParity = $semesterType === 'winter' ? 1 : 0;
            $curriculumSubjects = CurriculumSubject::query()
                ->with('subject')
                ->where('curriculum_version_id', $group->curriculum_version_id)
                ->where('course_year', $group->course_year)
                ->whereRaw('semester_no % 2 = ?', [$semesterParity])
                ->where('is_selected', true)
                ->get();
            $selectedSubjectIds = $curriculumSubjects->pluck('subject_id')->unique();

            $result['pooled'] += TimetableEntry::query()
                ->where('group_id', $group->id)
                ->where('academic_year', $academicYear)
                ->where('semester_type', $semesterType)
                ->whereNotNull('subject_id')
                ->whereNull('student_flow_id')
                ->whereNotIn('subject_id', $selectedSubjectIds->isEmpty() ? [0] : $selectedSubjectIds)
                ->where('locked', false)
                ->where('is_in_pool', false)
                ->update([
                    'is_in_pool' => true,
                    'pool_reason' => 'auto',
                    'updated_at' => now(),
                ]);
            $result['removed'] += TimetableEntry::query()
                ->where('group_id', $group->id)
                ->where('academic_year', $academicYear)
                ->where('semester_type', $semesterType)
                ->whereNotNull('subject_id')
                ->whereNull('student_flow_id')
                ->whereNotIn('subject_id', $selectedSubjectIds->isEmpty() ? [0] : $selectedSubjectIds)
                ->where('is_in_pool', true)
                ->where('pool_reason', 'auto')
                ->delete();

            $staleFlows = StudentFlow::query()
                ->with('subject')
                ->whereHas('groups', fn ($query) => $query->whereKey($group->id))
                ->whereNotIn('subject_id', $selectedSubjectIds->isEmpty() ? [0] : $selectedSubjectIds)
                ->get();
            foreach ($staleFlows as $flow) {
                if ($flow->subject === null) {
                    continue;
                }

                $flowResult = $this->reconcileFlowEntries(
                    $group,
                    $flow->subject,
                    ['coverage_type' => 'lecture', 'hours' => 0, 'teacher' => null, 'student_flow_id' => $flow->id],
                    $flow,
                    $academicYear,
                    $semesterType,
                );
                $result['pooled'] += $flowResult['pooled'];
                $result['removed'] += $flowResult['removed'];
            }

            foreach ($curriculumSubjects as $curriculumSubject) {
                $subject = $curriculumSubject->subject;
                $hourValues = $curriculumSubject->hourValues();
                $auditoriumHours = $hourValues['auditorium_hours'];
                $parts = $this->lessonParts($subject, $group, $semesterType, $academicYear, $hourValues);
                $plannedHours = array_sum(array_column($parts, 'hours'));

                if ($plannedHours < $auditoriumHours) {
                    $parts[] = [
                        'coverage_type' => null,
                        'hours' => $auditoriumHours - $plannedHours,
                        'teacher' => null,
                        'student_flow_id' => null,
                    ];
                }

                $selfStudyHours = $hourValues['self_study_hours'];
                if ($selfStudyHours > 0) {
                    if ($parts === []) {
                        $parts[] = [
                            'coverage_type' => null,
                            'hours' => $selfStudyHours,
                            'teacher' => null,
                            'student_flow_id' => null,
                        ];
                    } else {
                        $largestPart = array_search(max(array_column($parts, 'hours')), array_column($parts, 'hours'), true);
                        $parts[$largestPart]['hours'] += $selfStudyHours;
                    }
                }

                $subjectEntries = [];
                foreach ($parts as $part) {
                    if ($part['student_flow_id'] !== null) {
                        $flow = StudentFlow::query()->findOrFail($part['student_flow_id']);
                        $result['pooled'] += $this->poolIndividualFlowEntries(
                            $flow,
                            $subject,
                            $part['coverage_type'],
                            $academicYear,
                            $semesterType,
                        );
                        $flowResult = $this->reconcileFlowEntries(
                            $group,
                            $subject,
                            $part,
                            $flow,
                            $academicYear,
                            $semesterType,
                        );
                        array_push($subjectEntries, ...$flowResult['entries']);
                        $result['added'] += $flowResult['added'];
                        $result['pooled'] += $flowResult['pooled'];
                        $result['removed'] += $flowResult['removed'];
                        $result['updated'] += $flowResult['updated'];
                        if ($flowResult['updated'] > 0) {
                            foreach ($flowResult['entries'] as $entry) {
                                if ($entry->is_in_pool) {
                                    continue;
                                }
                                foreach ($this->conflicts($entry) as $conflict) {
                                    $result['warnings'][] = ($entry->subject?->name ?? $subject->name).': '.$conflict;
                                }
                            }
                        }

                        continue;
                    }

                    $entries = TimetableEntry::query()
                        ->where('group_id', $group->id)
                        ->where('subject_id', $subject->id)
                        ->where('academic_year', $academicYear)
                        ->where('semester_type', $semesterType)
                        ->whereNull('student_flow_id')
                        ->when(
                            $part['coverage_type'] === null,
                            fn ($query) => $query->whereNull('coverage_type'),
                            fn ($query) => $query->where('coverage_type', $part['coverage_type']),
                        )
                        ->orderByDesc('locked')
                        ->orderBy('is_in_pool')
                        ->orderBy('cycle_week')
                        ->orderBy('weekday_no')
                        ->orderBy('start_time')
                        ->orderBy('id')
                        ->get();
                    foreach ($entries as $entry) {
                        $assignedTeacherId = $part['coverage_type'] === null ? $entry->teacher_id : $part['teacher']?->id;
                        if ($entry->teacher_id !== $assignedTeacherId) {
                            $entry->teacher_id = $assignedTeacherId;
                            $entry->save();
                            $result['updated']++;
                            if (! $entry->is_in_pool) {
                                foreach ($this->conflicts($entry) as $conflict) {
                                    $result['warnings'][] = $subject->name.': '.$conflict;
                                }
                            }
                        }
                    }

                    $placedEntries = $entries->where('is_in_pool', false)->values();
                    $poolEntries = $entries->where('is_in_pool', true)->values();
                    $lockedPoolEntries = $poolEntries->where('locked', true)->values();
                    $unlockedPoolEntries = $poolEntries->where('locked', false)->values();
                    $targetCycleHours = $part['hours'] / self::COURSE_LENGTH_WEEKS * self::CYCLE_LENGTH_WEEKS;
                    $placedCycleHours = (float) $placedEntries->sum('duration_hours');
                    $lockedPoolHours = (float) $lockedPoolEntries->sum('duration_hours');
                    $neededPoolCount = (int) ceil(max(0, $targetCycleHours - $placedCycleHours - $lockedPoolHours) / 4);
                    $retainedPoolEntries = $lockedPoolEntries
                        ->concat($unlockedPoolEntries->take($neededPoolCount))
                        ->values();

                    foreach ($unlockedPoolEntries->slice($neededPoolCount) as $excessEntry) {
                        $excessEntry->delete();
                        $result['removed']++;
                    }

                    while ($retainedPoolEntries->count() < $neededPoolCount) {
                        $pendingEntry = $this->generatedEntry($group, $subject, $part, $academicYear, $semesterType);
                        $preferredPeriod = ['morning', 'afternoon', 'evening'][$poolPeriodIndex++ % 3];
                        $range = collect($this->defaultTimeRanges())
                            ->first(fn (array $candidateRange): bool => $this->timePeriod($candidateRange['start_time']) === $preferredPeriod);
                        $range ??= $this->defaultTimeRanges()[0];
                        $pendingEntry->fill([
                            'cycle_week' => 1,
                            'weekday_no' => 1,
                            'start_time' => $range['start_time'],
                            'end_time' => $range['end_time'],
                            'duration_hours' => 4,
                            'is_in_pool' => true,
                            'pool_reason' => 'auto',
                        ]);
                        $pendingEntry->save();
                        $retainedPoolEntries->push($pendingEntry);
                        $result['added']++;
                    }

                    array_push($subjectEntries, ...$placedEntries->all(), ...$retainedPoolEntries->all());
                }

                $this->assignWorkloadComponents($subjectEntries, $auditoriumHours);
            }

            $this->recordRevision($group, $academicYear, $semesterType, 'Презареждане на разписанието');
            $this->saveScheduleInputSnapshot($group, $academicYear, $semesterType);
        });

        return $result;
    }

    /**
     * @return array{copied:int, source_academic_year:string}
     */
    public function copyPreviousWinter(StudentGroup $group, string $targetAcademicYear): array
    {
        $sourceAcademicYear = $this->previousAcademicYear($targetAcademicYear);

        return DB::transaction(function () use ($group, $targetAcademicYear, $sourceAcademicYear): array {
            $sourceInputs = $this->scheduleInputFingerprints($group, $sourceAcademicYear, 'winter');
            $targetInputs = $this->scheduleInputFingerprints($group, $targetAcademicYear, 'winter');
            $sourceSnapshot = DB::table('schedule_input_snapshots')
                ->where('group_id', $group->id)
                ->where('academic_year', $sourceAcademicYear)
                ->where('semester_type', 'winter')
                ->first();

            if ($sourceSnapshot === null && ($sourceInputs['has_curriculum'] || $sourceInputs['has_assignments'])) {
                throw ValidationException::withMessages([
                    'schedule' => 'Старото разписание няма контролна снимка на плана и възлаганията. Презаредете го преди копиране.',
                ]);
            }

            $baselineCurriculum = $sourceSnapshot?->curriculum_fingerprint ?? $sourceInputs['curriculum'];
            $baselineAssignments = $sourceSnapshot?->assignment_fingerprint ?? $sourceInputs['assignments'];
            $changedInputs = [];

            if (
                ! hash_equals($baselineCurriculum, $sourceInputs['curriculum'])
                || ! hash_equals($baselineCurriculum, $targetInputs['curriculum'])
            ) {
                $changedInputs[] = 'учебният план';
            }

            if (
                ! hash_equals($baselineAssignments, $sourceInputs['assignments'])
                || ! hash_equals($baselineAssignments, $targetInputs['assignments'])
            ) {
                $changedInputs[] = 'преподавателските възлагания';
            }

            if ($changedInputs !== []) {
                throw ValidationException::withMessages([
                    'schedule' => 'Копирането е спряно, защото са променени '.implode(' и ', $changedInputs).'.',
                ]);
            }

            if (TimetableEntry::query()
                ->where('group_id', $group->id)
                ->where('academic_year', $targetAcademicYear)
                ->where('semester_type', 'winter')
                ->whereNull('student_flow_id')
                ->exists()) {
                throw ValidationException::withMessages([
                    'schedule' => 'В целевото разписание вече има занятия. Премахнете ги или изберете друга учебна година.',
                ]);
            }

            $sourceEntries = TimetableEntry::query()
                ->where(function ($query) use ($group): void {
                    $query->where('group_id', $group->id)
                        ->orWhereHas('studentFlow.groups', fn ($groupsQuery) => $groupsQuery->whereKey($group->id));
                })
                ->where('academic_year', $sourceAcademicYear)
                ->where('semester_type', 'winter')
                ->orderBy('cycle_week')
                ->orderBy('weekday_no')
                ->orderBy('start_time')
                ->get();

            if ($sourceEntries->isEmpty()) {
                throw ValidationException::withMessages([
                    'schedule' => 'В предишния зимен семестър няма занятия за копиране.',
                ]);
            }

            $copied = 0;
            foreach ($sourceEntries as $sourceEntry) {
                if ($sourceEntry->student_flow_id !== null && TimetableEntry::query()
                    ->where('student_flow_id', $sourceEntry->student_flow_id)
                    ->where('academic_year', $targetAcademicYear)
                    ->where('semester_type', 'winter')
                    ->exists()) {
                    continue;
                }

                $entry = $sourceEntry->replicate();
                $entry->academic_year = $targetAcademicYear;
                if ($entry->student_flow_id === null) {
                    $entry->group_id = $group->id;
                }

                if (! $entry->is_in_pool && ($conflicts = $this->conflicts($entry, true)) !== []) {
                    throw ValidationException::withMessages([
                        'schedule' => 'Копирането е спряно заради конфликт: '.implode(' ', $conflicts),
                    ]);
                }

                $entry->save();
                $copied++;
            }

            $this->recordRevision($group, $targetAcademicYear, 'winter', 'Копиране на предишно зимно разписание');
            $this->saveScheduleInputSnapshot($group, $targetAcademicYear, 'winter');

            return [
                'copied' => $copied,
                'source_academic_year' => $sourceAcademicYear,
            ];
        });
    }

    /**
     * @return array{
     *     entries: array<int, TimetableEntry>,
     *     unscheduled: array<int, array{subject: string, reason: string, suggestion: string}>,
     *     warnings: array<int, array{subject: string, reason: string, suggestion: string}>,
     *     analysis: array<int, array{subject: string, auditorium_hours: int, scheduled_hours: int, self_study_hours: int, cycle_week: int|null, period: string|null, weekdays: array<int, int>}>
     * }
     */
    private function generateForGroupIndividually(
        StudentGroup $group,
        string $academicYear,
        string $semesterType,
        bool $recordRevision = true,
        ?int $pairedGroupId = null,
        bool $pairedRotation = false,
    ): array {
        $semesterParity = $semesterType === 'winter' ? 1 : 0;
        $curriculumSubjects = CurriculumSubject::query()
            ->with('subject')
            ->where('curriculum_version_id', $group->curriculum_version_id)
            ->where('course_year', $group->course_year)
            ->whereRaw('semester_no % 2 = ?', [$semesterParity])
            ->where('is_selected', true)
            ->orderBy('semester_no')
            ->get();
        $curriculumSubjects = $curriculumSubjects
            ->sortBy(fn (CurriculumSubject $curriculumSubject): array => [
                $this->estimatedSessionCount($curriculumSubject->hourValues()) === 5 ? 0 : 1,
                -$this->estimatedSessionCount($curriculumSubject->hourValues()),
                $curriculumSubject->semester_no,
            ])
            ->values();
        $timeRanges = $this->defaultTimeRanges();
        $minimumSessionHours = min(array_column($timeRanges, 'duration_hours'));

        $entries = [];
        $unscheduled = [];
        $warnings = [];
        $analysis = [];
        $pairedPeriodIndex = 0;
        $poolPeriodIndex = 0;

        DB::transaction(function () use ($group, $academicYear, $semesterType, $curriculumSubjects, $timeRanges, $minimumSessionHours, $recordRevision, $pairedGroupId, $pairedRotation, &$pairedPeriodIndex, &$poolPeriodIndex, &$entries, &$unscheduled, &$warnings, &$analysis): void {
            TimetableEntry::query()
                ->where('group_id', $group->id)
                ->where('academic_year', $academicYear)
                ->where('semester_type', $semesterType)
                ->whereNull('student_flow_id')
                ->where('locked', false)
                ->where('is_in_pool', false)
                ->delete();
            TimetableEntry::query()
                ->where('group_id', $group->id)
                ->where('academic_year', $academicYear)
                ->where('semester_type', $semesterType)
                ->where('is_in_pool', true)
                ->where('pool_reason', 'auto')
                ->delete();

            foreach ($curriculumSubjects as $curriculumSubject) {
                $subject = $curriculumSubject->subject;
                $hourValues = $curriculumSubject->hourValues();
                $auditoriumHours = $hourValues['auditorium_hours'];
                $lessonParts = $this->lessonParts($subject, $group, $semesterType, $academicYear, $hourValues);
                $plannedHours = array_sum(array_column($lessonParts, 'hours'));

                if ($auditoriumHours < 1 && $plannedHours < 1 && $hourValues['self_study_hours'] < 1) {
                    $unscheduled[] = [
                        'subject' => $subject->name,
                        'reason' => 'Не са зададени аудиторни часове, лекции или упражнения.',
                        'suggestion' => 'Проверете часовете на дисциплината или добавете занятията ръчно.',
                    ];

                    continue;
                }

                if ($plannedHours < $auditoriumHours) {
                    $lessonParts[] = [
                        'coverage_type' => null,
                        'hours' => $auditoriumHours - $plannedHours,
                        'teacher' => null,
                        'student_flow_id' => null,
                    ];
                }

                $selfStudyHours = $hourValues['self_study_hours'];
                if ($selfStudyHours > 0) {
                    if ($lessonParts === []) {
                        $lessonParts[] = [
                            'coverage_type' => null,
                            'hours' => $selfStudyHours,
                            'teacher' => null,
                            'student_flow_id' => null,
                        ];
                    } else {
                        $hoursByPart = array_column($lessonParts, 'hours');
                        $largestPart = array_search(max($hoursByPart), $hoursByPart, true);
                        $lessonParts[$largestPart]['hours'] += $selfStudyHours;
                    }
                }

                $lockedEntries = TimetableEntry::query()
                    ->where('group_id', $group->id)
                    ->where('subject_id', $subject->id)
                    ->where('academic_year', $academicYear)
                    ->where('semester_type', $semesterType)
                    ->where('locked', true)
                    ->get();

                if ($lockedEntries->isNotEmpty()) {
                    $warnings[] = [
                        'subject' => $subject->name,
                        'reason' => 'Запазени са ръчно заключените занятия; дисциплината не е прегенерирана.',
                        'suggestion' => 'Отключете занятията, ако искате генераторът да ги разпредели наново.',
                    ];

                    continue;
                }

                if ($plannedHours !== $auditoriumHours) {
                    $warnings[] = [
                        'subject' => $subject->name,
                        'reason' => $plannedHours < $auditoriumHours
                            ? 'Лекциите и упражненията са '.$plannedHours.' ч., а АЗ е '.$auditoriumHours.' ч.; остатъкът ще бъде разпределен без преподавател и без предположение дали е лекция или упражнение.'
                            : 'Сборът на лекциите и упражненията е '.$plannedHours.' ч., а аудиторната заетост е '.$auditoriumHours.' ч.; графикът ще покрие по-голямата стойност.',
                        'suggestion' => 'Проверете АЗ, Л и У в дисциплината и коригирайте несъответствието, ако е необходимо.',
                    ];
                }

                foreach ($lessonParts as $lessonPart) {
                    if ($lessonPart['teacher'] === null) {
                        $warnings[] = [
                            'subject' => $subject->name,
                            'reason' => 'Няма активен преподавател за '.($lessonPart['coverage_type'] === 'lecture' ? 'лекции' : ($lessonPart['coverage_type'] === 'exercise' ? 'упражнения' : 'занятия')).'.',
                            'suggestion' => 'Назначете преподавател в досието; часовете са оставени без преподавател.',
                        ];
                    }
                }

                $days = [];
                $scheduledHours = 0;
                $subjectCycleWeek = null;
                $lastScheduledDay = null;
                $subjectTimeRange = null;
                $subjectScheduleEntries = [];
                foreach ($lessonParts as $partIndex => $part) {
                    if ($part['student_flow_id'] !== null) {
                        $flow = StudentFlow::query()->findOrFail($part['student_flow_id']);
                        $this->poolIndividualFlowEntries($flow, $subject, $part['coverage_type'], $academicYear, $semesterType);
                        $flowResult = $this->reconcileFlowEntries(
                            $group,
                            $subject,
                            $part,
                            $flow,
                            $academicYear,
                            $semesterType,
                        );
                        array_push($subjectScheduleEntries, ...$flowResult['entries']);
                        if ($flowResult['added'] > 0) {
                            $unscheduled[] = [
                                'subject' => $subject->name.' · '.$flow->name,
                                'reason' => 'Общият поток е добавен във временния пул за ръчно поставяне.',
                                'suggestion' => 'Поставете потока на свободно място; той ще се показва по едно и също време във всички участващи групи.',
                            ];
                        }

                        continue;
                    }

                    $existingPartEntries = TimetableEntry::query()
                        ->where('subject_id', $subject->id)
                        ->where('academic_year', $academicYear)
                        ->where('semester_type', $semesterType)
                        ->where('coverage_type', $part['coverage_type'])
                        ->when(
                            $part['student_flow_id'] === null,
                            fn ($query) => $query->where('group_id', $group->id)->whereNull('student_flow_id'),
                            fn ($query) => $query->where('student_flow_id', $part['student_flow_id']),
                        )
                        ->get();
                    $session = $existingPartEntries->count();
                    $scheduledPartHours = $existingPartEntries->sum('duration_hours') * self::COURSE_LENGTH_WEEKS / self::CYCLE_LENGTH_WEEKS;
                    $maximumSessions = $session + (int) ceil(
                        max(0, $part['hours'] - $scheduledPartHours) / ($minimumSessionHours * self::COURSE_LENGTH_WEEKS / self::CYCLE_LENGTH_WEEKS),
                    );

                    while ($scheduledPartHours < $part['hours'] && $session < $maximumSessions) {
                        $candidate = $this->generatedEntry($group, $subject, $part, $academicYear, $semesterType);
                        $pairedEntry = $pairedGroupId === null
                            ? null
                            : $this->pairedEntryFor($candidate, $pairedGroupId, $academicYear, $semesterType, $session);
                        $preferredPeriod = null;
                        if ($pairedRotation && $pairedGroupId === null) {
                            $preferredPeriod = $pairedPeriodIndex % 2 === 0 ? 'morning' : 'afternoon';
                            $pairedPeriodIndex++;
                        } elseif ($pairedEntry === null) {
                            $preferredPeriod = ['morning', 'afternoon', 'evening'][$poolPeriodIndex % 3];
                            $poolPeriodIndex++;
                        }
                        $placement = $this->chooseTimeRange(
                            $candidate,
                            $subjectCycleWeek,
                            $lastScheduledDay,
                            $pairedEntry,
                            $preferredPeriod,
                            $timeRanges,
                        );
                        $subjectCycleWeek ??= $placement['cycle_week'];
                        $candidate->fill($placement);

                        if ($subjectTimeRange === null) {
                            $subjectTimeRange = [
                                'start_time' => $candidate->start_time,
                                'end_time' => $candidate->end_time,
                                'duration_hours' => $candidate->duration_hours,
                            ];
                        }
                        $candidate->fill([
                            'cycle_week' => $placement['cycle_week'],
                            'weekday_no' => $placement['weekday_no'],
                            'room_id' => $this->availableRoom($subject, $group, $candidate, $placement),
                            'is_in_pool' => true,
                            'pool_reason' => 'auto',
                        ]);
                        if ($candidate->teacher_id === null) {
                            $warnings[] = [
                                'subject' => $subject->name,
                                'reason' => 'Няма активен преподавател за '.($part['coverage_type'] === 'lecture' ? 'лекции' : 'упражнения').'.',
                                'suggestion' => 'Назначете преподавател от досието му или директно от разписанието.',
                            ];
                        }
                        if ($candidate->room_id === null) {
                            $warnings[] = [
                                'subject' => $subject->name,
                                'reason' => 'Не е намерена свободна подходяща зала; занятието е създадено без зала.',
                                'suggestion' => 'Задайте зала от редакцията на занятието.',
                            ];
                        }
                        $candidate->save();
                        $entries[] = $candidate;
                        $subjectScheduleEntries[] = $candidate;
                        $days[] = $candidate->weekday_no;
                        $lastScheduledDay = $candidate->weekday_no;
                        $scheduledHours += $candidate->duration_hours * self::COURSE_LENGTH_WEEKS / self::CYCLE_LENGTH_WEEKS;
                        $scheduledPartHours += $candidate->duration_hours * self::COURSE_LENGTH_WEEKS / self::CYCLE_LENGTH_WEEKS;
                        $session++;
                    }

                }
                $this->assignWorkloadComponents($subjectScheduleEntries, max($auditoriumHours, $plannedHours));

                $effectiveSelfStudy = $selfStudyHours + max(
                    0,
                    (int) $scheduledHours - max($auditoriumHours, $plannedHours) - $selfStudyHours,
                );

                $analysis[] = [
                    'subject' => $subject->name,
                    'auditorium_hours' => $auditoriumHours,
                    'scheduled_hours' => (int) $scheduledHours,
                    'self_study_hours' => $effectiveSelfStudy,
                    'cycle_week' => $subjectCycleWeek,
                    'period' => match (true) {
                        $subjectTimeRange === null => null,
                        $subjectTimeRange['start_time'] < '12:00:00' => 'Сутрин',
                        $subjectTimeRange['start_time'] < '16:00:00' => 'Следобед',
                        default => 'Вечер',
                    },
                    'weekdays' => array_values(array_unique($days)),
                ];
            }

            if ($recordRevision) {
                $this->recordRevision($group, $academicYear, $semesterType, 'Автоматично генериране');
                $this->saveScheduleInputSnapshot($group, $academicYear, $semesterType);
            }
        });

        if ($curriculumSubjects->isEmpty()) {
            $warnings[] = [
                'subject' => 'Разписание',
                'reason' => 'За избраната група няма дисциплини от учебен план.',
                'suggestion' => 'Можете да добавите дисциплини ръчно от разписанието.',
            ];
        }

        return [
            'entries' => $entries,
            'unscheduled' => $unscheduled,
            'warnings' => $warnings,
            'analysis' => $analysis,
        ];
    }

    private function pairedGroupsFor(StudentGroup $group): Collection
    {
        if ($group->curriculum_version_id === null) {
            return StudentGroup::query()->whereKey([])->get();
        }

        $groups = StudentGroup::query()
            ->where('specialty_id', $group->specialty_id)
            ->where('curriculum_version_id', $group->curriculum_version_id)
            ->where('course_year', $group->course_year)
            ->orderBy('group_name')
            ->orderBy('id')
            ->get();

        return $groups->count() === 2 ? $groups : StudentGroup::query()->whereKey([])->get();
    }

    private function pairedEntryFor(
        TimetableEntry $candidate,
        int $pairedGroupId,
        string $academicYear,
        string $semesterType,
        int $session,
    ): ?TimetableEntry {
        return TimetableEntry::query()
            ->where('group_id', $pairedGroupId)
            ->where('subject_id', $candidate->subject_id)
            ->where('academic_year', $academicYear)
            ->where('semester_type', $semesterType)
            ->whereNull('student_flow_id')
            ->when(
                $candidate->coverage_type === null,
                fn ($query) => $query->whereNull('coverage_type'),
                fn ($query) => $query->where('coverage_type', $candidate->coverage_type),
            )
            ->where('locked', false)
            ->orderBy('cycle_week')
            ->orderBy('weekday_no')
            ->orderBy('start_time')
            ->orderBy('id')
            ->offset($session)
            ->first();
    }

    /**
     * @return array<int, array{subject: string, reason: string, suggestion: string}>
     */
    public function testSchedule(StudentGroup $group, string $academicYear, string $semesterType): array
    {
        $entries = TimetableEntry::query()
            ->with(['subject', 'teacher', 'room', 'studentFlow'])
            ->where(function ($query) use ($group): void {
                $query->where('group_id', $group->id)
                    ->orWhereHas('studentFlow.groups', fn ($groupsQuery) => $groupsQuery->whereKey($group->id));
            })
            ->where('academic_year', $academicYear)
            ->where('semester_type', $semesterType)
            ->get();
        $warnings = [];

        foreach ($entries as $entry) {
            $subjectName = $entry->subject?->name ?? $entry->custom_subject_name ?? 'Занятие';
            if ($entry->teacher_id === null) {
                $warnings[] = [
                    'subject' => $subjectName,
                    'reason' => 'Няма зададен преподавател.',
                    'suggestion' => 'Задайте преподавател от редакцията на занятието.',
                ];
            }
            if ($entry->room_id === null) {
                $warnings[] = [
                    'subject' => $subjectName,
                    'reason' => 'Няма зададена зала.',
                    'suggestion' => 'Задайте зала от редакцията на занятието.',
                ];
            }

            if ($entry->is_in_pool) {
                $warnings[] = [
                    'subject' => $subjectName,
                    'reason' => 'Занятието е във временния пул и не е разпределено в разписанието.',
                    'suggestion' => 'Преместете го от пула в подходящ свободен блок.',
                ];

                continue;
            }

            foreach ($this->conflicts($entry) as $conflict) {
                $warnings[] = [
                    'subject' => $subjectName,
                    'reason' => $conflict,
                    'suggestion' => 'Преместете занятието или коригирайте несъвместимите данни.',
                ];
            }
        }
        $entries = $entries->reject(fn (TimetableEntry $entry): bool => $entry->is_in_pool)->values();

        $semesterParity = $semesterType === 'winter' ? 1 : 0;
        $curriculumSubjects = CurriculumSubject::query()
            ->with('subject')
            ->where('curriculum_version_id', $group->curriculum_version_id)
            ->where('course_year', $group->course_year)
            ->whereRaw('semester_no % 2 = ?', [$semesterParity])
            ->where('is_selected', true)
            ->get();

        $processedSubjectIds = [];
        foreach ($curriculumSubjects as $curriculumSubject) {
            $subject = $curriculumSubject->subject;
            if (isset($processedSubjectIds[$subject->id])) {
                continue;
            }
            $processedSubjectIds[$subject->id] = true;

            $subjectEntries = $entries->where('subject_id', $subject->id);
            $hourValues = $curriculumSubject->hourValues();
            $auditoriumHours = $hourValues['auditorium_hours'];
            $plannedHours = $hourValues['lecture_hours'] + $hourValues['exercise_hours'];
            $requiredHours = max($auditoriumHours, $plannedHours) + $hourValues['self_study_hours'];
            $scheduledHours = $subjectEntries->sum('duration_hours') * self::COURSE_LENGTH_WEEKS / self::CYCLE_LENGTH_WEEKS;

            if ($requiredHours > 0 && $subjectEntries->isEmpty()) {
                $warnings[] = [
                    'subject' => $subject->name,
                    'reason' => 'Няма разпределени занятия за дисциплината.',
                    'suggestion' => 'Генерирайте разписанието или добавете занятията ръчно.',
                ];

                continue;
            }

            if ($auditoriumHours !== $plannedHours) {
                $warnings[] = [
                    'subject' => $subject->name,
                    'reason' => 'Сборът на лекциите и упражненията е '.$plannedHours.' ч., а АЗ е '.$auditoriumHours.' ч.',
                    'suggestion' => 'Проверете и коригирайте часовете в данните на дисциплината.',
                ];
            }

            if ($scheduledHours > $requiredHours) {
                $addedSelfStudyHours = (int) $scheduledHours - $requiredHours;
                $curriculumSubject->update([
                    'self_study_hours' => $hourValues['self_study_hours'] + $addedSelfStudyHours,
                ]);
                $warnings[] = [
                    'subject' => $subject->name,
                    'reason' => 'Към самоподготовката на дисциплината са добавени '.$addedSelfStudyHours.' ч.',
                    'suggestion' => 'Промяната е записана в учебния каталог.',
                ];
                $requiredHours = (int) $scheduledHours;
            }

            if ($scheduledHours < $requiredHours) {
                $warnings[] = [
                    'subject' => $subject->name,
                    'reason' => 'Разпределени са '.$scheduledHours.' от '.$requiredHours.' планирани аудиторни часа.',
                    'suggestion' => 'Добавете '.($requiredHours - $scheduledHours).' часа към разписанието.',
                ];
            }
        }

        foreach ($entries->groupBy(fn (TimetableEntry $entry): string => $entry->cycle_week.'-'.$entry->weekday_no) as $dayEntries) {
            $dailyHours = (int) $dayEntries->sum('duration_hours');
            if ($dailyHours > 10) {
                $firstEntry = $dayEntries->first();
                $warnings[] = [
                    'subject' => $group->group_name,
                    'reason' => 'Групата има '.$dailyHours.' учебни часа в един ден от седмица '.$firstEntry->cycle_week.'.',
                    'suggestion' => 'Преместете занятие така, че дневната натовареност на групата да не надвишава 10 часа.',
                ];
            }
        }

        $teacherIds = $entries->whereNotNull('teacher_id')->pluck('teacher_id')->unique();
        $teacherScheduleEntries = TimetableEntry::query()
            ->with('teacher')
            ->whereIn('teacher_id', $teacherIds)
            ->where('academic_year', $academicYear)
            ->where('semester_type', $semesterType)
            ->where('is_in_pool', false)
            ->get();
        foreach ($teacherScheduleEntries->groupBy('teacher_id') as $teacherEntries) {
            foreach ($teacherEntries->groupBy(fn (TimetableEntry $entry): string => $entry->cycle_week.'-'.$entry->weekday_no) as $dayEntries) {
                $dailyHours = (int) $dayEntries->sum('duration_hours');
                if ($dailyHours > 10) {
                    $teacher = $dayEntries->first()->teacher;
                    $warnings[] = [
                        'subject' => $teacher ? trim(($teacher->title ? $teacher->title.' ' : '').$teacher->first_name.' '.$teacher->last_name) : 'Преподавател',
                        'reason' => 'Преподавателят има '.$dailyHours.' учебни часа в един ден от седмица '.$dayEntries->first()->cycle_week.'.',
                        'suggestion' => 'Преместете занятие така, че дневната натовареност на преподавателя да не надвишава 10 часа.',
                    ];
                }
            }
        }

        return collect($warnings)
            ->unique(fn (array $warning): string => implode('|', $warning))
            ->values()
            ->all();
    }

    /**
     * @param  array{
     *     cycle_week:int,
     *     weekday_no:int,
     *     day_part?:string,
     *     start_time:string,
     *     end_time:string,
     *     duration_hours:int,
     *     teacher_id:int|null,
     *     room_id:int|null,
     *     locked:bool,
     *     custom_subject_name?:string|null,
     *     note?:string|null,
     *     is_in_pool?:bool
     * }  $data
     */
    public function moveEntry(TimetableEntry $entry, array $data): TimetableEntry
    {
        return DB::transaction(function () use ($entry, $data): TimetableEntry {
            $candidate = clone $entry;
            $wasInPool = (bool) $entry->is_in_pool;
            $previousPoolReason = $entry->pool_reason;
            $previousTeacherId = $entry->teacher_id;
            $previousCoverageType = $entry->coverage_type;
            $previousFlowId = $entry->student_flow_id;
            $changes = [
                'teacher_id' => $data['teacher_id'],
                'room_id' => $data['room_id'],
                'cycle_week' => $data['cycle_week'],
                'weekday_no' => $data['weekday_no'],
                'day_part' => $data['day_part'] ?? $entry->day_part ?? $this->timePeriod($entry->start_time),
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'duration_hours' => $data['duration_hours'],
                'locked' => $data['locked'],
            ];
            foreach (['coverage_type', 'student_flow_id', 'custom_subject_name', 'note', 'is_in_pool'] as $optionalField) {
                if (array_key_exists($optionalField, $data)) {
                    $changes[$optionalField] = $data[$optionalField];
                }
            }
            if (array_key_exists('self_study_hours', $data)) {
                $changes['self_study_hours'] = round((float) $data['self_study_hours'], 2);
            }

            $candidate->fill($changes);
            if (array_key_exists('is_in_pool', $changes) && ! (bool) $candidate->is_in_pool) {
                $candidate->pool_reason = null;
            }

            if (! $candidate->is_in_pool) {
                $this->assertNoConflicts($candidate);
            }

            $entry->fill($candidate->getAttributes());
            $entry->save();
            if ($previousFlowId !== $entry->student_flow_id && $entry->subject_id !== null) {
                if ($previousFlowId !== null) {
                    $previousFlow = StudentFlow::query()->findOrFail($previousFlowId);
                    $this->reconcileFlowEntries(
                        $entry->group,
                        $entry->subject,
                        [
                            'coverage_type' => $previousCoverageType,
                            'hours' => 0,
                            'teacher' => $entry->teacher,
                            'student_flow_id' => $previousFlowId,
                        ],
                        $previousFlow,
                        $entry->academic_year,
                        $entry->semester_type,
                    );
                }
                if ($entry->student_flow_id !== null) {
                    $flow = StudentFlow::query()->findOrFail($entry->student_flow_id);
                    $this->poolIndividualFlowEntries(
                        $flow,
                        $entry->subject,
                        $entry->coverage_type,
                        $entry->academic_year,
                        $entry->semester_type,
                    );
                    $this->reconcileFlowEntries(
                        $entry->group,
                        $entry->subject,
                        [
                            'coverage_type' => $entry->coverage_type,
                            'hours' => 0,
                            'teacher' => $entry->teacher,
                            'student_flow_id' => $flow->id,
                        ],
                        $flow,
                        $entry->academic_year,
                        $entry->semester_type,
                    );
                }
            }

            if (
                $previousTeacherId !== $entry->teacher_id
                && $previousCoverageType === $entry->coverage_type
                && $entry->subject_id !== null
                && in_array($entry->coverage_type, ['lecture', 'exercise'], true)
            ) {
                $relatedEntries = TimetableEntry::query()
                    ->where('subject_id', $entry->subject_id)
                    ->where('coverage_type', $entry->coverage_type)
                    ->where('academic_year', $entry->academic_year)
                    ->where('semester_type', $entry->semester_type)
                    ->when(
                        $entry->student_flow_id !== null,
                        fn (Builder $query) => $query->where('student_flow_id', $entry->student_flow_id),
                        fn (Builder $query) => $query->where('group_id', $entry->group_id)->whereNull('student_flow_id'),
                    )
                    ->whereKeyNot($entry->id)
                    ->get();

                foreach ($relatedEntries as $relatedEntry) {
                    $relatedCandidate = clone $relatedEntry;
                    $relatedCandidate->teacher_id = $entry->teacher_id;
                    if (! $relatedCandidate->is_in_pool) {
                        $this->assertNoConflicts($relatedCandidate);
                    }
                    $relatedEntry->teacher_id = $entry->teacher_id;
                    $relatedEntry->save();
                }
            }

            $this->syncTeacherAssignment($entry, $previousTeacherId, $previousCoverageType);
            $autoPlacement = ['placed' => 0, 'remaining' => 0];
            if ($wasInPool && ! $entry->is_in_pool && $previousPoolReason === 'auto' && $entry->subject_id !== null) {
                $autoPlacement = $this->placeRemainingAutomaticBlocks($entry);
            }
            $this->recordRevision(
                $entry->group,
                $entry->academic_year,
                $entry->semester_type,
                'Ръчна редакция',
            );

            $entry->refresh();
            $entry->setAttribute('auto_placed_count', $autoPlacement['placed']);
            $entry->setAttribute('auto_pending_count', $autoPlacement['remaining']);

            return $entry;
        });
    }

    public function parkEntry(TimetableEntry $entry, string $revisionReason = 'Временно преместено в пул'): TimetableEntry
    {
        return DB::transaction(function () use ($entry, $revisionReason): TimetableEntry {
            $entry->update([
                'is_in_pool' => true,
                'pool_reason' => 'manual',
                'locked' => false,
                'self_study_hours' => 0,
            ]);
            $this->recordRevision($entry->group, $entry->academic_year, $entry->semester_type, $revisionReason);

            return $entry->refresh();
        });
    }

    /**
     * Distribute auditorium hours across generated blocks and use remaining block capacity for self-study.
     *
     * @param  array<int, TimetableEntry>  $entries
     */
    private function assignWorkloadComponents(array $entries, int $auditoriumHours): void
    {
        if ($entries === []) {
            return;
        }

        $capacityPerCycle = array_sum(array_map(
            fn (TimetableEntry $entry): int => (int) $entry->duration_hours,
            $entries,
        ));
        if ($capacityPerCycle < 1) {
            return;
        }

        $auditoriumPerCycle = min($auditoriumHours / 5, $capacityPerCycle);
        $baseShare = floor($auditoriumPerCycle / count($entries));
        $remainingAuditorium = $auditoriumPerCycle - ($baseShare * count($entries));
        $wholeHourRemainder = abs($remainingAuditorium - round($remainingAuditorium)) < 0.0001;
        $equalShare = round($auditoriumPerCycle / count($entries), 2);

        foreach ($entries as $index => $entry) {
            $share = $wholeHourRemainder
                ? $baseShare + ($index < (int) round($remainingAuditorium) ? 1 : 0)
                : $equalShare;
            $share = min((float) $entry->duration_hours, $share);
            $entry->update([
                'auditorium_hours' => round($share, 2),
                'self_study_hours' => round((float) $entry->duration_hours - $share, 2),
            ]);
        }
    }

    /**
     * @return array{placed:int, remaining:int}
     */
    private function placeRemainingAutomaticBlocks(TimetableEntry $placedEntry): array
    {
        $pendingEntries = TimetableEntry::query()
            ->where('subject_id', $placedEntry->subject_id)
            ->where('academic_year', $placedEntry->academic_year)
            ->where('semester_type', $placedEntry->semester_type)
            ->where('is_in_pool', true)
            ->where('pool_reason', 'auto')
            ->when(
                $placedEntry->student_flow_id !== null,
                fn (Builder $query) => $query->where('student_flow_id', $placedEntry->student_flow_id),
                fn (Builder $query) => $query->where('group_id', $placedEntry->group_id)->whereNull('student_flow_id'),
            )
            ->orderBy('id')
            ->get();
        $totalPending = $pendingEntries->count();
        $lastSlot = (($placedEntry->cycle_week - 1) * count(self::WEEKDAYS)) + $placedEntry->weekday_no - 1;
        $placed = 0;

        foreach ($pendingEntries as $pendingEntry) {
            $durationHours = (int) $pendingEntry->duration_hours;
            $endTime = $durationHours === (int) $placedEntry->duration_hours
                ? $placedEntry->end_time
                : $this->endTimeForTeachingHours($placedEntry->start_time, $durationHours);

            if ($endTime === null) {
                continue;
            }

            for ($offset = 1; $offset <= self::CYCLE_LENGTH_WEEKS * count(self::WEEKDAYS); $offset++) {
                $slot = ($lastSlot + $offset) % (self::CYCLE_LENGTH_WEEKS * count(self::WEEKDAYS));
                $candidate = clone $pendingEntry;
                $candidate->fill([
                    'cycle_week' => intdiv($slot, count(self::WEEKDAYS)) + 1,
                    'weekday_no' => ($slot % count(self::WEEKDAYS)) + 1,
                    'day_part' => $placedEntry->day_part,
                    'start_time' => $placedEntry->start_time,
                    'end_time' => $endTime,
                    'duration_hours' => $durationHours,
                    'is_in_pool' => false,
                    'pool_reason' => null,
                ]);

                if ($this->conflicts($candidate) !== []) {
                    continue;
                }

                $pendingEntry->fill($candidate->only([
                    'cycle_week',
                    'weekday_no',
                    'day_part',
                    'start_time',
                    'end_time',
                    'duration_hours',
                    'is_in_pool',
                    'pool_reason',
                ]))->save();
                $lastSlot = $slot;
                $placed++;

                continue 2;
            }
        }

        return [
            'placed' => $placed,
            'remaining' => $totalPending - $placed,
        ];
    }

    private function endTimeForTeachingHours(string $startTime, int $durationHours): ?string
    {
        [$hour, $minute] = array_map('intval', explode(':', substr($startTime, 0, 5)));
        $endMinutes = ($hour * 60) + $minute + ($durationHours * 60) - 15;
        if ($endMinutes >= 24 * 60) {
            return null;
        }

        return sprintf('%02d:%02d:00', intdiv($endMinutes, 60), $endMinutes % 60);
    }

    /** @param array{group_id:int, subject_id:int|null, custom_subject_name:string|null, teacher_id:int|null, room_id:int|null, academic_year:string, semester_type:string, cycle_week:int, weekday_no:int, start_time:string, end_time:string, duration_hours:int, note:string|null} $data */
    public function addManualEntry(array $data): TimetableEntry
    {
        $entry = new TimetableEntry([
            ...$data,
            'day_part' => $data['day_part'] ?? $this->timePeriod($data['start_time']),
            'auditorium_hours' => $data['duration_hours'],
            'self_study_hours' => 0,
            'locked' => true,
        ]);

        $this->assertNoConflicts($entry);
        $entry->save();
        $this->syncTeacherAssignment($entry, null);
        $this->recordRevision(
            $entry->group,
            $entry->academic_year,
            $entry->semester_type,
            'Ръчно добавяне',
        );
        $group = $entry->group;
        if ($group !== null && $group->curriculum_version_id === null) {
            $this->saveScheduleInputSnapshot($group, $entry->academic_year, $entry->semester_type);
        }

        return $entry->refresh();
    }

    private function syncTeacherAssignment(
        TimetableEntry $entry,
        ?int $previousTeacherId,
        ?string $previousCoverageType = null,
    ): void {
        if ($entry->subject_id === null) {
            return;
        }

        $group = StudentGroup::query()->find($entry->group_id);
        if ($group === null) {
            return;
        }

        if (
            $previousTeacherId !== null
            && in_array($previousCoverageType, ['lecture', 'exercise'], true)
            && $previousCoverageType !== $entry->coverage_type
        ) {
            $this->teacherAssignmentsFor($entry, $group, $previousCoverageType)
                ->where('teacher_id', $previousTeacherId)
                ->delete();
        }

        if (! in_array($entry->coverage_type, ['lecture', 'exercise'], true)) {
            return;
        }

        $assignments = $this->teacherAssignmentsFor($entry, $group, $entry->coverage_type);
        if ($entry->teacher_id === null) {
            if ($previousTeacherId !== null) {
                $assignments->where('teacher_id', $previousTeacherId)->delete();
            }

            return;
        }

        $matchingAssignment = (clone $assignments)->where('teacher_id', $entry->teacher_id)->first();
        if ($matchingAssignment !== null) {
            (clone $assignments)
                ->where('teacher_id', '!=', $entry->teacher_id)
                ->delete();

            return;
        }

        $previousAssignment = $previousTeacherId === null
            ? null
            : (clone $assignments)->where('teacher_id', $previousTeacherId)->first();
        if ($previousAssignment !== null) {
            $previousAssignment->update(['teacher_id' => $entry->teacher_id]);
            (clone $assignments)
                ->where('teacher_id', '!=', $entry->teacher_id)
                ->delete();

            return;
        }

        (clone $assignments)->delete();
        TeacherAssignment::query()->create([
            'teacher_id' => $entry->teacher_id,
            'subject_id' => $entry->subject_id,
            'curriculum_version_id' => $entry->student_flow_id === null ? $group->curriculum_version_id : null,
            'course_year' => $group->course_year,
            'semester' => $entry->semester_type,
            'coverage_type' => $entry->coverage_type,
            'student_flow_id' => $entry->student_flow_id,
            'student_group_id' => $entry->student_flow_id === null ? $group->id : null,
            'academic_year' => $entry->academic_year,
        ]);
    }

    private function teacherAssignmentsFor(TimetableEntry $entry, StudentGroup $group, string $coverageType): Builder
    {
        $coverageAliases = $coverageType === 'lecture'
            ? ['lecture', 'Лекции', 'лекции', 'Лекция', 'лекция']
            : ['exercise', 'Упражнения', 'упражнения', 'Упражнение', 'упражнение'];

        return TeacherAssignment::query()
            ->where('subject_id', $entry->subject_id)
            ->whereIn('coverage_type', $coverageAliases)
            ->where('semester', $entry->semester_type)
            ->where('academic_year', $entry->academic_year)
            ->when(
                $entry->student_flow_id !== null,
                fn (Builder $query) => $query->where('student_flow_id', $entry->student_flow_id),
                fn (Builder $query) => $query
                    ->whereNull('student_flow_id')
                    ->where('student_group_id', $group->id),
            );
    }

    /**
     * @return array{curriculum:string, assignments:string, has_curriculum:bool, has_assignments:bool}
     */
    private function scheduleInputFingerprints(StudentGroup $group, string $academicYear, string $semesterType): array
    {
        $semesterParity = $semesterType === 'winter' ? 1 : 0;
        $curriculumSubjects = $group->curriculum_version_id === null
            ? collect()
            : CurriculumSubject::query()
                ->with('subject')
                ->where('curriculum_version_id', $group->curriculum_version_id)
                ->where('course_year', $group->course_year)
                ->whereRaw('semester_no % 2 = ?', [$semesterParity])
                ->where('is_selected', true)
                ->orderBy('semester_no')
                ->orderBy('subject_id')
                ->get();

        $curriculumData = $curriculumSubjects
            ->map(fn (CurriculumSubject $curriculumSubject): array => [
                'subject_id' => (int) $curriculumSubject->subject_id,
                'course_year' => (int) $curriculumSubject->course_year,
                'semester_no' => (int) $curriculumSubject->semester_no,
                'category' => $curriculumSubject->subject_category,
                'selected' => (bool) $curriculumSubject->is_selected,
                'code' => $curriculumSubject->subject?->code,
                'name' => $curriculumSubject->subject?->name,
                ...$curriculumSubject->hourValues(),
            ])
            ->all();
        $assignmentData = [];
        $hasAssignments = false;
        foreach ($curriculumSubjects as $curriculumSubject) {
            $parts = $curriculumSubject->subject === null
                ? []
                : $this->lessonParts(
                    $curriculumSubject->subject,
                    $group,
                    $semesterType,
                    $academicYear,
                    $curriculumSubject->hourValues(),
                );
            $partData = [];

            foreach ($parts as $part) {
                $assignment = $part['coverage_type'] === null
                    ? null
                    : $this->findAssignmentForSubject(
                        $curriculumSubject->subject,
                        $group,
                        $semesterType,
                        $part['coverage_type'],
                        $academicYear,
                    );
                $teacherId = $part['teacher']?->id;
                $hasAssignments = $hasAssignments || $assignment !== null || $part['student_flow_id'] !== null;
                $partData[] = [
                    'coverage_type' => $part['coverage_type'],
                    'hours' => (int) $part['hours'],
                    'teacher_id' => $teacherId === null ? null : (int) $teacherId,
                    'planned_hours' => $assignment?->planned_hours === null ? null : (int) $assignment->planned_hours,
                    'student_flow_id' => $part['student_flow_id'] === null ? null : (int) $part['student_flow_id'],
                ];
            }

            $assignmentData[] = [
                'subject_id' => (int) $curriculumSubject->subject_id,
                'parts' => $partData,
            ];
        }
        $encode = fn (array $data): string => hash(
            'sha256',
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
        );

        return [
            'curriculum' => $encode([
                'curriculum_version_id' => $group->curriculum_version_id,
                'course_year' => (int) $group->course_year,
                'subjects' => $curriculumData,
            ]),
            'assignments' => $encode($assignmentData),
            'has_curriculum' => $group->curriculum_version_id !== null,
            'has_assignments' => $hasAssignments,
        ];
    }

    private function saveScheduleInputSnapshot(StudentGroup $group, string $academicYear, string $semesterType): void
    {
        $fingerprints = $this->scheduleInputFingerprints($group, $academicYear, $semesterType);
        $now = now();

        DB::table('schedule_input_snapshots')->updateOrInsert(
            [
                'group_id' => $group->id,
                'academic_year' => $academicYear,
                'semester_type' => $semesterType,
            ],
            [
                'curriculum_fingerprint' => $fingerprints['curriculum'],
                'assignment_fingerprint' => $fingerprints['assignments'],
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
    }

    private function previousAcademicYear(string $academicYear): string
    {
        if (
            preg_match('/^(\d{4})\/(\d{4})$/D', $academicYear, $matches) !== 1
            || (int) $matches[1] + 1 !== (int) $matches[2]
        ) {
            throw ValidationException::withMessages([
                'schedule' => 'За копиране въведете учебна година във формат 2026/2027.',
            ]);
        }

        $startYear = (int) $matches[1] - 1;
        $endYear = (int) $matches[2] - 1;

        return str_pad((string) $startYear, 4, '0', STR_PAD_LEFT).'/'.str_pad((string) $endYear, 4, '0', STR_PAD_LEFT);
    }

    public function recordRevision(StudentGroup $group, string $academicYear, string $semesterType, string $reason): void
    {
        DB::transaction(function () use ($group, $academicYear, $semesterType, $reason): void {
            $revisions = DB::table('schedule_revisions')
                ->where('group_id', $group->id)
                ->where('academic_year', $academicYear)
                ->where('semester_type', $semesterType);

            $revisionNo = ((int) (clone $revisions)->lockForUpdate()->max('revision_no')) + 1;
            $snapshot = TimetableEntry::query()
                ->with(['subject', 'teacher', 'room'])
                ->where(function ($query) use ($group): void {
                    $query->where('group_id', $group->id)
                        ->orWhereHas('studentFlow.groups', fn ($groupsQuery) => $groupsQuery->whereKey($group->id));
                })
                ->where('academic_year', $academicYear)
                ->where('semester_type', $semesterType)
                ->where('is_in_pool', false)
                ->orderBy('cycle_week')
                ->orderBy('weekday_no')
                ->orderBy('start_time')
                ->get()
                ->map(fn (TimetableEntry $entry): array => [
                    'entry_id' => $entry->id,
                    'subject_id' => $entry->subject_id,
                    'subject' => $entry->subject?->name ?? $entry->custom_subject_name,
                    'custom_subject_name' => $entry->custom_subject_name,
                    'note' => $entry->note,
                    'coverage_type' => $entry->coverage_type,
                    'teacher_id' => $entry->teacher_id,
                    'teacher' => $entry->teacher
                        ? trim(($entry->teacher->title ? $entry->teacher->title.' ' : '').$entry->teacher->first_name.' '.$entry->teacher->last_name)
                        : null,
                    'room_id' => $entry->room_id,
                    'room' => $entry->room?->name ?? null,
                    'cycle_week' => $entry->cycle_week,
                    'weekday_no' => $entry->weekday_no,
                    'start_time' => substr($entry->start_time, 0, 5),
                    'end_time' => substr($entry->end_time, 0, 5),
                    'duration_hours' => $entry->duration_hours,
                    'locked' => $entry->locked,
                ])
                ->values()
                ->all();

            DB::table('schedule_revisions')->insert([
                'group_id' => $group->id,
                'academic_year' => $academicYear,
                'semester_type' => $semesterType,
                'revision_no' => $revisionNo,
                'reason' => $reason,
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * @param  array{coverage_type:string|null, hours:int, teacher:Teacher|null, student_flow_id:int|null}  $part
     */
    private function generatedEntry(
        StudentGroup $group,
        Subject $subject,
        array $part,
        string $academicYear,
        string $semesterType,
    ): TimetableEntry {
        return new TimetableEntry([
            'group_id' => $group->id,
            'subject_id' => $subject->id,
            'student_flow_id' => $part['student_flow_id'],
            'teacher_id' => $part['teacher']?->id,
            'academic_year' => $academicYear,
            'semester_type' => $semesterType,
            'start_time' => '08:00:00',
            'end_time' => '11:45:00',
            'duration_hours' => 4,
            'coverage_type' => $part['coverage_type'],
        ]);
    }

    private function estimatedSessionCount(array $hourValues): int
    {
        $parts = array_values(array_filter([
            $hourValues['lecture_hours'],
            $hourValues['exercise_hours'],
        ], fn (int $hours): bool => $hours > 0));
        $plannedHours = array_sum($parts);
        $baseHours = max($hourValues['auditorium_hours'], $plannedHours);

        if ($baseHours > $plannedHours) {
            $parts[] = $baseHours - $plannedHours;
        }

        $selfStudyHours = $hourValues['self_study_hours'];
        if ($selfStudyHours > 0) {
            if ($parts === []) {
                $parts[] = $selfStudyHours;
            } else {
                $largestPart = array_search(max($parts), $parts, true);
                $parts[$largestPart] += $selfStudyHours;
            }
        }

        return array_sum(array_map(
            fn (int $hours): int => (int) ceil($hours / 20),
            $parts,
        ));
    }

    /**
     * @return array<int, array{name:string, start_time:string, end_time:string, duration_hours:int, weekdays:array<int, int>, sort_order:int}>
     */
    private function defaultTimeRanges(): array
    {
        return [
            ['name' => 'Сутрин', 'start_time' => '08:00:00', 'end_time' => '11:45:00', 'duration_hours' => 4, 'weekdays' => [1, 2, 3, 4, 5], 'sort_order' => 1],
            ['name' => 'Следобед', 'start_time' => '13:00:00', 'end_time' => '16:45:00', 'duration_hours' => 4, 'weekdays' => [1, 2, 3, 4, 5], 'sort_order' => 2],
            ['name' => 'Вечер', 'start_time' => '17:00:00', 'end_time' => '20:45:00', 'duration_hours' => 4, 'weekdays' => [1, 2, 3, 4, 5], 'sort_order' => 3],
            ['name' => 'Събота сутрин', 'start_time' => '08:00:00', 'end_time' => '11:45:00', 'duration_hours' => 4, 'weekdays' => [6], 'sort_order' => 4],
            ['name' => 'Събота следобед', 'start_time' => '13:00:00', 'end_time' => '16:45:00', 'duration_hours' => 4, 'weekdays' => [6], 'sort_order' => 5],
            ['name' => 'Събота вечер', 'start_time' => '17:00:00', 'end_time' => '20:45:00', 'duration_hours' => 4, 'weekdays' => [6], 'sort_order' => 6],
        ];
    }

    /**
     * @param  array{coverage_type:string|null, hours:int, teacher:Teacher|null, student_flow_id:int|null}  $part
     * @return array{entries:array<int, TimetableEntry>, added:int, pooled:int, removed:int, updated:int}
     */
    private function reconcileFlowEntries(
        StudentGroup $group,
        Subject $subject,
        array $part,
        StudentFlow $flow,
        string $academicYear,
        string $semesterType,
    ): array {
        $entries = TimetableEntry::query()
            ->where('student_flow_id', $flow->id)
            ->where('subject_id', $subject->id)
            ->where('academic_year', $academicYear)
            ->where('semester_type', $semesterType)
            ->orderByDesc('locked')
            ->orderBy('is_in_pool')
            ->orderBy('cycle_week')
            ->orderBy('weekday_no')
            ->orderBy('start_time')
            ->orderBy('id')
            ->get();
        $requiredHours = $this->requiredFlowHours($flow, $subject, $part['coverage_type'], $semesterType, $academicYear);
        $pooled = 0;
        if ($requiredHours === 0) {
            foreach ($entries->where('is_in_pool', false)->where('locked', false) as $entry) {
                $entry->update([
                    'is_in_pool' => true,
                    'pool_reason' => 'auto',
                ]);
                $entry->is_in_pool = true;
                $entry->pool_reason = 'auto';
                $pooled++;
            }
        }
        $averageDuration = max(1, (int) round($entries->avg('duration_hours') ?: 4));
        $placedEntries = $entries->where('is_in_pool', false)->values();
        $poolEntries = $entries->where('is_in_pool', true)->values();
        $lockedPoolEntries = $poolEntries->where('locked', true)->values();
        $unlockedPoolEntries = $poolEntries->where('locked', false)->values();
        $targetCycleHours = $requiredHours / self::COURSE_LENGTH_WEEKS * self::CYCLE_LENGTH_WEEKS;
        $placedCycleHours = (float) $placedEntries->sum('duration_hours');
        $lockedPoolHours = (float) $lockedPoolEntries->sum('duration_hours');
        $neededPoolCount = (int) ceil(max(0, $targetCycleHours - $placedCycleHours - $lockedPoolHours) / $averageDuration);
        $retainedPoolEntries = $lockedPoolEntries
            ->concat($unlockedPoolEntries->take($neededPoolCount))
            ->values();
        $retainedEntries = $placedEntries
            ->concat($retainedPoolEntries)
            ->values();
        $removed = 0;
        $updated = 0;

        foreach ($unlockedPoolEntries->slice($neededPoolCount) as $excessEntry) {
            $excessEntry->delete();
            $removed++;
        }

        foreach ($retainedEntries as $entry) {
            $assignedTeacherId = $part['coverage_type'] === null ? $entry->teacher_id : $part['teacher']?->id;
            if ($entry->teacher_id !== $assignedTeacherId) {
                $entry->teacher_id = $assignedTeacherId;
                $entry->save();
                $updated++;
            }
        }

        $added = 0;
        while ($retainedPoolEntries->count() < $lockedPoolEntries->count() + $neededPoolCount) {
            $pendingEntry = $this->generatedEntry($group, $subject, $part, $academicYear, $semesterType);
            $periods = $this->defaultTimeRanges();
            $period = ['morning', 'afternoon', 'evening'][$retainedEntries->count() % 3];
            $range = collect($periods)
                ->first(fn (array $candidateRange): bool => $this->timePeriod($candidateRange['start_time']) === $period)
                ?? $periods[0];
            $pendingEntry->fill([
                'student_flow_id' => $flow->id,
                'cycle_week' => 1,
                'weekday_no' => 1,
                'start_time' => $range['start_time'],
                'end_time' => $range['end_time'],
                'duration_hours' => 4,
                'is_in_pool' => true,
                'pool_reason' => 'auto',
            ]);
            $pendingEntry->save();
            $retainedEntries->push($pendingEntry);
            $retainedPoolEntries->push($pendingEntry);
            $added++;
        }

        return [
            'entries' => $retainedEntries->all(),
            'added' => $added,
            'pooled' => $pooled,
            'removed' => $removed,
            'updated' => $updated,
        ];
    }

    private function requiredFlowHours(
        StudentFlow $flow,
        Subject $subject,
        ?string $coverageType,
        string $semesterType,
        string $academicYear,
    ): int {
        $requiredHours = 0;
        $semesterParity = $semesterType === 'winter' ? 1 : 0;
        $groups = $flow->groups()->with('curriculumVersion')->get();

        foreach ($groups as $flowGroup) {
            $curriculumSubject = CurriculumSubject::query()
                ->where('curriculum_version_id', $flowGroup->curriculum_version_id)
                ->where('course_year', $flowGroup->course_year)
                ->where('subject_id', $subject->id)
                ->whereRaw('semester_no % 2 = ?', [$semesterParity])
                ->where('is_selected', true)
                ->first();

            if ($curriculumSubject === null) {
                continue;
            }

            $hourValues = $curriculumSubject->hourValues();
            $parts = $this->lessonParts($subject, $flowGroup, $semesterType, $academicYear, $hourValues);
            $plannedHours = array_sum(array_column($parts, 'hours'));
            if ($plannedHours < $hourValues['auditorium_hours']) {
                $parts[] = [
                    'coverage_type' => null,
                    'hours' => $hourValues['auditorium_hours'] - $plannedHours,
                    'teacher' => null,
                    'student_flow_id' => null,
                ];
            }

            $selfStudyHours = $hourValues['self_study_hours'];
            if ($selfStudyHours > 0) {
                if ($parts === []) {
                    $parts[] = [
                        'coverage_type' => null,
                        'hours' => $selfStudyHours,
                        'teacher' => null,
                        'student_flow_id' => null,
                    ];
                } else {
                    $largestPart = array_search(max(array_column($parts, 'hours')), array_column($parts, 'hours'), true);
                    $parts[$largestPart]['hours'] += $selfStudyHours;
                }
            }

            foreach ($parts as $candidatePart) {
                if (
                    $candidatePart['coverage_type'] === $coverageType
                    && $candidatePart['student_flow_id'] === $flow->id
                ) {
                    $requiredHours = max($requiredHours, $candidatePart['hours']);
                }
            }
        }

        return $requiredHours;
    }

    private function poolIndividualFlowEntries(
        StudentFlow $flow,
        Subject $subject,
        ?string $coverageType,
        string $academicYear,
        string $semesterType,
    ): int {
        if ($coverageType === null) {
            return 0;
        }

        return TimetableEntry::query()
            ->whereIn('group_id', $flow->groups()->pluck('student_groups.id'))
            ->where('subject_id', $subject->id)
            ->where('coverage_type', $coverageType)
            ->whereNull('student_flow_id')
            ->where('academic_year', $academicYear)
            ->where('semester_type', $semesterType)
            ->where('is_in_pool', false)
            ->where('locked', false)
            ->update([
                'is_in_pool' => true,
                'pool_reason' => 'auto',
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<int, array{name:string, start_time:string, end_time:string, duration_hours:int, weekdays:array<int, int>, sort_order:int}>  $timeRanges
     * @return array{cycle_week:int, weekday_no:int, start_time:string, end_time:string, duration_hours:int}
     */
    private function chooseTimeRange(
        TimetableEntry $candidate,
        ?int $cycleWeek,
        ?int $adjacentToDay = null,
        ?TimetableEntry $pairedEntry = null,
        ?string $preferredPeriod = null,
        array $timeRanges = [],
    ): array {
        if ($timeRanges === []) {
            $timeRanges = $this->defaultTimeRanges();
        }

        if ($preferredPeriod !== null) {
            foreach ($timeRanges as $range) {
                if ($this->timePeriod($range['start_time']) !== $preferredPeriod) {
                    continue;
                }

                $this->applyTimeRange($candidate, $range);
                $placement = $this->choosePlacement(
                    $candidate,
                    $cycleWeek,
                    $adjacentToDay,
                    $range['weekdays'],
                    true,
                );
                $this->applyPlacement($candidate, $placement);

                if ($this->conflicts($candidate, true) === []) {
                    return $this->timeRangePlacement($range, $placement);
                }
            }
        }

        if ($pairedEntry !== null) {
            $pairedPeriod = $this->timePeriod($pairedEntry->start_time);
            $oppositePeriod = match ($pairedPeriod) {
                'morning' => 'afternoon',
                'afternoon' => 'morning',
                default => null,
            };

            if ($oppositePeriod !== null) {
                foreach ($timeRanges as $range) {
                    if ($this->timePeriod($range['start_time']) !== $oppositePeriod
                        || ! in_array($pairedEntry->weekday_no, $range['weekdays'], true)) {
                        continue;
                    }

                    $this->applyTimeRange($candidate, $range);
                    $placement = $this->choosePlacement(
                        $candidate,
                        $pairedEntry->cycle_week,
                        null,
                        [$pairedEntry->weekday_no],
                        true,
                    );
                    $this->applyPlacement($candidate, $placement);

                    if ($this->conflicts($candidate, true) === []) {
                        return $this->timeRangePlacement($range, $placement);
                    }
                }
            }
        }

        $plans = [];

        foreach ($timeRanges as $blockPriority => $range) {
            $this->applyTimeRange($candidate, $range);
            $placement = $this->choosePlacement(
                $candidate,
                $cycleWeek,
                $adjacentToDay,
                $range['weekdays'],
                true,
            );
            $this->applyPlacement($candidate, $placement);
            $plans[] = [
                'range' => $range,
                'placement' => $placement,
                'conflicts' => count($this->conflicts($candidate, true)),
                'block_priority' => $range['sort_order'] * 1000 + $blockPriority,
            ];
        }

        usort($plans, fn (array $first, array $second): int => [
            $first['conflicts'],
            $first['block_priority'],
            $first['placement']['preference'],
            $first['placement']['day_distance'],
            $first['placement']['entries'],
            $first['placement']['cycle_week'],
            $first['placement']['weekday_no'],
        ] <=> [
            $second['conflicts'],
            $second['block_priority'],
            $second['placement']['preference'],
            $second['placement']['day_distance'],
            $second['placement']['entries'],
            $second['placement']['cycle_week'],
            $second['placement']['weekday_no'],
        ]);
        $this->applyTimeRange($candidate, $plans[0]['range']);
        $this->applyPlacement($candidate, $plans[0]['placement']);

        return $this->timeRangePlacement($plans[0]['range'], $plans[0]['placement']);
    }

    /**
     * @param  array{name:string, start_time:string, end_time:string, duration_hours:int, weekdays:array<int, int>, sort_order:int}  $range
     */
    private function applyTimeRange(TimetableEntry $candidate, array $range): void
    {
        $candidate->fill([
            'start_time' => $range['start_time'],
            'end_time' => $range['end_time'],
            'duration_hours' => $range['duration_hours'],
        ]);
    }

    /**
     * @param  array<string, int>  $placement
     */
    private function applyPlacement(TimetableEntry $candidate, array $placement): void
    {
        $candidate->fill([
            'cycle_week' => $placement['cycle_week'],
            'weekday_no' => $placement['weekday_no'],
        ]);
    }

    /**
     * @param  array{name:string, start_time:string, end_time:string, duration_hours:int, weekdays:array<int, int>, sort_order:int}  $range
     * @param  array<string, int>  $placement
     * @return array{cycle_week:int, weekday_no:int, start_time:string, end_time:string, duration_hours:int}
     */
    private function timeRangePlacement(array $range, array $placement): array
    {
        return [
            'cycle_week' => $placement['cycle_week'],
            'weekday_no' => $placement['weekday_no'],
            'start_time' => $range['start_time'],
            'end_time' => $range['end_time'],
            'duration_hours' => $range['duration_hours'],
        ];
    }

    private function timePeriod(string $time): string
    {
        return match (true) {
            $time < '12:00:00' => 'morning',
            $time < '16:00:00' => 'afternoon',
            default => 'evening',
        };
    }

    /**
     * @return array{cycle_week:int, weekday_no:int}
     */
    private function choosePlacement(
        TimetableEntry $candidate,
        ?int $cycleWeek = null,
        ?int $adjacentToDay = null,
        ?array $weekdays = null,
        bool $allowUnavailableSaturday = false,
    ): array {
        $options = [];

        foreach ($cycleWeek === null ? [1, 2, 3] : [$cycleWeek] as $candidateWeek) {
            foreach ($weekdays ?? [1, 2, 3, 4, 5, 6] as $weekdayNo) {
                $candidate->cycle_week = $candidateWeek;
                $candidate->weekday_no = $weekdayNo;
                $conflictCount = count($this->conflicts($candidate, $allowUnavailableSaturday));
                $sameSubjectDay = TimetableEntry::query()
                    ->where('group_id', $candidate->group_id)
                    ->where('subject_id', $candidate->subject_id)
                    ->where('academic_year', $candidate->academic_year)
                    ->where('semester_type', $candidate->semester_type)
                    ->where('cycle_week', $candidateWeek)
                    ->where('weekday_no', $weekdayNo)
                    ->when(
                        $candidate->student_flow_id === null,
                        fn ($query) => $query->whereNull('student_flow_id'),
                        fn ($query) => $query->where('student_flow_id', $candidate->student_flow_id),
                    )
                    ->exists();
                $preference = $candidate->teacher_id
                    ? TeacherDayPreference::query()
                        ->where('teacher_id', $candidate->teacher_id)
                        ->where('cycle_week', $candidateWeek)
                        ->where('weekday_no', $weekdayNo)
                        ->value('preference')
                    : null;
                $options[] = [
                    'cycle_week' => $candidateWeek,
                    'weekday_no' => $weekdayNo,
                    'conflicts' => $conflictCount,
                    'same_subject_day' => $sameSubjectDay ? 1 : 0,
                    'day_priority' => $weekdayNo === 6 ? 1 : 0,
                    'preference' => $preference === 'preferred' ? 0 : ($preference === 'unavailable' ? 2 : 1),
                    'day_distance' => $adjacentToDay === null ? 0 : min(
                        abs($weekdayNo - $adjacentToDay),
                        abs($weekdayNo - ($adjacentToDay - 1)),
                        abs($weekdayNo - ($adjacentToDay + 1)),
                    ),
                    'entries' => TimetableEntry::query()
                        ->where('group_id', $candidate->group_id)
                        ->where('is_in_pool', false)
                        ->where('academic_year', $candidate->academic_year)
                        ->where('semester_type', $candidate->semester_type)
                        ->where('cycle_week', $candidateWeek)
                        ->count(),
                ];
            }
        }

        usort($options, fn (array $first, array $second): int => [
            $first['conflicts'],
            $first['same_subject_day'],
            $first['day_priority'],
            $first['day_distance'],
            $first['preference'],
            $first['entries'],
            $first['cycle_week'],
            $first['weekday_no'],
        ] <=> [
            $second['conflicts'],
            $second['same_subject_day'],
            $second['day_priority'],
            $second['day_distance'],
            $second['preference'],
            $second['entries'],
            $second['cycle_week'],
            $second['weekday_no'],
        ]);

        return $options[0];
    }

    /**
     * @param  array{cycle_week:int, weekday_no:int}  $placement
     */
    private function availableRoom(Subject $subject, StudentGroup $group, TimetableEntry $candidate, array $placement): ?int
    {
        $candidate->cycle_week = $placement['cycle_week'];
        $candidate->weekday_no = $placement['weekday_no'];

        foreach ($this->roomsForSubject($subject, $group) as $room) {
            $candidate->room_id = $room->id;

            if ($this->conflicts($candidate, true) === []) {
                return $room->id;
            }
        }

        return null;
    }

    private function findAssignmentForSubject(
        Subject $subject,
        StudentGroup $group,
        string $semesterType,
        ?string $coverageType,
        string $academicYear,
    ): ?TeacherAssignment {
        return TeacherAssignment::query()
            ->with('teacher')
            ->where('subject_id', $subject->id)
            ->where(function ($query) use ($group, $subject): void {
                $query->where(function ($standard) use ($group): void {
                    $standard->whereNull('student_flow_id')
                        ->where(function ($scope) use ($group): void {
                            $scope->where('student_group_id', $group->id)
                                ->orWhere(function ($curriculum) use ($group): void {
                                    $curriculum->whereNull('student_group_id')
                                        ->where('curriculum_version_id', $group->curriculum_version_id)
                                        ->where('course_year', $group->course_year);
                                });
                        });
                })->orWhere(function ($flow) use ($group, $subject): void {
                    $flow->whereNotNull('student_flow_id')
                        ->whereHas('studentFlow', fn ($studentFlow) => $studentFlow
                            ->where('subject_id', $subject->id)
                            ->whereHas('groups', fn ($groups) => $groups->whereKey($group->id)));
                });
            })
            ->where(function ($query) use ($semesterType): void {
                $query->whereNull('semester')->orWhere('semester', $semesterType);
            })
            ->where(function ($query) use ($academicYear): void {
                $query->whereNull('academic_year')->orWhere('academic_year', $academicYear);
            })
            ->when(
                $coverageType === null,
                fn ($query) => $query->whereNull('coverage_type'),
                fn ($query) => $query->whereIn('coverage_type', $coverageType === 'lecture'
                    ? ['lecture', 'Лекции', 'лекции', 'Лекция', 'лекция']
                    : ['exercise', 'Упражнения', 'упражнения', 'Упражнение', 'упражнение']),
            )
            ->when($coverageType !== 'lecture', fn ($query) => $query->whereNull('student_flow_id'))
            ->orderByRaw('CASE WHEN student_group_id = ? THEN 0 ELSE 1 END', [$group->id])
            ->orderByRaw('CASE WHEN academic_year = ? THEN 0 ELSE 1 END', [$academicYear])
            ->orderByRaw('CASE WHEN student_flow_id IS NULL THEN 1 ELSE 0 END')
            ->whereHas('teacher', fn ($query) => $query->where('active', true))
            ->first();
    }

    /**
     * @return array<int, array{coverage_type: string|null, hours: int, teacher: Teacher|null, student_flow_id: int|null}>
     */
    private function lessonParts(
        Subject $subject,
        StudentGroup $group,
        string $semesterType,
        string $academicYear,
        array $hourValues,
    ): array {
        $parts = [];

        foreach ([
            'lecture' => $hourValues['lecture_hours'],
            'exercise' => $hourValues['exercise_hours'],
        ] as $coverageType => $hours) {
            if ($hours > 0) {
                $assignment = $this->findAssignmentForSubject($subject, $group, $semesterType, $coverageType, $academicYear);
                $flowId = $coverageType === 'lecture'
                    ? StudentFlow::query()
                        ->where('subject_id', $subject->id)
                        ->whereHas('groups', fn ($query) => $query->whereKey($group->id))
                        ->orderBy('id')
                        ->value('id')
                    : null;

                $parts[] = [
                    'coverage_type' => $coverageType,
                    'hours' => $hours,
                    'teacher' => $assignment?->teacher,
                    'student_flow_id' => $flowId ?? ($coverageType === 'lecture' ? $assignment?->student_flow_id : null),
                ];
            }
        }

        return $parts;
    }

    /**
     * @return Collection<int, Room>
     */
    private function roomsForSubject(Subject $subject, StudentGroup $group): Collection
    {
        $roomTypes = $this->roomTypesForSubject($subject);

        return Room::query()
            ->where('is_active', true)
            ->whereIn('room_type', $roomTypes)
            ->where('capacity', '>=', $group->student_count)
            ->orderBy('capacity')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    private function conflicts(TimetableEntry $candidate, bool $allowUnavailableSaturday = false): array
    {
        $conflicts = [];
        $scope = fn ($query) => $query
            ->where('is_in_pool', false)
            ->where('academic_year', $candidate->academic_year)
            ->where('semester_type', $candidate->semester_type)
            ->where('cycle_week', $candidate->cycle_week)
            ->where('weekday_no', $candidate->weekday_no)
            ->where('start_time', '<', $candidate->end_time)
            ->where('end_time', '>', $candidate->start_time)
            ->when($candidate->exists, fn ($query) => $query->whereKeyNot($candidate->id));

        $groupIds = $candidate->student_flow_id
            ? StudentFlow::query()->find($candidate->student_flow_id)?->groups()->pluck('student_groups.id')->push($candidate->group_id)->unique()
            : collect([$candidate->group_id]);
        if (TimetableEntry::query()
            ->where($scope)
            ->where(function ($query) use ($groupIds): void {
                $query->whereIn('group_id', $groupIds)
                    ->orWhereHas('studentFlow.groups', fn ($groupsQuery) => $groupsQuery->whereIn('student_groups.id', $groupIds));
            })
            ->exists()) {
            $conflicts[] = 'Групата вече има занятие в този час.';
        }

        if ($candidate->teacher_id) {
            $teacher = Teacher::query()->find($candidate->teacher_id);

            if ($teacher === null || ! $teacher->active) {
                $conflicts[] = 'Избраният преподавател не е активен.';
            } else {
                if (TimetableEntry::query()->where('teacher_id', $teacher->id)->where($scope)->exists()) {
                    $conflicts[] = 'Преподавателят вече има занятие в този час.';
                }

                $teacherHours = TimetableEntry::query()
                    ->where('teacher_id', $teacher->id)
                    ->where('is_in_pool', false)
                    ->where('academic_year', $candidate->academic_year)
                    ->where('semester_type', $candidate->semester_type)
                    ->where('cycle_week', $candidate->cycle_week)
                    ->where('weekday_no', $candidate->weekday_no)
                    ->when($candidate->exists, fn ($query) => $query->whereKeyNot($candidate->id))
                    ->sum('duration_hours');

                if ($teacherHours + $candidate->duration_hours > min(10, (int) $teacher->max_daily_hours)) {
                    $conflicts[] = 'Дневното ограничение за преподавателя ще бъде надвишено.';
                }

                if (
                    $teacher->weekly_workload_hours > 0
                    && TimetableEntry::query()
                        ->where('teacher_id', $teacher->id)
                        ->where('is_in_pool', false)
                        ->where('academic_year', $candidate->academic_year)
                        ->where('semester_type', $candidate->semester_type)
                        ->where('cycle_week', $candidate->cycle_week)
                        ->when($candidate->exists, fn ($query) => $query->whereKeyNot($candidate->id))
                        ->sum('duration_hours') + $candidate->duration_hours > $teacher->weekly_workload_hours
                ) {
                    $conflicts[] = 'Седмичната натовареност на преподавателя ще бъде надвишена.';
                }

                if (
                    $this->hasUnavailablePreference($teacher->id, $candidate)
                    && ! ($allowUnavailableSaturday && $candidate->weekday_no === 6)
                ) {
                    $weekday = self::WEEKDAYS[$candidate->weekday_no] ?? "ден {$candidate->weekday_no}";
                    $conflicts[] = "Преподавателят не е на разположение в {$weekday}, седмица {$candidate->cycle_week}.";
                }

                if ($this->overlapsTeacherAbsence($teacher->id, $candidate)) {
                    $conflicts[] = 'Преподавателят има зададено отсъствие в този период.';
                }
            }
        }

        if ($candidate->room_id) {
            $room = Room::query()->find($candidate->room_id);
            $group = StudentGroup::query()->find($candidate->group_id);
            $subject = Subject::query()->find($candidate->subject_id);

            if ($room === null || ! $room->is_active) {
                $conflicts[] = 'Избраната зала не е активна.';
            } else {
                if (TimetableEntry::query()->where('room_id', $room->id)->where($scope)->exists()) {
                    $conflicts[] = 'Залата вече е заета в този час.';
                }

                if (
                    $group === null
                    || $room->capacity < $group->student_count
                    || ($subject !== null && ! in_array($room->room_type, $this->roomTypesForSubject($subject), true))
                ) {
                    $conflicts[] = 'Залата не е подходяща за тази дисциплина или група.';
                }

                if ($this->periodsConflict($room->unavailable_periods ?? [], $candidate)) {
                    $conflicts[] = 'Залата е недостъпна в избрания период.';
                }
            }
        }

        return $conflicts;
    }

    private function assertNoConflicts(TimetableEntry $candidate): void
    {
        $conflicts = $this->conflicts($candidate);

        if ($conflicts !== []) {
            throw ValidationException::withMessages(['schedule' => implode(' ', $conflicts)]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function roomTypesForSubject(Subject $subject): array
    {
        return ['lecture', 'studio', 'computer', 'atelier', 'painting', 'graphics'];
    }

    private function hasUnavailablePreference(int $teacherId, TimetableEntry $candidate): bool
    {
        return TeacherDayPreference::query()
            ->where('teacher_id', $teacherId)
            ->where('cycle_week', $candidate->cycle_week)
            ->where('weekday_no', $candidate->weekday_no)
            ->where('preference', 'unavailable')
            ->exists();
    }

    private function overlapsTeacherAbsence(int $teacherId, TimetableEntry $candidate): bool
    {
        if (preg_match('/^(\d{4})/', $candidate->academic_year, $matches) !== 1) {
            return false;
        }

        $academicYearStart = CarbonImmutable::create((int) $matches[1], 9, 1)->startOfDay();
        $cycleStart = $academicYearStart->startOfWeek();
        $academicYearEnd = $academicYearStart->addYear()->subSecond();
        $absences = TeacherAbsence::query()
            ->where('teacher_id', $teacherId)
            ->where('starts_at', '<=', $academicYearEnd)
            ->where('ends_at', '>=', $academicYearStart)
            ->get();

        foreach ($absences as $absence) {
            $date = $absence->starts_at->startOfDay()->max($academicYearStart);
            $lastDate = $absence->ends_at->startOfDay()->min($academicYearEnd);

            while ($date->lessThanOrEqualTo($lastDate)) {
                $cycleWeek = ((int) floor($cycleStart->diffInDays($date) / 7) % 3) + 1;

                if ($date->dayOfWeekIso === $candidate->weekday_no && $cycleWeek === $candidate->cycle_week) {
                    $lessonStart = $date->setTimeFromTimeString($candidate->start_time);
                    $lessonEnd = $date->setTimeFromTimeString($candidate->end_time);

                    if ($absence->starts_at->lessThan($lessonEnd) && $absence->ends_at->greaterThan($lessonStart)) {
                        return true;
                    }
                }

                $date = $date->addDay();
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $periods
     */
    private function periodsConflict(array $periods, TimetableEntry $candidate): bool
    {
        foreach ($periods as $period) {
            $start = substr((string) ($period['start_time'] ?? '00:00:00'), 0, 8);
            $end = substr((string) ($period['end_time'] ?? '00:00:00'), 0, 8);

            if (
                (int) ($period['cycle_week'] ?? 0) === $candidate->cycle_week
                && (int) ($period['weekday_no'] ?? 0) === $candidate->weekday_no
                && $start < $candidate->end_time
                && $end > $candidate->start_time
            ) {
                return true;
            }
        }

        return false;
    }
}
