<?php

namespace App\Http\Controllers;

use App\Models\CurriculumSubject;
use App\Models\CurriculumVersion;
use App\Models\Room;
use App\Models\Specialty;
use App\Models\StudentFlow;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAbsence;
use App\Models\TeacherAssignment;
use App\Models\TimetableEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class CatalogController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $isDepartmentHead = $user?->hasRole('department_head') && ! $user->hasRole('admin');
        abort_unless(! $isDepartmentHead || filled($user->department), 403);
        $activeTab = $request->string('tab')->toString();
        $activeTab = $isDepartmentHead
            ? 'teachers'
            : (in_array($activeTab, ['plans', 'subjects', 'teachers', 'organization'], true)
            ? $activeTab
            : 'plans');
        $subjectSort = in_array($request->query('subject_sort'), ['name', 'auditorium_hours', 'code'], true)
            ? $request->query('subject_sort')
            : 'name';
        $subjectCurriculumFilter = in_array($request->query('subject_in_curriculum'), ['included', 'not_included'], true)
            ? $request->query('subject_in_curriculum')
            : '';
        $subjectPlanId = $request->integer('subject_plan_id');
        $teacherPlanId = $isDepartmentHead ? 0 : $request->integer('teacher_plan_id');
        $teacherDepartmentFilter = $isDepartmentHead
            ? $user->department
            : $request->query('teacher_department');
        $subjectsQuery = Subject::query()
            ->with([
                'curriculumSubjects.curriculumVersion.specialty',
                'curriculumSubjects.subject',
            ])
            ->withCount('curriculumSubjects');
        if ($subjectPlanId > 0) {
            $subjectsQuery->whereHas('curriculumSubjects', fn ($query) => $query->where('curriculum_version_id', $subjectPlanId));
        } elseif ($subjectCurriculumFilter === 'included') {
            $subjectsQuery->has('curriculumSubjects');
        } elseif ($subjectCurriculumFilter === 'not_included') {
            $subjectsQuery->doesntHave('curriculumSubjects');
        }
        if ($subjectSort === 'auditorium_hours') {
            $subjectsQuery
                ->orderByDesc(CurriculumSubject::query()
                    ->selectRaw('MAX(auditorium_hours)')
                    ->whereColumn('curriculum_subjects.subject_id', 'subjects.id')
                    ->when($subjectPlanId > 0, fn ($query) => $query->where('curriculum_version_id', $subjectPlanId)))
                ->orderBy('name');
        } else {
            $subjectsQuery->orderBy($subjectSort)->orderBy('name');
        }
        $teachersQuery = Teacher::query()->with('absences')->withCount('assignments')->orderBy('last_name');
        if (filled($teacherDepartmentFilter)) {
            $teachersQuery->where('department', $teacherDepartmentFilter);
        }
        if ($teacherPlanId > 0) {
            $teachersQuery->where(function ($query) use ($teacherPlanId): void {
                $query->whereHas('assignments', fn ($assignments) => $assignments->where('curriculum_version_id', $teacherPlanId))
                    ->orWhereHas('assignments.studentGroup', fn ($group) => $group->where('curriculum_version_id', $teacherPlanId))
                    ->orWhereHas('assignments.studentFlow.groups', fn ($group) => $group->where('curriculum_version_id', $teacherPlanId));
            });
        }

        return view('admin.catalog', [
            'specialties' => Specialty::query()->withCount('curriculumVersions')->orderBy('name')->get(),
            'curriculumVersions' => CurriculumVersion::query()->with('specialty')->withCount('subjects')->orderByDesc('version_year')->get(),
            'groups' => StudentGroup::query()
                ->with(['specialty', 'curriculumVersion.subjects'])
                ->orderBy(Specialty::query()->select('name')->whereColumn('specialties.id', 'student_groups.specialty_id'))
                ->orderBy('course_year')
                ->orderBy('group_name')
                ->get(),
            'subjects' => $subjectsQuery->get(),
            'teachers' => $teachersQuery->get(),
            'rooms' => Room::query()->orderBy('name')->get(),
            'studentFlows' => StudentFlow::query()
                ->with([
                    'groups' => fn ($query) => $query
                        ->with('specialty')
                        ->orderBy(Specialty::query()->select('name')->whereColumn('specialties.id', 'student_groups.specialty_id'))
                        ->orderBy('course_year')
                        ->orderBy('group_name'),
                    'subject',
                ])
                ->withCount('groups')
                ->orderBy('name')
                ->orderBy('id')
                ->get(),
            'activeTab' => $activeTab,
            'subjectSort' => $subjectSort,
            'subjectCurriculumFilter' => $subjectCurriculumFilter,
            'subjectPlanId' => $subjectPlanId,
            'teacherPlanId' => $teacherPlanId,
            'teacherDepartmentFilter' => $teacherDepartmentFilter,
            'teacherDepartments' => Teacher::query()
                ->whereNotNull('department')
                ->where('department', '!=', '')
                ->distinct()
                ->orderBy('department')
                ->pluck('department'),
            'isDepartmentHead' => (bool) $isDepartmentHead,
            'users' => $isDepartmentHead ? collect() : User::query()->orderBy('name')->get(),
        ]);
    }

    public function updateDepartmentHead(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'is_department_head' => ['required', 'boolean'],
            'department' => ['nullable', 'string', 'max:255'],
        ]);
        $user = User::query()->findOrFail($data['user_id']);

        if ($data['is_department_head']) {
            $departments = Teacher::query()
                ->whereNotNull('department')
                ->where('department', '!=', '')
                ->distinct()
                ->pluck('department')
                ->all();
            validator($data, [
                'department' => ['required', Rule::in($departments)],
            ])->validate();

            $user->forceFill(['department' => $data['department']])->save();
            $user->assignRole(Role::findOrCreate('department_head', 'web'));
        } else {
            $user->removeRole('department_head');
            if (! $user->hasRole('admin')) {
                $user->forceFill(['department' => null])->save();
            }
        }

        return $this->saved('Достъпът на ръководителя на катедра е обновен.', 'teachers');
    }

    public function coverageReport(Request $request): View
    {
        $filters = $request->validate([
            'curriculum_version_id' => ['nullable', 'integer', 'exists:curriculum_versions,id'],
            'student_group_id' => ['nullable', 'integer', 'exists:student_groups,id'],
            'academic_year' => ['nullable', 'string', 'max:20'],
            'semester_type' => ['nullable', 'in:winter,summer'],
        ]);
        $academicYear = $filters['academic_year'] ?? now()->year.'/'.(now()->year + 1);
        $semesterType = $filters['semester_type'] ?? 'winter';
        $curriculumVersionId = isset($filters['curriculum_version_id']) ? (int) $filters['curriculum_version_id'] : null;
        $studentGroupId = isset($filters['student_group_id']) ? (int) $filters['student_group_id'] : null;

        $rows = TeacherAssignment::query()
            ->with(['teacher', 'subject', 'curriculumVersion.specialty', 'studentGroup.specialty', 'studentFlow.groups.specialty'])
            ->where('semester', $semesterType)
            ->where(fn ($query) => $query->whereNull('academic_year')->orWhere('academic_year', $academicYear))
            ->when($curriculumVersionId !== null, fn ($query) => $query->where(function ($scope) use ($curriculumVersionId): void {
                $scope->where('curriculum_version_id', $curriculumVersionId)
                    ->orWhereHas('studentGroup', fn ($group) => $group->where('curriculum_version_id', $curriculumVersionId))
                    ->orWhereHas('studentFlow.groups', fn ($group) => $group->where('curriculum_version_id', $curriculumVersionId));
            }))
            ->when($studentGroupId !== null, fn ($query) => $query->where(function ($scope) use ($studentGroupId): void {
                $scope->where('student_group_id', $studentGroupId)
                    ->orWhereHas('studentFlow.groups', fn ($group) => $group->whereKey($studentGroupId));
            }))
            ->get()
            ->map(function (TeacherAssignment $assignment) use ($academicYear, $semesterType, $curriculumVersionId, $studentGroupId): array {
                $coverageAliases = in_array($assignment->coverage_type, ['lecture', 'Лекции', 'лекции', 'Лекция', 'лекция'], true)
                    ? ['lecture', 'Лекции', 'лекции', 'Лекция', 'лекция']
                    : ['exercise', 'Упражнения', 'упражнения', 'Упражнение', 'упражнение'];
                $entries = TimetableEntry::query()
                    ->where('subject_id', $assignment->subject_id)
                    ->where('teacher_id', $assignment->teacher_id)
                    ->whereIn('coverage_type', $coverageAliases)
                    ->where('academic_year', $academicYear)
                    ->where('semester_type', $semesterType)
                    ->where('is_in_pool', false)
                    ->when($assignment->student_flow_id !== null, fn ($query) => $query->where('student_flow_id', $assignment->student_flow_id))
                    ->when($assignment->student_group_id !== null, fn ($query) => $query->where('group_id', $assignment->student_group_id))
                    ->when($assignment->student_flow_id === null && $assignment->student_group_id === null && $assignment->curriculum_version_id !== null, fn ($query) => $query
                        ->whereHas('group', fn ($group) => $group
                            ->where('curriculum_version_id', $assignment->curriculum_version_id)
                            ->when($assignment->course_year !== null, fn ($course) => $course->where('course_year', $assignment->course_year))))
                    ->when($studentGroupId !== null, fn ($query) => $query->where(function ($scope) use ($studentGroupId): void {
                        $scope->where('group_id', $studentGroupId)
                            ->orWhereHas('studentFlow.groups', fn ($group) => $group->whereKey($studentGroupId));
                    }))
                    ->when($curriculumVersionId !== null, fn ($query) => $query->where(function ($scope) use ($curriculumVersionId): void {
                        $scope->whereHas('group', fn ($group) => $group->where('curriculum_version_id', $curriculumVersionId))
                            ->orWhereHas('studentFlow.groups', fn ($group) => $group->where('curriculum_version_id', $curriculumVersionId));
                    }))
                    ->get(['duration_hours']);
                $plannedHours = (int) ($assignment->planned_hours ?? 0);
                $scheduledHours = (int) ($entries->sum('duration_hours') * 5);

                return [
                    'assignment' => $assignment,
                    'planned_hours' => $plannedHours,
                    'scheduled_hours' => $scheduledHours,
                    'remaining_hours' => max(0, $plannedHours - $scheduledHours),
                    'excess_hours' => max(0, $scheduledHours - $plannedHours),
                    'sessions' => $entries->count(),
                ];
            });

        return view('admin.coverage-report', [
            'rows' => $rows,
            'curriculumVersions' => CurriculumVersion::query()->with('specialty')->orderByDesc('version_year')->get(),
            'groups' => StudentGroup::query()
                ->with('specialty')
                ->orderBy(Specialty::query()->select('name')->whereColumn('specialties.id', 'student_groups.specialty_id'))
                ->orderBy('course_year')
                ->orderBy('group_name')
                ->get(),
            'curriculumVersionId' => $curriculumVersionId,
            'studentGroupId' => $studentGroupId,
            'academicYear' => $academicYear,
            'semesterType' => $semesterType,
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

    public function destroySpecialty(Specialty $specialty): RedirectResponse
    {
        $specialty->delete();
        $this->deleteInvalidStudentFlows();

        return $this->saved('Специалността и свързаните с нея планове и групи са изтрити.', 'plans');
    }

    public function storeCurriculumVersion(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'specialty_id' => ['required', 'exists:specialties,id'],
            'name' => ['required', 'string', 'max:255'],
            'version_year' => ['required', 'integer', 'min:2000', 'max:2200'],
            'active_from' => ['nullable', 'date'],
            'active_to' => ['nullable', 'date', 'after_or_equal:active_from'],
            'current_semester' => ['sometimes', 'integer', 'between:1,4'],
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

    public function updateCurriculumVersionDetails(Request $request, CurriculumVersion $curriculumVersion): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'version_year' => ['required', 'integer', 'min:2000', 'max:2200'],
            'active_from' => ['nullable', 'date'],
            'active_to' => ['nullable', 'date', 'after_or_equal:active_from'],
            'current_semester' => ['sometimes', 'integer', 'between:1,4'],
        ]);
        $curriculumVersion->update($data);

        return redirect()->route('catalog.curriculum-versions.show', $curriculumVersion)
            ->with('status', 'Данните за учебния план са обновени.');
    }

    public function advanceCurriculumSemester(CurriculumVersion $curriculumVersion): RedirectResponse
    {
        $status = DB::transaction(function () use ($curriculumVersion): string {
            $version = CurriculumVersion::query()
                ->lockForUpdate()
                ->findOrFail($curriculumVersion->id);

            if ($version->current_semester < 4) {
                $version->increment('current_semester');

                return 'Текущият семестър е преместен към '.$version->current_semester.'.';
            }

            if ($version->is_active) {
                $version->update(['is_active' => false]);

                return 'Версията е в четвърти семестър и е архивирана.';
            }

            return 'Версията вече е архивирана.';
        });

        return redirect()->route('catalog.curriculum-versions.show', $curriculumVersion)
            ->with('status', $status);
    }

    public function destroyCurriculumVersion(CurriculumVersion $curriculumVersion): RedirectResponse
    {
        $curriculumVersion->delete();

        return $this->saved('Версията е изтрита; свързаните курсове остават без учебен план.', 'plans');
    }

    public function duplicateCurriculumVersion(CurriculumVersion $curriculumVersion): RedirectResponse
    {
        DB::transaction(function () use ($curriculumVersion): void {
            $copy = $curriculumVersion->replicate();
            $copy->name = $curriculumVersion->name.' — копие';
            $copy->is_active = false;
            $copy->save();
            $copy->subjects()->createMany(
                $curriculumVersion->subjects()->get()->map(fn (CurriculumSubject $subject): array => [
                    'subject_id' => $subject->subject_id,
                    'course_year' => $subject->course_year,
                    'semester_no' => $subject->semester_no,
                    'source_data' => $subject->source_data,
                    'subject_category' => $subject->subject_category,
                    'is_selected' => $subject->is_selected,
                ])->all(),
            );
        });

        return redirect()->route('catalog.index', ['tab' => 'plans'])
            ->with('status', 'Копие на учебния план е създадено.');
    }

    public function storeCurriculumSubject(Request $request, CurriculumVersion $curriculumVersion): RedirectResponse
    {
        $data = $request->validate([
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'course_year' => ['required', 'integer', 'between:1,6'],
            'semester_no' => ['required', 'integer', 'between:1,12'],
        ]);
        CurriculumSubject::query()->firstOrCreate([
            'curriculum_version_id' => $curriculumVersion->id,
            ...$data,
        ]);

        return redirect()->route('catalog.curriculum-versions.show', $curriculumVersion)
            ->with('status', 'Дисциплината е добавена към учебния план.');
    }

    public function updateCurriculumSubject(Request $request, CurriculumSubject $curriculumSubject): RedirectResponse
    {
        $data = $request->validate([
            'course_year' => ['required', 'integer', 'between:1,6'],
            'semester_no' => ['required', 'integer', 'between:1,12'],
            'subject_category' => ['required', Rule::in(['mandatory', 'elective', 'optional'])],
            'auditorium_hours' => ['nullable', 'integer', 'between:0,1000'],
            'lecture_hours' => ['nullable', 'integer', 'between:0,1000'],
            'exercise_hours' => ['nullable', 'integer', 'between:0,1000'],
            'self_study_hours' => ['nullable', 'integer', 'between:0,1000'],
            'control_type' => ['nullable', Rule::in(['И', 'ТО', 'П'])],
        ]);
        $categoryChanged = $curriculumSubject->subject_category !== $data['subject_category'];
        $data['is_selected'] = match (true) {
            $data['subject_category'] === 'mandatory' => true,
            $categoryChanged => false,
            default => $curriculumSubject->is_selected,
        };
        $curriculumSubject->update($data);

        return redirect()->route('catalog.curriculum-versions.show', $curriculumSubject->curriculum_version_id)
            ->with('status', 'Дисциплината е обновена в учебния план.');
    }

    public function updateCurriculumSubjectSelection(Request $request, CurriculumSubject $curriculumSubject): RedirectResponse
    {
        $data = $request->validate([
            'is_selected' => ['required', 'boolean'],
        ]);
        $curriculumSubject->update(['is_selected' => (bool) $data['is_selected']]);

        return redirect()->route('catalog.curriculum-versions.show', $curriculumSubject->curriculum_version_id)
            ->with('status', $data['is_selected']
                ? 'Дисциплината е включена в генерирането на разписанието.'
                : 'Дисциплината е изключена от генерирането на разписанието.');
    }

    public function destroyCurriculumSubject(CurriculumSubject $curriculumSubject): RedirectResponse
    {
        $versionId = $curriculumSubject->curriculum_version_id;
        $curriculumSubject->delete();

        return redirect()->route('catalog.curriculum-versions.show', $versionId)
            ->with('status', 'Дисциплината е премахната от учебния план.');
    }

    public function storeGroup(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'specialty_id' => ['required', 'exists:specialties,id'],
            'curriculum_version_id' => [
                'nullable',
                'integer',
                Rule::exists('curriculum_versions', 'id')->where('specialty_id', $request->input('specialty_id')),
            ],
            'course_year' => ['required', 'integer', 'between:1,6'],
            'group_name' => ['nullable', 'string', 'max:100'],
            'student_count' => ['required', 'integer', 'min:1', 'max:500'],
        ]);
        $data['group_name'] = filled($data['group_name'] ?? null) ? trim($data['group_name']) : null;

        DB::transaction(function () use ($data): void {
            Specialty::query()->whereKey($data['specialty_id'])->lockForUpdate()->firstOrFail();
            $this->assertUniqueGroup($data);
            StudentGroup::query()->create($data);
        });

        return $this->saved(
            isset($data['curriculum_version_id'])
                ? 'Групата е добавена и свързана с избраната версия на плана.'
                : 'Групата е добавена без учебен план за ръчно разписание.',
            'organization',
        );
    }

    public function updateGroup(Request $request, StudentGroup $group): RedirectResponse
    {
        $data = $request->validate([
            'curriculum_version_id' => [
                'nullable',
                'integer',
                Rule::exists('curriculum_versions', 'id')->where('specialty_id', $group->specialty_id),
            ],
            'group_name' => ['nullable', 'string', 'max:100'],
            'student_count' => ['required', 'integer', 'min:1', 'max:500'],
        ]);
        $data['group_name'] = filled($data['group_name'] ?? null) ? trim($data['group_name']) : null;

        DB::transaction(function () use ($data, $group): void {
            Specialty::query()->whereKey($group->specialty_id)->lockForUpdate()->firstOrFail();
            $this->assertUniqueGroup([
                'specialty_id' => $group->specialty_id,
                'curriculum_version_id' => $data['curriculum_version_id'] ?? $group->curriculum_version_id,
                'course_year' => $group->course_year,
                'group_name' => $data['group_name'] ?? $group->group_name,
            ], $group->id);
            $group->update($data);
        });

        return $this->saved('Данните за групата са обновени.', 'organization');
    }

    public function destroyGroup(StudentGroup $group): RedirectResponse
    {
        $group->delete();
        $this->deleteInvalidStudentFlows();

        return $this->saved('Курсът и групата са изтрити заедно със свързаните занятия.', 'organization');
    }

    public function destroyGroups(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'group_ids' => ['required', 'array', 'min:1'],
            'group_ids.*' => ['required', 'integer', 'distinct', 'exists:student_groups,id'],
        ]);

        DB::transaction(function () use ($data): void {
            StudentGroup::query()->whereKey($data['group_ids'])->get()->each->delete();
            $this->deleteInvalidStudentFlows();
        });

        return $this->saved('Избраните курсове и групи са изтрити заедно със свързаните занятия.', 'organization');
    }

    public function storeSubject(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        Subject::query()->create($data);

        return $this->saved('Дисциплината е добавена.', 'subjects');
    }

    public function updateSubject(Request $request, Subject $subject): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $subject->update($data);

        return $this->saved('Дисциплината е обновена.', 'subjects');
    }

    public function destroySubject(Subject $subject): RedirectResponse
    {
        $subject->delete();

        return $this->saved('Дисциплината е изтрита заедно с включванията ѝ в планове и потоци.', 'subjects');
    }

    public function storeTeacher(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'title' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:255'],
            'max_daily_hours' => ['required', 'integer', 'between:1,10'],
            'weekly_workload_hours' => ['required', 'integer', 'between:0,80'],
        ]);

        $data['active'] = true;
        $teacher = Teacher::query()->create($data);

        return redirect()
            ->route('catalog.teachers.show', $teacher)
            ->with('status', 'Преподавателят е добавен.');
    }

    public function updateTeacher(Request $request, Teacher $teacher): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'title' => ['nullable', 'string', 'max:100'],
            'max_daily_hours' => ['required', 'integer', 'between:1,10'],
            'weekly_workload_hours' => ['required', 'integer', 'between:0,80'],
            'active' => ['required', 'boolean'],
        ]);

        $teacher->update($data);

        return redirect()
            ->route('catalog.teachers.show', $teacher)
            ->with('status', 'Данните за преподавателя са обновени.');
    }

    public function destroyTeacher(Teacher $teacher): RedirectResponse
    {
        $teacher->delete();

        return $this->saved('Преподавателят е изтрит; часовете в разписанията са оставени без преподавател.', 'teachers');
    }

    public function storeTeacherAssignment(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'teacher_id' => ['required', 'exists:teachers,id'],
            'subject_id' => ['required', 'exists:subjects,id'],
            'curriculum_version_id' => ['required', 'exists:curriculum_versions,id'],
            'course_year' => ['required', 'integer', 'between:1,6'],
            'semester' => ['nullable', 'in:winter,summer'],
            'coverage_type' => ['required', Rule::in(['lecture', 'exercise'])],
            'student_flow_id' => ['nullable', 'integer', 'exists:student_flows,id'],
        ]);
        if ($data['coverage_type'] !== 'lecture') {
            $data['student_flow_id'] = null;
        }
        if (isset($data['student_flow_id'])) {
            $this->assertFlowMatchesCurriculum(
                StudentFlow::query()->findOrFail($data['student_flow_id']),
                (int) $data['subject_id'],
                (int) $data['curriculum_version_id'],
                (int) $data['course_year'],
            );
        }

        TeacherAssignment::query()->firstOrCreate($data);

        return redirect()
            ->route('catalog.teachers.show', $data['teacher_id'])
            ->with('status', 'Преподавателската обезпеченост е записана.');
    }

    public function storeStudentFlow(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:student_flows,name'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'student_group_ids' => ['required', 'array', 'min:2'],
            'student_group_ids.*' => ['required', 'integer', 'distinct', 'exists:student_groups,id'],
        ]);
        $this->assertFlowGroupsOfferSubject($data['student_group_ids'], (int) $data['subject_id']);

        $flow = DB::transaction(function () use ($data): StudentFlow {
            $flow = StudentFlow::query()->create([
                'name' => $data['name'],
                'subject_id' => $data['subject_id'],
            ]);
            $flow->groups()->sync($data['student_group_ids']);

            return $flow;
        });

        return redirect()->route('catalog.index', ['tab' => 'organization'])
            ->with('status', 'Учебният поток е създаден.');
    }

    public function updateStudentFlow(Request $request, StudentFlow $studentFlow): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('student_flows', 'name')->ignore($studentFlow->id)],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'student_group_ids' => ['required', 'array', 'min:2'],
            'student_group_ids.*' => ['required', 'integer', 'distinct', 'exists:student_groups,id'],
        ]);
        $subjectId = (int) $data['subject_id'];
        $this->assertFlowGroupsOfferSubject($data['student_group_ids'], $subjectId);
        if (
            $studentFlow->subject_id !== null
            && $studentFlow->subject_id !== $subjectId
            && ($studentFlow->timetableEntries()->exists() || $studentFlow->teacherAssignments()->exists())
        ) {
            throw ValidationException::withMessages([
                'subject_id' => 'Не може да смените дисциплината на поток с вече назначени или планирани занятия.',
            ]);
        }
        DB::transaction(function () use ($studentFlow, $data, $subjectId): void {
            $studentFlow->update([
                'name' => $data['name'],
                'subject_id' => $subjectId,
            ]);
            $studentFlow->groups()->sync($data['student_group_ids']);
        });

        return redirect()->route('catalog.index', ['tab' => 'organization'])
            ->with('status', 'Учебният поток е обновен.');
    }

    private function assertFlowMatchesCurriculum(
        StudentFlow $flow,
        int $subjectId,
        int $curriculumVersionId,
        int $courseYear,
    ): void {
        if (
            (int) $flow->subject_id !== $subjectId
            || ! $flow->groups()
                ->where('student_groups.curriculum_version_id', $curriculumVersionId)
                ->where('student_groups.course_year', $courseYear)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'student_flow_id' => 'Потокът трябва да е за същата дисциплина и да включва избрания курс.',
            ]);
        }
    }

    /**
     * @param  array<int, int|string>  $groupIds
     */
    private function assertFlowGroupsOfferSubject(array $groupIds, int $subjectId): void
    {
        $groups = StudentGroup::query()
            ->with(['specialty', 'curriculumVersion'])
            ->whereIn('id', $groupIds)
            ->get();
        $eligibleGroupIds = StudentGroup::query()
            ->whereIn('id', $groupIds)
            ->whereNotNull('curriculum_version_id')
            ->whereExists(function ($query) use ($subjectId): void {
                $query->selectRaw('1')
                    ->from('curriculum_subjects')
                    ->whereColumn('curriculum_subjects.curriculum_version_id', 'student_groups.curriculum_version_id')
                    ->whereColumn('curriculum_subjects.course_year', 'student_groups.course_year')
                    ->where('curriculum_subjects.subject_id', $subjectId);
            })
            ->pluck('id')
            ->all();

        $groupsWithoutSubject = $groups->whereNotIn('id', $eligibleGroupIds);
        if ($groupsWithoutSubject->isNotEmpty()) {
            $subjectName = Subject::query()->whereKey($subjectId)->value('name');
            $groupDetails = $groupsWithoutSubject
                ->map(function (StudentGroup $group): string {
                    $groupLabel = $group->specialty?->name ?? 'Специалност не е зададена';
                    $groupLabel .= ' · '.$group->course_year.' курс';
                    if ($group->group_name !== null && $group->group_name !== '') {
                        $groupLabel .= ' · група '.$group->group_name;
                    }

                    $planLabel = $group->curriculumVersion === null
                        ? 'няма зададен учебен план'
                        : 'план „'.$group->curriculumVersion->name.'“ ('.$group->curriculumVersion->version_year.')';

                    return $groupLabel.' — '.$planLabel;
                })
                ->implode('; ');

            throw ValidationException::withMessages([
                'student_group_ids' => 'Всички групи в потока трябва да изучават дисциплината „'.$subjectName.'“. Тя не е включена в учебния план за: '.$groupDetails.'. Проверете избраните групи и учебни планове.',
            ]);
        }
    }

    private function deleteInvalidStudentFlows(): void
    {
        StudentFlow::query()
            ->where(function ($query): void {
                $query->whereDoesntHave('groups')
                    ->orWhereHas('groups', null, '<', 2);
            })
            ->delete();
    }

    /**
     * @param  array{specialty_id:int|string, curriculum_version_id:int|string|null, course_year:int, group_name:string|null}  $data
     */
    private function assertUniqueGroup(array $data, ?int $exceptGroupId = null): void
    {
        $curriculumVersionId = $data['curriculum_version_id'] ?? null;
        $groupName = $data['group_name'] ?? null;
        $query = StudentGroup::query()
            ->where('specialty_id', $data['specialty_id'])
            ->where('course_year', $data['course_year'])
            ->when(
                $curriculumVersionId === null,
                fn ($groups) => $groups->whereNull('curriculum_version_id'),
                fn ($groups) => $groups->where('curriculum_version_id', $curriculumVersionId),
            )
            ->when(
                $groupName === null,
                fn ($groups) => $groups->whereNull('group_name'),
                fn ($groups) => $groups->where('group_name', $groupName),
            )
            ->when($exceptGroupId !== null, fn ($groups) => $groups->whereKeyNot($exceptGroupId));

        if ($query->exists()) {
            $field = $groupName === null ? 'course_year' : 'group_name';
            throw ValidationException::withMessages([
                $field => 'Вече съществува курс/група със същата специалност, план, курс и име.',
            ]);
        }
    }

    public function storeTeacherAbsence(Request $request, Teacher $teacher): RedirectResponse
    {
        $data = $request->validate([
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'starts_time' => ['required', 'date_format:H:i'],
            'ends_on' => ['required', 'date_format:Y-m-d'],
            'ends_time' => ['required', 'date_format:H:i'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $startsAt = CarbonImmutable::parse($data['starts_on'].' '.$data['starts_time']);
        $endsAt = CarbonImmutable::parse($data['ends_on'].' '.$data['ends_time']);

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw ValidationException::withMessages([
                'ends_on' => 'Крайният момент трябва да е след началния.',
            ]);
        }

        TeacherAbsence::query()->create([
            'teacher_id' => $teacher->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'reason' => $data['reason'] ?? null,
        ]);

        return redirect()
            ->route('catalog.teachers.show', $teacher)
            ->with('status', 'Отсъствието е добавено.');
    }

    public function destroyTeacherAbsence(Teacher $teacher, TeacherAbsence $absence): RedirectResponse
    {
        abort_unless($absence->teacher_id === $teacher->id, 404);
        $absence->delete();

        return redirect()
            ->route('catalog.teachers.show', $teacher)
            ->with('status', 'Отсъствието е премахнато.');
    }

    public function storeRoom(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'room_type' => ['required', Rule::in(['lecture', 'atelier', 'computer', 'painting', 'graphics', 'studio'])],
            'capacity' => ['required', 'integer', 'min:1', 'max:1000'],
            'equipment' => ['nullable', 'string', 'max:500'],
            'unavailable_periods' => ['nullable', 'regex:/^[1-3]\/[1-7]\/(?:[01]\d|2[0-3]):[0-5]\d-(?:[01]\d|2[0-3]):[0-5]\d(?:;[1-3]\/[1-7]\/(?:[01]\d|2[0-3]):[0-5]\d-(?:[01]\d|2[0-3]):[0-5]\d)*$/'],
        ]);

        $data['equipment'] = $this->parseStringList($data['equipment'] ?? null);
        $data['unavailable_periods'] = $this->parsePeriods($data['unavailable_periods'] ?? null);
        $data['is_active'] = true;
        Room::query()->create($data);

        return $this->saved('Залата е добавена.', 'organization');
    }

    public function updateRoom(Request $request, Room $room): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'room_type' => ['required', Rule::in(['lecture', 'atelier', 'computer', 'painting', 'graphics', 'studio'])],
            'capacity' => ['required', 'integer', 'min:1', 'max:1000'],
            'equipment' => ['nullable', 'string', 'max:500'],
            'unavailable_periods' => ['nullable', 'regex:/^[1-3]\/[1-7]\/(?:[01]\d|2[0-3]):[0-5]\d-(?:[01]\d|2[0-3]):[0-5]\d(?:;[1-3]\/[1-7]\/(?:[01]\d|2[0-3]):[0-5]\d-(?:[01]\d|2[0-3]):[0-5]\d)*$/'],
            'is_active' => ['required', 'boolean'],
        ]);

        $data['equipment'] = $this->parseStringList($data['equipment'] ?? null);
        $data['unavailable_periods'] = $this->parsePeriods($data['unavailable_periods'] ?? null);
        $room->update($data);

        return $this->saved('Данните за залата са обновени.', 'organization');
    }

    public function destroyRoom(Room $room): RedirectResponse
    {
        $room->delete();

        return $this->saved('Залата е изтрита. Занятията, които я използваха, остават без зададена зала.', 'organization');
    }

    private function saved(string $message, string $tab = 'plans'): RedirectResponse
    {
        return redirect()->route('catalog.index', ['tab' => $tab])->with('status', $message);
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
            preg_match('/^([1-3])\/([1-7])\/(\d{2}:\d{2})-(\d{2}:\d{2})$/', $period, $matches);

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
