<?php

namespace App\Http\Controllers;

use App\Models\Room;
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
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'group_id' => ['nullable', 'integer', 'exists:student_groups,id'],
            'academic_year' => ['nullable', 'string', 'max:20'],
            'semester_type' => ['nullable', 'in:winter,summer'],
            'cycle_week' => ['nullable', 'integer', 'between:1,3'],
        ]);

        $groups = StudentGroup::query()
            ->with(['specialty', 'curriculumVersion'])
            ->orderBy('specialty_id')
            ->orderBy('course_year')
            ->orderBy('group_name')
            ->get();

        $selectedGroup = isset($filters['group_id'])
            ? $groups->firstWhere('id', (int) $filters['group_id'])
            : $groups->first();
        $academicYear = $filters['academic_year'] ?? $this->defaultAcademicYear();
        $semesterType = $filters['semester_type'] ?? 'winter';
        $cycleWeek = (int) ($filters['cycle_week'] ?? 1);
        $entries = collect();

        if ($selectedGroup !== null) {
            $entries = TimetableEntry::query()
                ->with(['subject', 'teacher', 'room'])
                ->where('group_id', $selectedGroup->id)
                ->where('academic_year', $academicYear)
                ->where('semester_type', $semesterType)
                ->where('cycle_week', $cycleWeek)
                ->orderBy('weekday_no')
                ->orderBy('start_time')
                ->get();
        }

        return view('schedules.index', [
            'groups' => $groups,
            'selectedGroup' => $selectedGroup,
            'academicYear' => $academicYear,
            'semesterType' => $semesterType,
            'cycleWeek' => $cycleWeek,
            'entries' => $entries,
            'teachers' => Teacher::query()->where('active', true)->orderBy('last_name')->get(),
            'rooms' => Room::query()->where('is_active', true)->orderBy('name')->get(),
            'blocks' => ScheduleService::BLOCKS,
            'weekdays' => ScheduleService::WEEKDAYS,
            'unscheduled' => session('unscheduled', []),
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

        return redirect()->route('schedules.index', [
            'group_id' => $group->id,
            'academic_year' => $data['academic_year'],
            'semester_type' => $data['semester_type'],
        ])->with([
            'status' => 'Генерирани са '.count($result['entries']).' занятия.',
            'unscheduled' => $result['unscheduled'],
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
            'block' => ['required', 'integer', 'between:0,3'],
            'cycle_week' => ['required', 'integer', 'between:1,3'],
            'weekday_no' => ['required', 'integer', 'between:1,5'],
            'teacher_id' => ['nullable', 'integer', 'exists:teachers,id'],
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'locked' => ['sometimes', 'boolean'],
        ]);

        $data['teacher_id'] = isset($data['teacher_id']) ? (int) $data['teacher_id'] : null;
        $data['room_id'] = (int) $data['room_id'];
        $data['locked'] = $request->boolean('locked');

        $scheduleService->moveEntry($entry, $data);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Занятието е преместено.']);
        }

        return redirect()->route('schedules.index', $request->only([
            'group_id',
            'academic_year',
            'semester_type',
            'cycle_week',
        ]))->with('status', 'Промените са запазени.');
    }

    public function destroy(Request $request, TimetableEntry $entry, ScheduleService $scheduleService): RedirectResponse
    {
        if ($entry->locked) {
            throw ValidationException::withMessages([
                'schedule' => 'Отключете занятието, преди да го премахнете.',
            ]);
        }

        $group = $entry->group;
        $academicYear = $entry->academic_year;
        $semesterType = $entry->semester_type;
        $entry->delete();
        $scheduleService->recordRevision($group, $academicYear, $semesterType, 'Ръчно премахване');

        return redirect()->route('schedules.index', $request->only([
            'group_id',
            'academic_year',
            'semester_type',
            'cycle_week',
        ]))->with('status', 'Занятието е премахнато.');
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
            'weekdays' => ScheduleService::WEEKDAYS,
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

        return ResponseFacade::streamDownload(function () use ($entries, $weekdays): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Седмица', 'Ден', 'Начало', 'Край', 'Дисциплина', 'Преподавател', 'Зала'], ';');

            foreach ($entries as $entry) {
                fputcsv($output, [
                    $entry->cycle_week,
                    $weekdays[$entry->weekday_no] ?? $entry->weekday_no,
                    substr($entry->start_time, 0, 5),
                    substr($entry->end_time, 0, 5),
                    $entry->subject->name,
                    trim(($entry->teacher?->title ? $entry->teacher->title.' ' : '').($entry->teacher?->first_name ?? '').' '.($entry->teacher?->last_name ?? '')),
                    $entry->room?->name ?? '',
                ], ';');
            }

            fclose($output);
        }, 'raspisanie-grupa-'.$group->id.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return Collection<int, TimetableEntry>
     */
    private function entriesForGroup(StudentGroup $group, string $academicYear, string $semesterType): Collection
    {
        return TimetableEntry::query()
            ->with(['subject', 'teacher', 'room'])
            ->where('group_id', $group->id)
            ->where('academic_year', $academicYear)
            ->where('semester_type', $semesterType)
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
        $beforeBySubjectWeek = collect($before)->keyBy(fn (array $entry): string => $entry['subject_id'].'-'.$entry['cycle_week']);
        $afterBySubjectWeek = collect($after)->keyBy(fn (array $entry): string => $entry['subject_id'].'-'.$entry['cycle_week']);
        $changes = ['added' => [], 'removed' => [], 'changed' => []];
        $comparedFields = ['weekday_no', 'start_time', 'end_time', 'teacher_id', 'room_id'];

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
