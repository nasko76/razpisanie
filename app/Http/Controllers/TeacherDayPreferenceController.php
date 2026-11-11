<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use App\Models\TeacherDayPreference;
use App\Models\TimetableEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TeacherDayPreferenceController extends Controller
{
    public function update(Request $request, Teacher $teacher): RedirectResponse
    {
        $data = $request->validate([
            'preferences' => ['required', 'array', 'size:3'],
            'preferences.*' => ['required', 'array', 'size:7'],
            'preferences.*.*' => ['required', Rule::in(['preferred', 'neutral', 'unavailable'])],
        ]);

        $conflictingEntries = collect();
        foreach ($data['preferences'] as $cycleWeek => $weekPreferences) {
            foreach ($weekPreferences as $weekday => $preference) {
                if ($preference !== 'unavailable') {
                    continue;
                }

                $currentPreference = TeacherDayPreference::query()
                    ->where('teacher_id', $teacher->id)
                    ->where('cycle_week', (int) $cycleWeek)
                    ->where('weekday_no', (int) $weekday)
                    ->value('preference');
                if ($currentPreference === 'unavailable') {
                    continue;
                }

                $conflictingEntries = $conflictingEntries->merge(
                    TimetableEntry::query()
                        ->with('subject')
                        ->where('teacher_id', $teacher->id)
                        ->where('cycle_week', (int) $cycleWeek)
                        ->where('weekday_no', (int) $weekday)
                        ->get(),
                );
            }
        }

        DB::transaction(function () use ($teacher, $data): void {
            foreach ($data['preferences'] as $cycleWeek => $weekPreferences) {
                if (! in_array((int) $cycleWeek, [1, 2, 3], true)) {
                    abort(422, 'Избрана е невалидна седмица от цикъла.');
                }

                foreach ($weekPreferences as $weekday => $preference) {
                    if (! in_array((int) $weekday, range(1, 7), true)) {
                        abort(422, 'Избран е невалиден ден от седмицата.');
                    }

                    TeacherDayPreference::query()->updateOrCreate(
                        [
                            'teacher_id' => $teacher->id,
                            'cycle_week' => (int) $cycleWeek,
                            'weekday_no' => (int) $weekday,
                        ],
                        ['preference' => $preference],
                    );
                }
            }
        });

        $redirect = redirect()
            ->route('catalog.teachers.show', $teacher)
            ->with('status', 'Предпочитаните дни за трите седмици са обновени.');

        if ($conflictingEntries->isNotEmpty()) {
            $subjects = $conflictingEntries
                ->map(fn (TimetableEntry $entry): string => $entry->subject?->name ?? $entry->custom_subject_name ?? 'Занятие без име')
                ->unique()
                ->take(5)
                ->join(', ');
            $extraCount = max(0, $conflictingEntries->unique('id')->count() - 5);
            $message = 'Новата настройка „Не“ засяга '.$conflictingEntries->unique('id')->count()
                .' вече поставени занятия ('.$subjects.($extraCount > 0 ? ', и още '.$extraCount : '').'). Те не са преместени автоматично; администраторът трябва да ги размести.';

            $redirect->with('warning', $message);
        }

        return $redirect;
    }
}
