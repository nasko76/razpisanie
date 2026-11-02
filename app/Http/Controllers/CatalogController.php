<?php

namespace App\Http\Controllers;

use App\Models\CurriculumSubject;
use App\Models\CurriculumVersion;
use App\Models\Room;
use App\Models\Specialty;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CatalogController extends Controller
{
    public function index(): View
    {
        return view('admin.catalog', [
            'specialties' => Specialty::query()->withCount('curriculumVersions')->orderBy('name')->get(),
            'curriculumVersions' => CurriculumVersion::query()->with('specialty')->withCount('subjects')->orderByDesc('version_year')->get(),
            'groups' => StudentGroup::query()->with(['specialty', 'curriculumVersion'])->orderBy('course_year')->orderBy('group_name')->get(),
            'subjects' => Subject::query()->withCount('curriculumSubjects')->orderBy('name')->get(),
            'curriculumSubjects' => CurriculumSubject::query()->with(['curriculumVersion.specialty', 'subject'])->orderBy('course_year')->orderBy('semester_no')->get(),
            'teachers' => Teacher::query()->withCount('assignments')->orderBy('last_name')->get(),
            'assignments' => TeacherAssignment::query()->with(['teacher', 'subject', 'curriculumVersion.specialty'])->orderBy('course_year')->get(),
            'rooms' => Room::query()->orderBy('name')->get(),
        ]);
    }

    public function storeSpecialty(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'degree' => ['nullable', 'string', 'max:100'],
        ]);

        Specialty::query()->create($data);

        return $this->saved('Специалността е добавена.');
    }

    public function updateSpecialty(Request $request, Specialty $specialty): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'degree' => ['nullable', 'string', 'max:100'],
        ]);

        $specialty->update($data);

        return $this->saved('Специалността е обновена.');
    }

    public function storeCurriculumVersion(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'specialty_id' => ['required', 'exists:specialties,id'],
            'name' => ['required', 'string', 'max:255'],
            'version_year' => ['required', 'integer', 'min:2000', 'max:2200'],
            'active_from' => ['nullable', 'date'],
            'active_to' => ['nullable', 'date', 'after_or_equal:active_from'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($data): void {
            $isActive = (bool) ($data['is_active'] ?? false);

            if ($isActive) {
                CurriculumVersion::query()
                    ->where('specialty_id', $data['specialty_id'])
                    ->update(['is_active' => false]);
            }

            CurriculumVersion::query()->create([
                ...$data,
                'is_active' => $isActive,
            ]);
        });

        return $this->saved('Създадена е нова версия на учебния план.');
    }

    public function updateCurriculumVersion(Request $request, CurriculumVersion $curriculumVersion): RedirectResponse
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($curriculumVersion, $data): void {
            if ($data['is_active']) {
                CurriculumVersion::query()
                    ->where('specialty_id', $curriculumVersion->specialty_id)
                    ->whereKeyNot($curriculumVersion->id)
                    ->update(['is_active' => false]);
            }

            $curriculumVersion->update(['is_active' => (bool) $data['is_active']]);
        });

        return $this->saved($data['is_active'] ? 'Версията е активирана.' : 'Версията е архивирана.');
    }

    public function storeGroup(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'specialty_id' => ['required', 'exists:specialties,id'],
            'curriculum_version_id' => [
                'required',
                Rule::exists('curriculum_versions', 'id')->where('specialty_id', $request->input('specialty_id')),
            ],
            'course_year' => ['required', 'integer', 'between:1,6'],
            'group_name' => ['required', 'string', 'max:100'],
            'student_count' => ['required', 'integer', 'min:1', 'max:500'],
        ]);

        StudentGroup::query()->create($data);

        return $this->saved('Групата е добавена и свързана с избраната версия на плана.');
    }

    public function updateGroup(Request $request, StudentGroup $group): RedirectResponse
    {
        $data = $request->validate([
            'group_name' => ['required', 'string', 'max:100'],
            'student_count' => ['required', 'integer', 'min:1', 'max:500'],
        ]);

        $group->update($data);

        return $this->saved('Данните за групата са обновени.');
    }

    public function storeSubject(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['lecture', 'exercise', 'atelier', 'practice', 'selfstudy', 'stream_lecture'])],
            'default_hours' => ['required', 'integer', 'min:0', 'max:40'],
        ]);

        Subject::query()->create($data);

        return $this->saved('Дисциплината е добавена.');
    }

    public function updateSubject(Request $request, Subject $subject): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['lecture', 'exercise', 'atelier', 'practice', 'selfstudy', 'stream_lecture'])],
            'default_hours' => ['required', 'integer', 'min:0', 'max:40'],
        ]);

        $subject->update($data);

        return $this->saved('Дисциплината е обновена.');
    }

    public function storeCurriculumSubject(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'curriculum_version_id' => ['required', 'exists:curriculum_versions,id'],
            'subject_id' => ['required', 'exists:subjects,id'],
            'semester_no' => ['required', 'integer', 'between:1,12'],
            'course_year' => ['required', 'integer', 'between:1,6'],
            'contact_hours' => ['required', 'integer', 'min:0', 'max:1000'],
            'self_study_hours' => ['required', 'integer', 'min:0', 'max:1000'],
            'scheduled_hours' => ['required', 'integer', 'min:0', 'max:1000'],
            'cycle_weeks' => ['required', 'regex:/^[1-3](,[1-3])*$/'],
            'control_type' => ['nullable', 'string', 'max:100'],
        ]);

        $data['cycle_weeks'] = array_values(array_unique(array_map('intval', explode(',', $data['cycle_weeks']))));
        $exists = CurriculumSubject::query()
            ->where('curriculum_version_id', $data['curriculum_version_id'])
            ->where('subject_id', $data['subject_id'])
            ->where('semester_no', $data['semester_no'])
            ->where('course_year', $data['course_year'])
            ->exists();

        if ($exists) {
            return back()->withErrors([
                'subject_id' => 'Тази дисциплина вече е добавена към курса и семестъра на версията.',
            ])->withInput();
        }

        CurriculumSubject::query()->create($data);

        return $this->saved('Дисциплината е добавена към учебния план.');
    }

    public function storeTeacher(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'title' => ['nullable', 'string', 'max:100'],
            'max_daily_hours' => ['required', 'integer', 'between:1,16'],
            'weekly_workload_hours' => ['required', 'integer', 'between:0,80'],
            'preferred_weekdays' => ['nullable', 'regex:/^[1-5](,[1-5])*$/'],
            'unavailable_weekdays' => ['nullable', 'regex:/^[1-5](,[1-5])*$/'],
            'unavailable_periods' => ['nullable', 'regex:/^[1-3]\/[1-5]\/(?:[01]\d|2[0-3]):[0-5]\d-(?:[01]\d|2[0-3]):[0-5]\d(?:;[1-3]\/[1-5]\/(?:[01]\d|2[0-3]):[0-5]\d-(?:[01]\d|2[0-3]):[0-5]\d)*$/'],
        ]);

        $data['preferred_weekdays'] = $this->parseIntegerList($data['preferred_weekdays'] ?? null);
        $data['unavailable_weekdays'] = $this->parseIntegerList($data['unavailable_weekdays'] ?? null);
        $data['unavailable_periods'] = $this->parsePeriods($data['unavailable_periods'] ?? null);
        $data['active'] = true;
        Teacher::query()->create($data);

        return $this->saved('Преподавателят е добавен.');
    }

    public function updateTeacher(Request $request, Teacher $teacher): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'title' => ['nullable', 'string', 'max:100'],
            'max_daily_hours' => ['required', 'integer', 'between:1,16'],
            'weekly_workload_hours' => ['required', 'integer', 'between:0,80'],
            'preferred_weekdays' => ['nullable', 'regex:/^[1-5](,[1-5])*$/'],
            'unavailable_weekdays' => ['nullable', 'regex:/^[1-5](,[1-5])*$/'],
            'unavailable_periods' => ['nullable', 'regex:/^[1-3]\/[1-5]\/(?:[01]\d|2[0-3]):[0-5]\d-(?:[01]\d|2[0-3]):[0-5]\d(?:;[1-3]\/[1-5]\/(?:[01]\d|2[0-3]):[0-5]\d-(?:[01]\d|2[0-3]):[0-5]\d)*$/'],
            'active' => ['required', 'boolean'],
        ]);

        $data['preferred_weekdays'] = $this->parseIntegerList($data['preferred_weekdays'] ?? null);
        $data['unavailable_weekdays'] = $this->parseIntegerList($data['unavailable_weekdays'] ?? null);
        $data['unavailable_periods'] = $this->parsePeriods($data['unavailable_periods'] ?? null);
        $teacher->update($data);

        return $this->saved('Данните за преподавателя са обновени.');
    }

    public function storeTeacherAssignment(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'teacher_id' => ['required', 'exists:teachers,id'],
            'subject_id' => ['required', 'exists:subjects,id'],
            'curriculum_version_id' => ['required', 'exists:curriculum_versions,id'],
            'course_year' => ['required', 'integer', 'between:1,6'],
            'semester' => ['nullable', 'in:winter,summer'],
        ]);

        TeacherAssignment::query()->firstOrCreate($data);

        return $this->saved('Преподавателската обезпеченост е записана.');
    }

    public function storeRoom(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'room_type' => ['required', Rule::in(['lecture', 'atelier', 'computer', 'painting', 'graphics', 'studio'])],
            'capacity' => ['required', 'integer', 'min:1', 'max:1000'],
            'equipment' => ['nullable', 'string', 'max:500'],
            'unavailable_periods' => ['nullable', 'regex:/^[1-3]\/[1-5]\/(?:[01]\d|2[0-3]):[0-5]\d-(?:[01]\d|2[0-3]):[0-5]\d(?:;[1-3]\/[1-5]\/(?:[01]\d|2[0-3]):[0-5]\d-(?:[01]\d|2[0-3]):[0-5]\d)*$/'],
        ]);

        $data['equipment'] = $this->parseStringList($data['equipment'] ?? null);
        $data['unavailable_periods'] = $this->parsePeriods($data['unavailable_periods'] ?? null);
        $data['is_active'] = true;
        Room::query()->create($data);

        return $this->saved('Залата е добавена.');
    }

    public function updateRoom(Request $request, Room $room): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'room_type' => ['required', Rule::in(['lecture', 'atelier', 'computer', 'painting', 'graphics', 'studio'])],
            'capacity' => ['required', 'integer', 'min:1', 'max:1000'],
            'equipment' => ['nullable', 'string', 'max:500'],
            'unavailable_periods' => ['nullable', 'regex:/^[1-3]\/[1-5]\/(?:[01]\d|2[0-3]):[0-5]\d-(?:[01]\d|2[0-3]):[0-5]\d(?:;[1-3]\/[1-5]\/(?:[01]\d|2[0-3]):[0-5]\d-(?:[01]\d|2[0-3]):[0-5]\d)*$/'],
            'is_active' => ['required', 'boolean'],
        ]);

        $data['equipment'] = $this->parseStringList($data['equipment'] ?? null);
        $data['unavailable_periods'] = $this->parsePeriods($data['unavailable_periods'] ?? null);
        $room->update($data);

        return $this->saved('Данните за залата са обновени.');
    }

    private function saved(string $message): RedirectResponse
    {
        return redirect()->route('catalog.index')->with('status', $message);
    }

    /**
     * @return array<int, int>|null
     */
    private function parseIntegerList(?string $value): ?array
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return array_values(array_unique(array_map('intval', explode(',', $value))));
    }

    /**
     * @return array<int, string>|null
     */
    private function parseStringList(?string $value): ?array
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    /**
     * @return array<int, array{cycle_week: int, weekday_no: int, start_time: string, end_time: string}>|null
     */
    private function parsePeriods(?string $value): ?array
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $periods = [];

        foreach (explode(';', $value) as $period) {
            preg_match('/^([1-3])\/([1-5])\/(\d{2}:\d{2})-(\d{2}:\d{2})$/', $period, $matches);

            if ($matches[3] >= $matches[4]) {
                throw ValidationException::withMessages([
                    'unavailable_periods' => 'Крайният час трябва да е след началния час.',
                ]);
            }

            $periods[] = [
                'cycle_week' => (int) $matches[1],
                'weekday_no' => (int) $matches[2],
                'start_time' => $matches[3],
                'end_time' => $matches[4],
            ];
        }

        return $periods;
    }
}
