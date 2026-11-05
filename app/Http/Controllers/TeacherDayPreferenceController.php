<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use App\Models\TeacherDayPreference;
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

        return redirect()
            ->route('catalog.teachers.show', $teacher)
            ->with('status', 'Предпочитаните дни за трите седмици са обновени.');
    }
}
