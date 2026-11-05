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
        $semesterParity = $semesterType === 'winter' ? 1 : 0;
        $curriculumSubjects = CurriculumSubject::query()
            ->with('subject')
            ->where('curriculum_version_id', $group->curriculum_version_id)
            ->where('course_year', $group->course_year)
            ->whereRaw('semester_no % 2 = ?', [$semesterParity])
            ->orderBy('semester_no')
            ->get();

        $entries = [];
        $unscheduled = [];
        $warnings = [];
        $analysis = [];

        DB::transaction(function () use ($group, $academicYear, $semesterType, $curriculumSubjects, &$entries, &$unscheduled, &$warnings, &$analysis): void {
            TimetableEntry::query()
                ->where('group_id', $group->id)
                ->where('academic_year', $academicYear)
                ->where('semester_type', $semesterType)
                ->where('locked', false)
                ->delete();

            foreach ($curriculumSubjects as $curriculumSubject) {
                $subject = $curriculumSubject->subject;
                $auditoriumHours = (int) $subject->auditorium_hours;
                $lessonParts = $this->lessonParts($subject, $group, $semesterType);
                $plannedHours = array_sum(array_column($lessonParts, 'hours'));

                if ($auditoriumHours < 1 && $plannedHours < 1) {
                    $unscheduled[] = [
                        'subject' => $subject->name,
                        'reason' => 'Не са зададени аудиторни часове, лекции или упражнения.',
                        'suggestion' => 'Проверете часа̀та на дисциплината или добавете занятията ръчно.',
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

                $selfStudyHours = (int) $subject->self_study_hours;
                if ($selfStudyHours > 0) {
                    $largestPart = array_key_first(array_filter(
                        array_column($lessonParts, 'hours'),
                        fn (int $hours): bool => $hours === max(array_column($lessonParts, 'hours')),
                    ));
                    $lessonParts[$largestPart]['hours'] += $selfStudyHours;
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
                $surplusHours = 0;
                $subjectCycleWeek = null;
                $lastScheduledDay = null;
                $subjectTimeRange = null;
                foreach ($lessonParts as $part) {
                    $sessions = (int) ceil($part['hours'] / 20);
                    $scheduledHours += $sessions * 4 * self::COURSE_LENGTH_WEEKS / self::CYCLE_LENGTH_WEEKS;
                    $surplusHours += max(0, $sessions * 4 * self::COURSE_LENGTH_WEEKS / self::CYCLE_LENGTH_WEEKS - $part['hours']);

                    $alreadyScheduled = $part['student_flow_id'] === null ? 0 : TimetableEntry::query()
                        ->where('student_flow_id', $part['student_flow_id'])
                        ->where('subject_id', $subject->id)
                        ->where('academic_year', $academicYear)
                        ->where('semester_type', $semesterType)
                        ->count();

                    for ($session = $alreadyScheduled; $session < $sessions; $session++) {

                        $candidate = $this->generatedEntry($group, $subject, $part, $academicYear, $semesterType);
                        if ($subjectTimeRange === null) {
                            $placement = $this->chooseTimeRange($candidate, $subjectCycleWeek);
                            $subjectTimeRange = [
                                'start_time' => $candidate->start_time,
                                'end_time' => $candidate->end_time,
                                'duration_hours' => $candidate->duration_hours,
                            ];
                        } else {
                            $candidate->fill($subjectTimeRange);
                            $placement = $this->choosePlacement($candidate, $subjectCycleWeek, $lastScheduledDay);
                        }
                        $subjectCycleWeek ??= $placement['cycle_week'];
                        $candidate->fill([
                            'cycle_week' => $placement['cycle_week'],
                            'weekday_no' => $placement['weekday_no'],
                            'room_id' => $this->availableRoom($subject, $group, $candidate, $placement),
                        ]);
                        $slotConflicts = $this->conflicts($candidate);
                        if ($slotConflicts !== []) {
                            $warnings[] = [
                                'subject' => $subject->name,
                                'reason' => implode(' ', $slotConflicts),
                                'suggestion' => 'Проверете и преместете занятието от редакцията в разписанието.',
                            ];
                            $candidate->room_id = null;
                        }
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
                        $days[] = $candidate->weekday_no;
                        $lastScheduledDay = $candidate->weekday_no;
                    }
                }

                $effectiveSelfStudy = max(0, (int) $scheduledHours - max($auditoriumHours, $plannedHours));
                if ($surplusHours > 0) {
                    $warnings[] = [
                        'subject' => $subject->name,
                        'reason' => 'Округлянето до цели занятия добавя '.$surplusHours.' ч. самоподготовка към зададените '.(int) $subject->self_study_hours.' ч.',
                        'suggestion' => 'Проверете дали това разпределение отговаря на плана.',
                    ];
                }

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

            $this->recordRevision($group, $academicYear, $semesterType, 'Автоматично генериране');
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

            foreach ($this->conflicts($entry) as $conflict) {
                $warnings[] = [
                    'subject' => $subjectName,
                    'reason' => $conflict,
                    'suggestion' => 'Преместете занятието или коригирайте несъвместимите данни.',
                ];
            }
        }

        $semesterParity = $semesterType === 'winter' ? 1 : 0;
        $curriculumSubjects = CurriculumSubject::query()
            ->with('subject')
            ->where('curriculum_version_id', $group->curriculum_version_id)
            ->where('course_year', $group->course_year)
            ->whereRaw('semester_no % 2 = ?', [$semesterParity])
            ->get();

        foreach ($curriculumSubjects as $curriculumSubject) {
            $subject = $curriculumSubject->subject;
            $subjectEntries = $entries->where('subject_id', $subject->id);
            $auditoriumHours = (int) $subject->auditorium_hours;
            $plannedHours = (int) $subject->lecture_hours + (int) $subject->exercise_hours;
            $requiredHours = max($auditoriumHours, $plannedHours) + (int) $subject->self_study_hours;
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

            if ($scheduledHours < $requiredHours) {
                $warnings[] = [
                    'subject' => $subject->name,
                    'reason' => 'Разпределени са '.$scheduledHours.' от '.$requiredHours.' планирани аудиторни часа.',
                    'suggestion' => 'Добавете '.($requiredHours - $scheduledHours).' часа към разписанието.',
                ];
            } elseif ($scheduledHours > $requiredHours) {
                $warnings[] = [
                    'subject' => $subject->name,
                    'reason' => 'Разпределението надвишава планираните аудиторни часове с '.($scheduledHours - $requiredHours).' часа.',
                    'suggestion' => 'Проверете дали излишъкът следва да се отчете като самоподготовка.',
                ];
            }
        }

        return collect($warnings)
            ->unique(fn (array $warning): string => implode('|', $warning))
            ->values()
            ->all();
    }

    /** @param array{cycle_week:int, weekday_no:int, start_time:string, end_time:string, duration_hours:int, teacher_id:int|null, room_id:int|null, locked:bool, custom_subject_name?:string|null, note?:string|null} $data */
    public function moveEntry(TimetableEntry $entry, array $data): TimetableEntry
    {
        $candidate = clone $entry;
        $changes = [
            'teacher_id' => $data['teacher_id'],
            'room_id' => $data['room_id'],
            'cycle_week' => $data['cycle_week'],
            'weekday_no' => $data['weekday_no'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'duration_hours' => $data['duration_hours'],
            'locked' => $data['locked'],
        ];
        foreach (['coverage_type', 'student_flow_id', 'custom_subject_name', 'note'] as $optionalField) {
            if (array_key_exists($optionalField, $data)) {
                $changes[$optionalField] = $data[$optionalField];
            }
        }
        $candidate->fill($changes);

        $this->assertNoConflicts($candidate);

        $entry->fill($candidate->getAttributes());
        $entry->save();
        $this->recordRevision(
            $entry->group,
            $entry->academic_year,
            $entry->semester_type,
            'Ръчна редакция',
        );

        return $entry->refresh();
    }

    /** @param array{group_id:int, subject_id:int|null, custom_subject_name:string|null, teacher_id:int|null, room_id:int|null, academic_year:string, semester_type:string, cycle_week:int, weekday_no:int, start_time:string, end_time:string, duration_hours:int, note:string|null} $data */
    public function addManualEntry(array $data): TimetableEntry
    {
        $entry = new TimetableEntry([
            ...$data,
            'locked' => true,
        ]);

        $this->assertNoConflicts($entry);
        $entry->save();
        $this->recordRevision(
            $entry->group,
            $entry->academic_year,
            $entry->semester_type,
            'Ръчно добавяне',
        );

        return $entry->refresh();
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
                ->where('group_id', $group->id)
                ->where('academic_year', $academicYear)
                ->where('semester_type', $semesterType)
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

    /**
     * @return array{cycle_week:int, weekday_no:int}
     */
    private function chooseTimeRange(TimetableEntry $candidate, ?int $cycleWeek): array
    {
        $ranges = [
            ['start_time' => '08:00:00', 'end_time' => '11:45:00'],
            ['start_time' => '13:00:00', 'end_time' => '16:45:00'],
        ];
        $plans = [];

        foreach ($ranges as $sortOrder => $range) {
            $candidate->fill($range);
            $placement = $this->choosePlacement($candidate, $cycleWeek);
            $candidate->fill($placement);
            $plans[] = [
                'placement' => $placement,
                'conflicts' => count($this->conflicts($candidate)),
                'sort_order' => $sortOrder,
            ];
        }

        usort($plans, fn (array $first, array $second): int => [
            $first['conflicts'],
            $first['sort_order'],
        ] <=> [
            $second['conflicts'],
            $second['sort_order'],
        ]);
        $candidate->fill($ranges[$plans[0]['sort_order']]);
        $candidate->fill($plans[0]['placement']);

        return $plans[0]['placement'];
    }

    /**
     * @return array{cycle_week:int, weekday_no:int}
     */
    private function choosePlacement(TimetableEntry $candidate, ?int $cycleWeek = null, ?int $adjacentToDay = null): array
    {
        $options = [];

        foreach ($cycleWeek === null ? [1, 2, 3] : [$cycleWeek] as $candidateWeek) {
            foreach (array_keys(self::WEEKDAYS) as $weekdayNo) {
                if ($weekdayNo > 5) {
                    continue;
                }

                $candidate->cycle_week = $candidateWeek;
                $candidate->weekday_no = $weekdayNo;
                $conflictCount = count($this->conflicts($candidate));
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
                    'preference' => $preference === 'preferred' ? 0 : ($preference === 'unavailable' ? 2 : 1),
                    'day_distance' => $adjacentToDay === null ? 0 : min(
                        abs($weekdayNo - $adjacentToDay),
                        abs($weekdayNo - ($adjacentToDay - 1)),
                        abs($weekdayNo - ($adjacentToDay + 1)),
                    ),
                    'entries' => TimetableEntry::query()
                        ->where('group_id', $candidate->group_id)
                        ->where('academic_year', $candidate->academic_year)
                        ->where('semester_type', $candidate->semester_type)
                        ->where('cycle_week', $candidateWeek)
                        ->count(),
                ];
            }
        }

        usort($options, fn (array $first, array $second): int => [
            $first['conflicts'],
            $first['day_distance'],
            $first['preference'],
            $first['entries'],
            $first['cycle_week'],
            $first['weekday_no'],
        ] <=> [
            $second['conflicts'],
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

            if ($this->conflicts($candidate) === []) {
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
    ): ?TeacherAssignment {
        return TeacherAssignment::query()
            ->with('teacher')
            ->where('subject_id', $subject->id)
            ->where(function ($query) use ($group): void {
                $query->where(function ($standard) use ($group): void {
                    $standard->whereNull('student_flow_id')
                        ->where('curriculum_version_id', $group->curriculum_version_id)
                        ->where('course_year', $group->course_year);
                })->orWhere(function ($flow) use ($group): void {
                    $flow->whereNotNull('student_flow_id')
                        ->whereHas('studentFlow.groups', fn ($groups) => $groups->whereKey($group->id));
                });
            })
            ->where(function ($query) use ($semesterType): void {
                $query->whereNull('semester')->orWhere('semester', $semesterType);
            })
            ->when(
                $coverageType === null,
                fn ($query) => $query->whereNull('coverage_type'),
                fn ($query) => $query->whereIn('coverage_type', $coverageType === 'lecture'
                    ? ['lecture', 'Лекции', 'лекции', 'Лекция', 'лекция']
                    : ['exercise', 'Упражнения', 'упражнения', 'Упражнение', 'упражнение']),
            )
            ->when($coverageType !== 'lecture', fn ($query) => $query->whereNull('student_flow_id'))
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
    ): array {
        $parts = [];

        foreach ([
            'lecture' => (int) $subject->lecture_hours,
            'exercise' => (int) $subject->exercise_hours,
        ] as $coverageType => $hours) {
            if ($hours > 0) {
                $parts[] = [
                    'coverage_type' => $coverageType,
                    'hours' => $hours,
                    'teacher' => ($assignment = $this->findAssignmentForSubject($subject, $group, $semesterType, $coverageType))?->teacher,
                    'student_flow_id' => $coverageType === 'lecture' ? $assignment?->student_flow_id : null,
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
    private function conflicts(TimetableEntry $candidate): array
    {
        $conflicts = [];
        $scope = fn ($query) => $query
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
        if (TimetableEntry::query()->whereIn('group_id', $groupIds)->where($scope)->exists()) {
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
                    ->where('academic_year', $candidate->academic_year)
                    ->where('semester_type', $candidate->semester_type)
                    ->where('cycle_week', $candidate->cycle_week)
                    ->where('weekday_no', $candidate->weekday_no)
                    ->when($candidate->exists, fn ($query) => $query->whereKeyNot($candidate->id))
                    ->sum('duration_hours');

                if ($teacherHours + $candidate->duration_hours > $teacher->max_daily_hours) {
                    $conflicts[] = 'Дневното ограничение за преподавателя ще бъде надвишено.';
                }

                if (
                    $teacher->weekly_workload_hours > 0
                    && TimetableEntry::query()
                        ->where('teacher_id', $teacher->id)
                        ->where('academic_year', $candidate->academic_year)
                        ->where('semester_type', $candidate->semester_type)
                        ->where('cycle_week', $candidate->cycle_week)
                        ->when($candidate->exists, fn ($query) => $query->whereKeyNot($candidate->id))
                        ->sum('duration_hours') + $candidate->duration_hours > $teacher->weekly_workload_hours
                ) {
                    $conflicts[] = 'Седмичната натовареност на преподавателя ще бъде надвишена.';
                }

                if ($this->hasUnavailablePreference($teacher->id, $candidate)) {
                    $conflicts[] = 'Преподавателят е отбелязал този ден като нежелан.';
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
