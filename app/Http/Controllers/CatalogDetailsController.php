<?php

namespace App\Http\Controllers;

use App\Models\CurriculumSubject;
use App\Models\CurriculumVersion;
use App\Models\StudentFlow;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CatalogDetailsController extends Controller
{
    public function createTeacher(): View
    {
        return view('admin.catalog.teacher-create');
    }

    public function curriculumVersion(CurriculumVersion $curriculumVersion): View
    {
        $curriculumVersion->load('specialty');
        $curriculumSubjects = CurriculumSubject::query()
            ->with('subject')
            ->where('curriculum_version_id', $curriculumVersion->id)
            ->orderBy('course_year')
            ->orderBy('semester_no')
            ->orderBy('subject_id')
            ->get();
        $assignments = TeacherAssignment::query()
            ->with(['teacher', 'studentFlow.groups', 'studentFlow.subject'])
            ->where(function ($query) use ($curriculumVersion): void {
                $query->where('curriculum_version_id', $curriculumVersion->id)
                    ->orWhereHas('studentGroup', fn ($groups) => $groups
                        ->where('curriculum_version_id', $curriculumVersion->id))
                    ->orWhereHas('studentFlow.groups', fn ($groups) => $groups
                        ->where('curriculum_version_id', $curriculumVersion->id));
            })
            ->get();
        $subjects = Subject::query()->orderBy('name')->get();

        return view('admin.catalog.curriculum-version', compact(
            'curriculumVersion',
            'curriculumSubjects',
            'assignments',
            'subjects',
        ));
    }

    public function teacher(Teacher $teacher): View
    {
        $teacher->load(['dayPreferences', 'absences']);
        $assignments = TeacherAssignment::query()
            ->with(['subject', 'curriculumVersion.specialty', 'studentFlow.subject', 'studentGroup.specialty'])
            ->where('teacher_id', $teacher->id)
            ->orderBy('course_year')
            ->orderBy('semester')
            ->get();
        $dayPreferences = $teacher->dayPreferences
            ->groupBy('cycle_week')
            ->map(fn ($preferences) => $preferences->pluck('preference', 'weekday_no'));
        $days = [
            1 => 'Понеделник',
            2 => 'Вторник',
            3 => 'Сряда',
            4 => 'Четвъртък',
            5 => 'Петък',
            6 => 'Събота',
            7 => 'Неделя',
        ];
        $curriculumSubjects = CurriculumSubject::query()
            ->with(['subject', 'curriculumVersion.specialty'])
            ->orderBy('course_year')
            ->orderBy('semester_no')
            ->get();
        $studentFlows = StudentFlow::query()->with(['groups.specialty', 'subject'])->whereNotNull('subject_id')->orderBy('name')->get();

        return view('admin.catalog.teacher', compact(
            'teacher',
            'assignments',
            'dayPreferences',
            'days',
            'curriculumSubjects',
            'studentFlows',
        ));
    }

    public function destroyTeacherAssignment(TeacherAssignment $assignment): RedirectResponse
    {
        $teacherId = $assignment->teacher_id;
        $assignment->delete();

        return redirect()
            ->route('catalog.teachers.show', $teacherId)
            ->with('status', 'Преподавателското назначение е премахнато.');
    }

    public function destroyStudentFlow(StudentFlow $studentFlow): RedirectResponse
    {
        $studentFlow->delete();

        return redirect()->route('catalog.index', ['tab' => 'organization'])
            ->with('status', 'Потокът е изтрит; общите занятия са оставени към групата, в която са създадени.');
    }
}
