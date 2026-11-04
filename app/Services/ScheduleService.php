<?php

namespace App\Services;

use App\Models\CurriculumSubject;
use App\Models\Room;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\TimetableEntry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleService
{
    /**
     * @var array<int, array{start: string, end: string, hours: int}>
     */
    public const BLOCKS = [
        0 => ['start' => '08:00:00', 'end' => '12:45:00', 'hours' => 4],
        1 => ['start' => '13:00:00', 'end' => '15:45:00', 'hours' => 3],
        2 => ['start' => '13:00:00', 'end' => '17:45:00', 'hours' => 4],
        3 => ['start' => '16:00:00', 'end' => '18:30:00', 'hours' => 3],
    ];

    /**
     * @var array<int, string>
     */
    public const WEEKDAYS = [
        1 => 'Понеделник',
        2 => 'Вторник',
        3 => 'Сряда',
        4 => 'Четвъртък',
        5 => 'Петък',
    ];

    /**
     * @return array{entries: array<int, TimetableEntry>, unscheduled: array<int, array{subject: string, reason: string}>}
     */
    public function generateForGroup(StudentGroup $group, string $academicYear, string $semesterType): array
    {
        if (! $group->curriculum_version_id) {
            throw ValidationException::withMessages([
                'group_id' => 'Избраната група няма свързана версия на учебен план.',
            ]);
        }

        $semesterParity = $semesterType === 'winter' ? 1 : 0;
        $curriculumSubjects = CurriculumSubject::query()
            ->with('subject')
            ->where('curriculum_version_id', $group->curriculum_version_id)
            ->where('course_year', $group->course_year)
            ->whereRaw('semester_no % 2 = ?', [$semesterParity])
            ->whereHas('subject', fn ($query) => $query->where('type', '!=', 'selfstudy'))
            ->orderBy('semester_no')
            ->get();

        if ($curriculumSubjects->isEmpty()) {
            throw ValidationException::withMessages([
                'group_id' => 'В учебния план няма дисциплини за курса и семестъра на групата.',
            ]);
        }

        $entries = [];
        $unscheduled = [];

        DB::transaction(function () use ($group, $academicYear, $semesterType, $curriculumSubjects, &$entries, &$unscheduled): void {
            TimetableEntry::query()
                ->where('group_id', $group->id)
                ->where('academic_year', $academicYear)
                ->where('semester_type', $semesterType)
                ->where('locked', false)
                ->delete();

            foreach ($curriculumSubjects as $curriculumSubject) {
                $subject = $curriculumSubject->subject;
                $teacher = $this->findTeacherForSubject($subject, $group, $semesterType);
                $scheduledHours = (int) ($curriculumSubject->scheduled_hours ?: $subject->default_hours);
                if ($curriculumSubject->cycle_weeks === null && $curriculumSubject->source_data !== null) {
                    $unscheduled[] = [
                        'subject' => $subject->name,
                        'reason' => 'За импортираната дисциплина не са зададени седмици от цикъла.',
                    ];

                    continue;
                }

                $weeks = $curriculumSubject->cycle_weeks ?: [1, 2, 3];
                $weeks = array_values(array_unique(array_filter(
                    $weeks,
                    fn ($week): bool => in_array((int) $week, [1, 2, 3], true),
                )));

                if ($weeks === []) {
                    $unscheduled[] = [
                        'subject' => $subject->name,
                        'reason' => 'За дисциплината не е зададена валидна седмица от цикъла.',
                    ];

                    continue;
                }

                if (! in_array($scheduledHours, [3, 4], true)) {
                    $unscheduled[] = [
                        'subject' => $subject->name,
                        'reason' => 'Зададените часове трябва да съответстват на наличен блок от 3 или 4 учебни часа.',
                    ];

                    continue;
                }

                foreach ($weeks as $cycleWeek) {
                    $hasLockedEntry = TimetableEntry::query()
                        ->where('group_id', $group->id)
                        ->where('subject_id', $subject->id)
                        ->where('academic_year', $academicYear)
                        ->where('semester_type', $semesterType)
                        ->where('cycle_week', $cycleWeek)
                        ->where('locked', true)
                        ->exists();

                    if ($hasLockedEntry) {
                        continue;
                    }

                    $assignment = $this->findBestAssignment(
                        $group,
                        $subject,
                        $teacher,
                        $scheduledHours,
                        (int) $cycleWeek,
                        $academicYear,
                        $semesterType,
                    );

                    if ($assignment === null) {
                        $unscheduled[] = [
                            'subject' => $subject->name,
                            'reason' => 'Няма свободен подходящ блок, преподавател и зала за седмица '.$cycleWeek.'.',
                        ];

                        continue;
                    }

                    $entries[] = TimetableEntry::create([
                        'group_id' => $group->id,
                        'subject_id' => $subject->id,
                        'teacher_id' => $teacher?->id,
                        'room_id' => $assignment['room']->id,
                        'academic_year' => $academicYear,
                        'semester_type' => $semesterType,
                        'cycle_week' => (int) $cycleWeek,
                        'weekday_no' => $assignment['weekday_no'],
                        'start_time' => $assignment['start_time'],
                        'end_time' => $assignment['end_time'],
                        'duration_hours' => $assignment['duration_hours'],
                        'locked' => false,
                    ]);
                }
            }

            $this->recordRevision($group, $academicYear, $semesterType, 'Автоматично генериране');
        });

        return ['entries' => $entries, 'unscheduled' => $unscheduled];
    }

    /**
     * @param  array{block: int, cycle_week: int, weekday_no: int, teacher_id: int|null, room_id: int|null, locked: bool}  $data
     */
    public function moveEntry(TimetableEntry $entry, array $data): TimetableEntry
    {
        $block = self::BLOCKS[$data['block']];
        $candidate = clone $entry;
        $candidate->fill([
            'teacher_id' => $data['teacher_id'],
            'room_id' => $data['room_id'],
            'cycle_week' => $data['cycle_week'],
            'weekday_no' => $data['weekday_no'],
            'start_time' => $block['start'],
            'end_time' => $block['end'],
            'duration_hours' => $block['hours'],
            'locked' => $data['locked'],
        ]);

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
                    'subject' => $entry->subject->name,
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
     * @return array{room: Room, weekday_no: int, start_time: string, end_time: string, duration_hours: int}|null
     */
    private function findBestAssignment(
        StudentGroup $group,
        Subject $subject,
        ?Teacher $teacher,
        int $scheduledHours,
        int $cycleWeek,
        string $academicYear,
        string $semesterType,
    ): ?array {
        $rooms = $this->roomsForSubject($subject, $group);
        $preferredDays = array_map('intval', $teacher?->preferred_weekdays ?? []);
        $weekdays = array_values(array_unique(array_merge(
            array_intersect($preferredDays, array_keys(self::WEEKDAYS)),
            array_diff(array_keys(self::WEEKDAYS), $preferredDays),
        )));

        foreach ($rooms as $room) {
            foreach ($weekdays as $weekdayNo) {
                foreach (self::BLOCKS as $block) {
                    if ($block['hours'] !== $scheduledHours) {
                        continue;
                    }

                    $candidate = new TimetableEntry([
                        'group_id' => $group->id,
                        'subject_id' => $subject->id,
                        'teacher_id' => $teacher?->id,
                        'room_id' => $room->id,
                        'academic_year' => $academicYear,
                        'semester_type' => $semesterType,
                        'cycle_week' => $cycleWeek,
                        'weekday_no' => $weekdayNo,
                        'start_time' => $block['start'],
                        'end_time' => $block['end'],
                        'duration_hours' => $block['hours'],
                    ]);

                    if ($this->conflicts($candidate) === []) {
                        return [
                            'room' => $room,
                            'weekday_no' => $weekdayNo,
                            'start_time' => $block['start'],
                            'end_time' => $block['end'],
                            'duration_hours' => $block['hours'],
                        ];
                    }
                }
            }
        }

        return null;
    }

    private function findTeacherForSubject(Subject $subject, StudentGroup $group, string $semesterType): ?Teacher
    {
        return TeacherAssignment::query()
            ->with('teacher')
            ->where('subject_id', $subject->id)
            ->where('curriculum_version_id', $group->curriculum_version_id)
            ->where('course_year', $group->course_year)
            ->where(function ($query) use ($semesterType): void {
                $query->whereNull('semester')->orWhere('semester', $semesterType);
            })
            ->whereHas('teacher', fn ($query) => $query->where('active', true))
            ->first()
            ?->teacher;
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

        if (TimetableEntry::query()->where('group_id', $candidate->group_id)->where($scope)->exists()) {
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

                if (in_array($candidate->weekday_no, array_map('intval', $teacher->unavailable_weekdays ?? []), true)) {
                    $conflicts[] = 'Преподавателят е недостъпен в избрания ден.';
                }

                if ($this->periodsConflict($teacher->unavailable_periods ?? [], $candidate)) {
                    $conflicts[] = 'Преподавателят е недостъпен в избрания период.';
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
                    || $subject === null
                    || $room->capacity < $group->student_count
                    || ! in_array($room->room_type, $this->roomTypesForSubject($subject), true)
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
        return match ($subject->type) {
            'atelier' => ['atelier', 'painting', 'graphics', 'studio'],
            'lecture', 'stream_lecture' => ['lecture', 'studio'],
            'practice' => ['atelier', 'painting', 'graphics', 'studio', 'computer'],
            default => ['lecture', 'studio', 'computer', 'atelier', 'painting', 'graphics'],
        };
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
