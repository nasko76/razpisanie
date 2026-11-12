<?php

namespace App\Http\Controllers;

use App\Models\CurriculumSubject;
use App\Models\Room;
use App\Models\ScheduleNote;
use App\Models\StudentFlow;
use App\Models\StudentGroup;
use App\Models\Teacher;
use App\Models\TimetableEntry;
use App\Services\ScheduleService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response as ResponseFacade;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ScheduleController extends Controller
{
    public function index(Request $request, ScheduleService $scheduleService): View
    {
        $filters = $request->validate([
            'group_id' => ['nullable', 'integer', 'exists:student_groups,id'],
            'academic_year' => ['nullable', 'string', 'max:20'],
            'semester_type' => ['nullable', 'in:winter,summer'],
            'current_semester' => ['nullable', 'integer', 'between:1,4'],
            'cycle_week' => ['nullable', 'integer', 'between:1,3'],
        ]);

        $groupsQuery = StudentGroup::query()
            ->with(['specialty', 'curriculumVersion'])
            ->orderBy('specialty_id')
            ->orderBy('course_year')
            ->orderBy('group_name');
        if (isset($filters['current_semester'])) {
            $groupsQuery->whereHas('curriculumVersion', fn ($query) => $query->where('current_semester', $filters['current_semester']));
        }
        $groups = $groupsQuery->get();

        $selectedGroup = isset($filters['group_id'])
            ? $groups->firstWhere('id', (int) $filters['group_id'])
            : $groups->first();
        $academicYear = $filters['academic_year'] ?? $this->defaultAcademicYear();
        $semesterType = $filters['semester_type'] ?? 'winter';
        $cycleWeek = (int) ($filters['cycle_week'] ?? 1);
        $entries = collect();
        $curriculumSubjects = collect();

        if ($selectedGroup !== null) {
            $semesterParity = $semesterType === 'winter' ? 1 : 0;
            $curriculumSubjects = CurriculumSubject::query()
                ->with('subject')
                ->where('curriculum_version_id', $selectedGroup->curriculum_version_id)
                ->where('course_year', $selectedGroup->course_year)
                ->whereRaw('semester_no % 2 = ?', [$semesterParity])
                ->orderBy('semester_no')
                ->get();
            $entries = TimetableEntry::query()
                ->with(['subject', 'teacher', 'room', 'studentFlow'])
                ->where(function ($query) use ($selectedGroup): void {
                    $query->where('group_id', $selectedGroup->id)
                        ->orWhereHas('studentFlow.groups', fn ($groupsQuery) => $groupsQuery->whereKey($selectedGroup->id));
                })
                ->where('academic_year', $academicYear)
                ->where('semester_type', $semesterType)
                ->where('is_in_pool', false)
                ->orderBy('weekday_no')
                ->orderBy('cycle_week')
                ->orderBy('start_time')
                ->get();
            $poolEntries = TimetableEntry::query()
                ->with(['subject', 'teacher', 'room', 'studentFlow'])
                ->where(function ($query) use ($selectedGroup): void {
                    $query->where('group_id', $selectedGroup->id)
                        ->orWhereHas('studentFlow.groups', fn ($groupsQuery) => $groupsQuery->whereKey($selectedGroup->id));
                })
                ->where('academic_year', $academicYear)
                ->where('semester_type', $semesterType)
                ->where('is_in_pool', true)
                ->orderBy('cycle_week')
                ->orderBy('weekday_no')
                ->orderBy('start_time')
                ->get();
            $poolEntries = $poolEntries
                ->groupBy(fn (TimetableEntry $entry): string => implode('|', [
                    $entry->student_flow_id ? 'flow-'.$entry->student_flow_id : 'group-'.$entry->group_id,
                    $entry->subject_id ? 'subject-'.$entry->subject_id : 'custom-'.mb_strtolower(trim((string) $entry->custom_subject_name)),
                ]))
                ->map(function ($entries): object {
                    $blocks = $entries->sortBy('id')->values();

                    return (object) [
                        'entry' => $blocks->first(),
                        'blocks' => $blocks,
                        'block_count' => $blocks->count(),
                        'all_standard_blocks' => $blocks->every(fn (TimetableEntry $block): bool => (int) $block->duration_hours === 4),
                        'remaining_hours' => (int) $blocks->sum('duration_hours') * 5,
                        'auditorium_hours' => (float) $blocks->sum('auditorium_hours') * 5,
                        'self_study_hours' => (float) $blocks->sum('self_study_hours') * 5,
                        'coverage_types' => $blocks->pluck('coverage_type')
                            ->filter()
                            ->unique()
                            ->map(fn (string $coverageType): string => $coverageType === 'lecture' ? 'Лекции' : 'Упражнения')
                            ->values(),
                        'teachers' => $blocks->map(fn (TimetableEntry $block): ?string => $block->teacher
                            ? trim(($block->teacher->title ? $block->teacher->title.' ' : '').$block->teacher->first_name.' '.$block->teacher->last_name)
                            : null)->filter()->unique()->values(),
                        'has_manual_blocks' => $blocks->contains(fn (TimetableEntry $block): bool => $block->pool_reason !== 'auto'),
                    ];
                })
                ->values();
        }

        return view('schedules.index', [
            'groups' => $groups,
            'selectedGroup' => $selectedGroup,
            'academicYear' => $academicYear,
            'semesterType' => $semesterType,
            'currentSemester' => isset($filters['current_semester']) ? (int) $filters['current_semester'] : null,
            'cycleWeek' => $cycleWeek,
            'curriculumSubjects' => $curriculumSubjects,
            'note' => $selectedGroup === null ? null : ScheduleNote::query()
                ->where('group_id', $selectedGroup->id)
                ->where('academic_year', $academicYear)
                ->where('semester_type', $semesterType)
                ->value('note'),
            'entries' => $entries,
            'poolEntries' => $poolEntries ?? collect(),
            'teachers' => Teacher::query()->where('active', true)->orderBy('last_name')->get(),
            'rooms' => Room::query()->where('is_active', true)->orderBy('name')->get(),
            'studentFlows' => StudentFlow::query()->with('groups')->whereNotNull('subject_id')->orderBy('name')->get(),
            'teacherNamesBySubject' => $this->teacherNamesBySubject($entries),
            'dayParts' => [
                ['key' => 'morning', 'label' => 'Сутрин', 'after' => null, 'before' => '12:00'],
                ['key' => 'afternoon', 'label' => 'Следобед', 'after' => '12:00', 'before' => '16:00'],
                ['key' => 'evening', 'label' => 'Вечер', 'after' => '16:00', 'before' => null],
            ],
            'weekdays' => ScheduleService::WEEKDAYS,
            'scheduleTested' => session('schedule_tested', false),
            'scheduleTestWarnings' => session('schedule_test_warnings', []),
            'teacherLoads' => TimetableEntry::query()
                ->where('academic_year', $academicYear)
                ->where('semester_type', $semesterType)
                ->where('is_in_pool', false)
                ->whereNotNull('teacher_id')
                ->selectRaw('teacher_id, SUM(duration_hours) as scheduled_hours')
                ->groupBy('teacher_id')
                ->pluck('scheduled_hours', 'teacher_id'),
        ]);
    }

    public function generate(Request $request, ScheduleService $scheduleService): RedirectResponse
    {
        $data = $request->validate([
            'group_id' => ['required', 'integer', 'exists:student_groups,id'],
            'academic_year' => ['required', 'string', 'max:20'],
            'semester_type' => ['required', 'in:winter,summer'],
        ]);

        $group = StudentGroup::query()->findOrFail($data['group_id']);
        $result = $scheduleService->generateForGroup($group, $data['academic_year'], $data['semester_type']);

        $status = 'Генерирани са '.count($result['entries']).' занятия във временния пул за ръчно подреждане.'
            .' Вече поставените общи потоци са запазени на същото място.';
        if ($result['unscheduled'] !== []) {
            $status .= ' Някои занятия изискват допълнителна проверка.';
        }

        return redirect()->route('schedules.index', [
            'group_id' => $group->id,
            'academic_year' => $data['academic_year'],
            'semester_type' => $data['semester_type'],
        ])->with([
            'status' => $status,
        ]);
    }

    public function copyPreviousWinter(Request $request, ScheduleService $scheduleService): RedirectResponse
    {
        $data = $request->validate([
            'group_id' => ['required', 'integer', 'exists:student_groups,id'],
            'academic_year' => ['required', 'string', 'max:20'],
        ]);
        $group = StudentGroup::query()->findOrFail($data['group_id']);
        $result = $scheduleService->copyPreviousWinter($group, $data['academic_year']);

        return redirect()->route('schedules.index', [
            'group_id' => $group->id,
            'academic_year' => $data['academic_year'],
            'semester_type' => 'winter',
        ])->with(
            'status',
            'Копирани са '.$result['copied'].' занятия от зимния семестър '.$result['source_academic_year']
                .' в '.$data['academic_year'].'. Вече поставените целеви потоци са запазени.',
        );
    }

    public function refresh(Request $request, ScheduleService $scheduleService): RedirectResponse
    {
        $data = $request->validate([
            'group_id' => ['required', 'integer', 'exists:student_groups,id'],
            'academic_year' => ['required', 'string', 'max:20'],
            'semester_type' => ['required', 'in:winter,summer'],
        ]);
        $group = StudentGroup::query()->findOrFail($data['group_id']);
        $result = $scheduleService->refreshForGroup($group, $data['academic_year'], $data['semester_type']);
        $message = 'Разписанието е презаредено без преместване на запазените занятия.'
            .' Добавени в пула: '.$result['added'].'. Преместени в пула: '.$result['pooled'].'. Премахнати излишни автоматични блокове: '.$result['removed'].'.';

        if ($result['messages'] !== []) {
            $message .= ' '.implode(' ', $result['messages']);
        }

        return redirect()->route('schedules.index', [
            'group_id' => $group->id,
            'academic_year' => $data['academic_year'],
            'semester_type' => $data['semester_type'],
        ])->with([
            'status' => $message,
            'schedule_refresh_warnings' => array_values(array_unique($result['warnings'])),
        ]);
    }

    public function test(Request $request, ScheduleService $scheduleService): RedirectResponse
    {
        $data = $request->validate([
            'group_id' => ['required', 'integer', 'exists:student_groups,id'],
            'academic_year' => ['required', 'string', 'max:20'],
            'semester_type' => ['required', 'in:winter,summer'],
        ]);
        $group = StudentGroup::query()->findOrFail($data['group_id']);
        $warnings = $scheduleService->testSchedule($group, $data['academic_year'], $data['semester_type']);

        return redirect()->route('schedules.index', [
            'group_id' => $group->id,
            'academic_year' => $data['academic_year'],
            'semester_type' => $data['semester_type'],
        ])->with([
            'schedule_tested' => true,
            'schedule_test_warnings' => $warnings,
            'status' => $warnings === []
                ? 'Проверката приключи: не са открити забележки.'
                : 'Проверката приключи. Намерени са '.count($warnings).' забележки или препоръки.',
        ]);
    }

    public function history(Request $request, StudentGroup $group): View
    {
        $filters = $request->validate([
            'academic_year' => ['nullable', 'string', 'max:20'],
            'semester_type' => ['nullable', 'in:winter,summer'],
        ]);
        $academicYear = $filters['academic_year'] ?? $this->defaultAcademicYear();
        $semesterType = $filters['semester_type'] ?? 'winter';
        $revisions = DB::table('schedule_revisions')
            ->where('group_id', $group->id)
            ->where('academic_year', $academicYear)
            ->where('semester_type', $semesterType)
            ->orderByDesc('revision_no')
            ->limit(20)
            ->get()
            ->map(function (object $revision): object {
                $revision->snapshot = json_decode($revision->snapshot, true, flags: JSON_THROW_ON_ERROR);

                return $revision;
            });
        $latest = $revisions->first();
        $previous = $revisions->get(1);

        return view('schedules.history', [
            'group' => $group->load(['specialty', 'curriculumVersion']),
            'academicYear' => $academicYear,
            'semesterType' => $semesterType,
            'revisions' => $revisions,
            'latest' => $latest,
            'previous' => $previous,
            'changes' => $this->compareSnapshots($previous?->snapshot ?? [], $latest?->snapshot ?? []),
            'weekdays' => ScheduleService::WEEKDAYS,
        ]);
    }

    public function update(Request $request, TimetableEntry $entry, ScheduleService $scheduleService): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'cycle_week' => ['required', 'integer', 'between:1,3'],
            'weekday_no' => ['required', 'integer', 'between:1,7'],
            'day_part' => ['sometimes', 'in:morning,afternoon,evening'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'duration_hours' => ['nullable', 'integer', 'between:1,16'],
            'self_study_hours' => ['sometimes', 'numeric', 'between:0,16'],
            'teacher_id' => ['nullable', 'integer', 'exists:teachers,id'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'coverage_type' => ['nullable', 'in:lecture,exercise'],
            'student_flow_id' => ['nullable', 'integer', 'exists:student_flows,id'],
            'custom_subject_name' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'locked' => ['sometimes', 'boolean'],
            'is_in_pool' => ['sometimes', 'boolean'],
        ]);

        $data['teacher_id'] = isset($data['teacher_id']) ? (int) $data['teacher_id'] : null;
        $data['room_id'] = isset($data['room_id']) ? (int) $data['room_id'] : null;
        $data['start_time'] .= ':00';
        $data['end_time'] .= ':00';
        $data['duration_hours'] = $scheduleService->teachingHours($data['start_time'], $data['end_time']);
        if (isset($data['student_flow_id'])) {
            $flow = StudentFlow::query()->with('groups')->findOrFail($data['student_flow_id']);
            if (
                $data['coverage_type'] !== 'lecture'
                || $flow->subject_id !== $entry->subject_id
                || ! $flow->groups->contains('id', $entry->group_id)
            ) {
                throw ValidationException::withMessages([
                    'student_flow_id' => 'Потокът трябва да е за същата дисциплина и да включва избраната група.',
                ]);
            }
        }

        $data['locked'] = $request->boolean('locked');

        $entry = $scheduleService->moveEntry($entry, $data);
        $automaticallyPlaced = (int) $entry->getAttribute('auto_placed_count');
        $stillInPool = (int) $entry->getAttribute('auto_pending_count');
        $status = 'Промените са запазени.';
        if ($automaticallyPlaced > 0) {
            $status .= ' Автоматично са разпределени още '.$automaticallyPlaced.' блока по следващите свободни дни.';
        }
        if ($stillInPool > 0) {
            $status .= ' '.$stillInPool.' блока останаха в пула заради липса на съвместими свободни места.';
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => $status]);
        }

        return redirect()->route('schedules.index', $request->only([
            'group_id',
            'academic_year',
            'semester_type',
            'cycle_week',
        ]))->with('status', $status);
    }

    public function park(Request $request, TimetableEntry $entry, ScheduleService $scheduleService): RedirectResponse|JsonResponse
    {
        $scheduleService->parkEntry($entry);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Занятието е преместено във временния пул.']);
        }

        return redirect()->route('schedules.index', $request->only([
            'group_id',
            'academic_year',
            'semester_type',
            'cycle_week',
        ]))->with('status', 'Занятието е преместено във временния пул за ръчно преразпределяне.');
    }

    public function storeManualEntry(Request $request, ScheduleService $scheduleService): RedirectResponse
    {
        $data = $request->validate([
            'group_id' => ['required', 'integer', 'exists:student_groups,id'],
            'academic_year' => ['required', 'string', 'max:20'],
            'semester_type' => ['required', 'in:winter,summer'],
            'cycle_week' => ['required', 'integer', 'between:1,3'],
            'weekday_no' => ['required', 'integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'duration_hours' => ['nullable', 'integer', 'between:1,16'],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'custom_subject_name' => ['required_without:subject_id', 'nullable', 'string', 'max:255'],
            'teacher_id' => ['nullable', 'integer', 'exists:teachers,id'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'coverage_type' => ['nullable', 'in:lecture,exercise'],
            'student_flow_id' => ['nullable', 'integer', 'exists:student_flows,id'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['group_id'] = (int) $data['group_id'];
        $data['subject_id'] = isset($data['subject_id']) ? (int) $data['subject_id'] : null;
        $data['teacher_id'] = isset($data['teacher_id']) ? (int) $data['teacher_id'] : null;
        $data['room_id'] = isset($data['room_id']) ? (int) $data['room_id'] : null;
        $data['start_time'] .= ':00';
        $data['end_time'] .= ':00';
        $data['duration_hours'] = $scheduleService->teachingHours($data['start_time'], $data['end_time']);
        if (isset($data['student_flow_id'])) {
            $flow = StudentFlow::query()->with('groups')->findOrFail($data['student_flow_id']);
            if (
                $data['coverage_type'] !== 'lecture'
                || $flow->subject_id !== $data['subject_id']
                || ! $flow->groups->contains('id', $data['group_id'])
            ) {
                throw ValidationException::withMessages([
                    'student_flow_id' => 'Потокът трябва да е за същата дисциплина и да включва избраната група.',
                ]);
            }
        }

        $entry = $scheduleService->addManualEntry($data);

        return redirect()->route('schedules.index', [
            'group_id' => $entry->group_id,
            'academic_year' => $entry->academic_year,
            'semester_type' => $entry->semester_type,
            'cycle_week' => $entry->cycle_week,
        ])->with('status', 'Ръчното занятие е добавено.');
    }

    public function updateNote(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'group_id' => ['required', 'integer', 'exists:student_groups,id'],
            'academic_year' => ['required', 'string', 'max:20'],
            'semester_type' => ['required', 'in:winter,summer'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);
        ScheduleNote::query()->updateOrCreate(
            [
                'group_id' => $data['group_id'],
                'academic_year' => $data['academic_year'],
                'semester_type' => $data['semester_type'],
            ],
            ['note' => $data['note'] ?? null],
        );

        return redirect()->route('schedules.index', $request->only([
            'group_id',
            'academic_year',
            'semester_type',
            'cycle_week',
        ]))->with('status', 'Бележката към графика е запазена.');
    }

    public function destroy(Request $request, TimetableEntry $entry, ScheduleService $scheduleService): RedirectResponse
    {
        $scheduleService->parkEntry($entry, 'Ръчно премахване');

        return redirect()->route('schedules.index', $request->only([
            'group_id',
            'academic_year',
            'semester_type',
            'cycle_week',
        ]))->with('status', 'Занятието е преместено във временния пул и може да бъде върнато в разписанието.');
    }

    public function exportPdf(Request $request, StudentGroup $group): Response
    {
        $filters = $request->validate([
            'academic_year' => ['nullable', 'string', 'max:20'],
            'semester_type' => ['nullable', 'in:winter,summer'],
        ]);

        $academicYear = $filters['academic_year'] ?? $this->defaultAcademicYear();
        $semesterType = $filters['semester_type'] ?? 'winter';
        $entries = $this->entriesForGroup($group, $academicYear, $semesterType);

        return Pdf::loadView('schedules.pdf', [
            'group' => $group->load(['specialty', 'curriculumVersion']),
            'entries' => $entries,
            'academicYear' => $academicYear,
            'semesterType' => $semesterType,
            'note' => ScheduleNote::query()
                ->where('group_id', $group->id)
                ->where('academic_year', $academicYear)
                ->where('semester_type', $semesterType)
                ->value('note'),
            'weekdays' => ScheduleService::WEEKDAYS,
            'teacherNamesBySubject' => $this->teacherNamesBySubject($entries),
        ])->download('raspisanie-grupa-'.$group->id.'.pdf');
    }

    public function exportCsv(Request $request, StudentGroup $group): StreamedResponse
    {
        $filters = $request->validate([
            'academic_year' => ['nullable', 'string', 'max:20'],
            'semester_type' => ['nullable', 'in:winter,summer'],
        ]);

        $academicYear = $filters['academic_year'] ?? $this->defaultAcademicYear();
        $semesterType = $filters['semester_type'] ?? 'winter';
        $entries = $this->entriesForGroup($group, $academicYear, $semesterType);
        $weekdays = ScheduleService::WEEKDAYS;
        $scheduleNote = ScheduleNote::query()
            ->where('group_id', $group->id)
            ->where('academic_year', $academicYear)
            ->where('semester_type', $semesterType)
            ->value('note');
        $teacherNamesBySubject = $this->teacherNamesBySubject($entries);

        return ResponseFacade::streamDownload(function () use ($entries, $weekdays, $scheduleNote, $teacherNamesBySubject): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Седмица', 'Ден', 'Начало', 'Край', 'Дисциплина', 'Преподаватели', 'Зала', 'Бележка към занятието', 'Бележка към графика'], ';');

            foreach ($entries as $entry) {
                fputcsv($output, [
                    $entry->cycle_week,
                    $weekdays[$entry->weekday_no] ?? $entry->weekday_no,
                    substr($entry->start_time, 0, 5),
                    substr($entry->end_time, 0, 5),
                    $entry->subject?->name ?? $entry->custom_subject_name,
                    implode(', ', $teacherNamesBySubject[$entry->subject_id] ?? $this->teacherNames($entry->teacher ? collect([$entry->teacher]) : collect())),
                    $entry->room?->name ?? '',
                    $entry->note,
                    $scheduleNote,
                ], ';');
            }

            fclose($output);
        }, 'raspisanie-grupa-'.$group->id.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  Collection<int, TimetableEntry>  $entries
     * @return array<int, array<int, string>>
     */
    private function teacherNamesBySubject(Collection $entries): array
    {
        return $entries->whereNotNull('subject_id')
            ->groupBy('subject_id')
            ->map(fn (Collection $subjectEntries): array => $subjectEntries
                ->groupBy(fn (TimetableEntry $entry): string => $entry->coverage_type ?? '')
                ->flatMap(function (Collection $componentEntries, string $coverageType): array {
                    $prefix = match ($coverageType) {
                        'lecture' => 'Лекции: ',
                        'exercise' => 'Упражнения: ',
                        default => '',
                    };

                    return collect($this->teacherNames($componentEntries->pluck('teacher')->filter()))
                        ->map(fn (string $name): string => $prefix.$name)
                        ->all();
                })
                ->unique()
                ->values()
                ->all())
            ->all();
    }

    /**
     * @param  Collection<int, Teacher>  $teachers
     * @return array<int, string>
     */
    private function teacherNames(Collection $teachers): array
    {
        return $teachers->unique('id')
            ->map(fn (Teacher $teacher): string => trim(($teacher->title ? $teacher->title.' ' : '').$teacher->first_name.' '.$teacher->last_name))
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, TimetableEntry>
     */
    private function entriesForGroup(StudentGroup $group, string $academicYear, string $semesterType): Collection
    {
        return TimetableEntry::query()
            ->with(['subject', 'teacher', 'room', 'studentFlow'])
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
            ->get();
    }

    private function defaultAcademicYear(): string
    {
        $year = now()->year;

        return $year.'/'.($year + 1);
    }

    /**
     * @param  array<int, array<string, mixed>>  $before
     * @param  array<int, array<string, mixed>>  $after
     * @return array{added: array<int, array<string, mixed>>, removed: array<int, array<string, mixed>>, changed: array<int, array{before: array<string, mixed>, after: array<string, mixed>}>}
     */
    private function compareSnapshots(array $before, array $after): array
    {
        $keyForEntry = fn (array $entry): string => 'entry-'.$entry['entry_id'];
        $beforeBySubjectWeek = collect($before)->keyBy($keyForEntry);
        $afterBySubjectWeek = collect($after)->keyBy($keyForEntry);
        $changes = ['added' => [], 'removed' => [], 'changed' => []];
        $comparedFields = ['weekday_no', 'start_time', 'end_time', 'teacher_id', 'room_id', 'custom_subject_name', 'note', 'duration_hours', 'coverage_type'];

        foreach ($afterBySubjectWeek as $key => $entry) {
            if (! $beforeBySubjectWeek->has($key)) {
                $changes['added'][] = $entry;

                continue;
            }

            $previous = $beforeBySubjectWeek->get($key);
            $hasChanged = collect($comparedFields)->contains(
                fn (string $field): bool => ($previous[$field] ?? null) !== ($entry[$field] ?? null),
            );

            if ($hasChanged) {
                $changes['changed'][] = ['before' => $previous, 'after' => $entry];
            }
        }

        foreach ($beforeBySubjectWeek as $key => $entry) {
            if (! $afterBySubjectWeek->has($key)) {
                $changes['removed'][] = $entry;
            }
        }

        return $changes;
    }
}
