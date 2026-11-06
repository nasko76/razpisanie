<?php

namespace App\Http\Controllers;

use App\Models\SoftwareSetting;
use App\Models\Specialty;
use App\Models\StudentGroup;
use App\Models\Teacher;
use App\Models\TimetableEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class PublicScheduleController extends Controller
{
    private const DAYS = [
        1 => 'Понеделник',
        2 => 'Вторник',
        3 => 'Сряда',
        4 => 'Четвъртък',
        5 => 'Петък',
        6 => 'Събота',
        7 => 'Неделя',
    ];

    private const DAY_PARTS = [
        'morning' => ['label' => 'Сутрин', 'before' => '12:00'],
        'afternoon' => ['label' => 'Следобед', 'before' => '16:00'],
        'evening' => ['label' => 'Вечер', 'before' => null],
    ];

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'specialty_id' => ['nullable', 'integer', 'exists:specialties,id'],
            'course_year' => ['nullable', 'integer', 'between:1,6'],
            'group_id' => ['nullable', 'integer', 'exists:student_groups,id'],
            'cycle_week' => ['nullable', 'integer', 'between:1,3'],
        ]);

        $specialtyId = isset($filters['specialty_id']) ? (int) $filters['specialty_id'] : null;
        $courseYear = isset($filters['course_year']) ? (int) $filters['course_year'] : null;
        $selectedGroup = isset($filters['group_id'])
            ? StudentGroup::query()->with('specialty')->findOrFail($filters['group_id'])
            : null;

        if ($selectedGroup !== null
            && ((int) $selectedGroup->specialty_id !== $specialtyId || (int) $selectedGroup->course_year !== $courseYear)) {
            abort(404);
        }

        $hasSelection = $specialtyId !== null && $courseYear !== null;
        $groupsQuery = StudentGroup::query()
            ->with('specialty')
            ->when($specialtyId !== null, fn ($query) => $query->where('specialty_id', $specialtyId))
            ->when($courseYear !== null, fn ($query) => $query->where('course_year', $courseYear))
            ->orderBy('group_name');
        $groups = $groupsQuery->get();
        $groupOptions = $groups->map(fn (StudentGroup $group): array => [
            'id' => $group->id,
            'group_name' => $group->display_name,
            'specialty_id' => $group->specialty_id,
            'course_year' => $group->course_year,
        ])->values()->all();
        $scheduleGroupIds = $selectedGroup !== null
            ? collect([$selectedGroup->id])
            : $groups->pluck('id');

        $activeEntry = $hasSelection && $scheduleGroupIds->isNotEmpty()
            ? TimetableEntry::query()
                ->whereIn('group_id', $scheduleGroupIds)
                ->orWhereHas('studentFlow.groups', fn ($query) => $query->whereIn('student_groups.id', $scheduleGroupIds))
                ->orderByDesc('academic_year')
                ->orderByDesc('updated_at')
                ->first(['academic_year', 'semester_type'])
            : null;
        [$academicYear, $semesterType] = $activeEntry === null
            ? $this->currentTerm()
            : [$activeEntry->academic_year, $activeEntry->semester_type];
        $cycleWeek = (int) ($filters['cycle_week'] ?? 1);
        $entries = $hasSelection && $scheduleGroupIds->isNotEmpty()
            ? TimetableEntry::query()
                ->with(['subject', 'teacher', 'room', 'group'])
                ->where(function ($query) use ($scheduleGroupIds): void {
                    $query->whereIn('group_id', $scheduleGroupIds)
                        ->orWhereHas('studentFlow.groups', fn ($groupsQuery) => $groupsQuery->whereIn('student_groups.id', $scheduleGroupIds));
                })
                ->where('academic_year', $academicYear)
                ->where('semester_type', $semesterType)
                ->where('cycle_week', $cycleWeek)
                ->orderBy('weekday_no')
                ->orderBy('start_time')
                ->get()
            : collect();

        $schedule = [];

        foreach ($entries as $entry) {
            $part = $this->dayPart($entry->start_time);
            $schedule[$entry->weekday_no][$part][] = $entry;
        }
        $teacherNamesBySubject = $entries->whereNotNull('subject_id')
            ->groupBy(fn (TimetableEntry $entry): string => $entry->group_id.':'.$entry->subject_id)
            ->map(fn (Collection $subjectEntries): array => $subjectEntries
                ->groupBy(fn (TimetableEntry $entry): string => $entry->coverage_type ?? '')
                ->flatMap(function (Collection $componentEntries, string $coverageType): array {
                    $prefix = match ($coverageType) {
                        'lecture' => 'Лекции: ',
                        'exercise' => 'Упражнения: ',
                        default => '',
                    };

                    return $componentEntries->pluck('teacher')
                        ->filter()
                        ->unique('id')
                        ->map(fn (Teacher $teacher): string => $prefix.trim(($teacher->title ? $teacher->title.' ' : '').$teacher->first_name.' '.$teacher->last_name))
                        ->all();
                })
                ->unique()
                ->values()
                ->all())
            ->all();

        return view('schedules.public', [
            'specialties' => Specialty::query()->orderBy('name')->get(),
            'groups' => $groups,
            'groupOptions' => $groupOptions,
            'selectedSpecialtyId' => $specialtyId,
            'selectedCourseYear' => $courseYear,
            'selectedGroup' => $selectedGroup,
            'hasSelection' => $hasSelection,
            'academicYear' => $academicYear,
            'semesterType' => $semesterType,
            'semesterLabel' => $semesterType === 'summer' ? 'Летен семестър' : 'Зимен семестър',
            'cycleWeek' => $cycleWeek,
            'schedule' => $schedule,
            'teacherNamesBySubject' => $teacherNamesBySubject,
            'days' => self::DAYS,
            'dayParts' => self::DAY_PARTS,
            'publicScheduleNotice' => SoftwareSetting::text('public_schedule_notice'),
        ]);
    }

    /**
     * @return array{string, string}
     */
    private function currentTerm(): array
    {
        $year = now()->year;
        $academicYear = now()->month >= 9
            ? $year.'-'.($year + 1)
            : ($year - 1).'-'.$year;
        $semesterType = now()->month >= 2 && now()->month < 9 ? 'summer' : 'winter';

        return [$academicYear, $semesterType];
    }

    private function dayPart(string $startTime): string
    {
        $time = substr($startTime, 0, 5);

        foreach (self::DAY_PARTS as $part => $definition) {
            if ($definition['before'] === null || $time < $definition['before']) {
                return $part;
            }
        }

        return 'evening';
    }
}
