<?php

namespace App\Services;

use App\Models\Room;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\TimetableEntry;

class ScheduleService
{
    public function generateForGroup(StudentGroup $group, string $academicYear, string $semesterType): array
    {
        $subjects = Subject::all();
        $entries = [];

        foreach ($subjects as $subject) {
            $teacher = $this->findTeacherForSubject($subject, $group);
            $room = $this->findRoomForSubject($subject);
            $slot = $this->findBestSlot($group, $subject, $teacher, $room, $academicYear, $semesterType);

            if (!$slot) {
                continue;
            }

            $entry = TimetableEntry::create([
                'group_id' => $group->id,
                'subject_id' => $subject->id,
                'teacher_id' => $teacher?->id,
                'room_id' => $room?->id,
                'academic_year' => $academicYear,
                'semester_type' => $semesterType,
                'cycle_week' => $slot['cycle_week'],
                'weekday_no' => $slot['weekday_no'],
                'start_time' => $slot['start_time'],
                'end_time' => $slot['end_time'],
                'duration_hours' => $slot['duration_hours'],
                'locked' => false,
            ]);

            $entries[] = $entry;
        }

        return $entries;
    }

    protected function findTeacherForSubject(Subject $subject, StudentGroup $group): ?Teacher
    {
        $assignment = TeacherAssignment::query()
            ->where('subject_id', $subject->id)
            ->where('curriculum_version_id', $group->curriculum_version_id)
            ->where('course_year', $group->course_year)
            ->first();

        return $assignment ? $assignment->teacher : Teacher::query()->first();
    }

    protected function findRoomForSubject(Subject $subject): ?Room
    {
        $query = Room::query()->where('is_active', true);

        if ($subject->type === 'atelier') {
            $query->where('room_type', 'atelier');
        } elseif ($subject->type === 'lecture') {
            $query->whereIn('room_type', ['lecture', 'studio']);
        } else {
            $query->whereIn('room_type', ['lecture', 'studio', 'computer']);
        }

        return $query->first();
    }

    protected function findBestSlot(StudentGroup $group, Subject $subject, ?Teacher $teacher, ?Room $room, string $academicYear, string $semesterType): ?array
    {
        $timeBlocks = [
            ['08:00:00', '12:45:00', 4],
            ['13:00:00', '15:45:00', 3],
            ['13:00:00', '17:45:00', 4],
            ['16:00:00', '18:30:00', 3],
        ];

        $weekdays = [1, 2, 3, 4, 5];
        $cycles = [1, 2, 3];

        foreach ($cycles as $cycleWeek) {
            foreach ($weekdays as $weekdayNo) {
                foreach ($timeBlocks as [$start, $end, $duration]) {
                    $candidate = new TimetableEntry([
                        'group_id' => $group->id,
                        'subject_id' => $subject->id,
                        'teacher_id' => $teacher?->id,
                        'room_id' => $room?->id,
                        'academic_year' => $academicYear,
                        'semester_type' => $semesterType,
                        'cycle_week' => $cycleWeek,
                        'weekday_no' => $weekdayNo,
                        'start_time' => $start,
                        'end_time' => $end,
                        'duration_hours' => $duration,
                    ]);

                    if ($teacher && $this->hasTeacherConflict($candidate)) {
                        continue;
                    }

                    if ($room && $this->hasRoomConflict($candidate)) {
                        continue;
                    }

                    if ($this->hasGroupConflict($candidate)) {
                        continue;
                    }

                    return [
                        'cycle_week' => $cycleWeek,
                        'weekday_no' => $weekdayNo,
                        'start_time' => $start,
                        'end_time' => $end,
                        'duration_hours' => $duration,
                    ];
                }
            }
        }

        return null;
    }

    protected function hasTeacherConflict(TimetableEntry $candidate): bool
    {
        if (!$candidate->teacher_id) {
            return false;
        }

        return TimetableEntry::where('teacher_id', $candidate->teacher_id)
            ->where('weekday_no', $candidate->weekday_no)
            ->where('cycle_week', $candidate->cycle_week)
            ->where(function ($query) use ($candidate) {
                $query->whereBetween('start_time', [$candidate->start_time, $candidate->end_time])
                    ->orWhereBetween('end_time', [$candidate->start_time, $candidate->end_time])
                    ->orWhere(function ($q) use ($candidate) {
                        $q->where('start_time', '<=', $candidate->start_time)
                            ->where('end_time', '>=', $candidate->end_time);
                    });
            })
            ->exists();
    }

    protected function hasRoomConflict(TimetableEntry $candidate): bool
    {
        if (!$candidate->room_id) {
            return false;
        }

        return TimetableEntry::where('room_id', $candidate->room_id)
            ->where('weekday_no', $candidate->weekday_no)
            ->where('cycle_week', $candidate->cycle_week)
            ->where(function ($query) use ($candidate) {
                $query->whereBetween('start_time', [$candidate->start_time, $candidate->end_time])
                    ->orWhereBetween('end_time', [$candidate->start_time, $candidate->end_time])
                    ->orWhere(function ($q) use ($candidate) {
                        $q->where('start_time', '<=', $candidate->start_time)
                            ->where('end_time', '>=', $candidate->end_time);
                    });
            })
            ->exists();
    }

    protected function hasGroupConflict(TimetableEntry $candidate): bool
    {
        return TimetableEntry::where('group_id', $candidate->group_id)
            ->where('weekday_no', $candidate->weekday_no)
            ->where('cycle_week', $candidate->cycle_week)
            ->where(function ($query) use ($candidate) {
                $query->whereBetween('start_time', [$candidate->start_time, $candidate->end_time])
                    ->orWhereBetween('end_time', [$candidate->start_time, $candidate->end_time])
                    ->orWhere(function ($q) use ($candidate) {
                        $q->where('start_time', '<=', $candidate->start_time)
                            ->where('end_time', '>=', $candidate->end_time);
                    });
            })
            ->exists();
    }
}