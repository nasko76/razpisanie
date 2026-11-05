<?php

use App\Models\CurriculumSubject;
use App\Models\CurriculumVersion;
use App\Models\Specialty;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\TeacherDayPreference;
use App\Models\User;
use Spatie\Permission\Models\Role;

it('defaults weekdays to neutral and weekends to unavailable', function () {
    $teacher = Teacher::query()->create([
        'first_name' => 'Иван',
        'last_name' => 'Иванов',
        'max_daily_hours' => 8,
        'weekly_workload_hours' => 20,
        'active' => true,
    ]);

    expect($teacher->dayPreferences()->count())->toBe(21);
    foreach ([1, 2, 3] as $cycleWeek) {
        expect($teacher->dayPreferences()->where('cycle_week', $cycleWeek)->where('weekday_no', 1)->value('preference'))->toBe('neutral')
            ->and($teacher->dayPreferences()->where('cycle_week', $cycleWeek)->where('weekday_no', 5)->value('preference'))->toBe('neutral')
            ->and($teacher->dayPreferences()->where('cycle_week', $cycleWeek)->where('weekday_no', 6)->value('preference'))->toBe('unavailable')
            ->and($teacher->dayPreferences()->where('cycle_week', $cycleWeek)->where('weekday_no', 7)->value('preference'))->toBe('unavailable');
    }
});

it('updates day preferences separately for each cycle week', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('admin'));
    $this->actingAs($user);
    $teacher = Teacher::query()->create([
        'first_name' => 'Мария',
        'last_name' => 'Петрова',
        'max_daily_hours' => 8,
        'weekly_workload_hours' => 20,
        'active' => true,
    ]);

    $preferences = [
        1 => [1 => 'preferred', 2 => 'neutral', 3 => 'unavailable', 4 => 'neutral', 5 => 'preferred', 6 => 'preferred', 7 => 'unavailable'],
        2 => [1 => 'neutral', 2 => 'preferred', 3 => 'neutral', 4 => 'unavailable', 5 => 'neutral', 6 => 'unavailable', 7 => 'unavailable'],
        3 => [1 => 'unavailable', 2 => 'neutral', 3 => 'preferred', 4 => 'neutral', 5 => 'neutral', 6 => 'unavailable', 7 => 'unavailable'],
    ];

    $this->put(route('catalog.teachers.preferred-days', $teacher), [
        'preferences' => $preferences,
    ])->assertRedirect(route('catalog.teachers.show', $teacher));

    foreach ($preferences as $cycleWeek => $weekPreferences) {
        foreach ($weekPreferences as $weekday => $preference) {
            expect(TeacherDayPreference::query()
                ->where('teacher_id', $teacher->id)
                ->where('cycle_week', $cycleWeek)
                ->where('weekday_no', $weekday)
                ->value('preference'))->toBe($preference);
        }
    }

    $this->get(route('catalog.teachers.show', $teacher))
        ->assertOk()
        ->assertSee('Предпочитани дни за провеждане на занятия')
        ->assertSee('Седмица 1')
        ->assertSee('Седмица 2')
        ->assertSee('Седмица 3')
        ->assertSee('Отсъствия по дати · допълнителни настройки')
        ->assertDontSee('<details open', false);
});

it('shows curriculum coverage in both the plan overview and teacher dossier', function () {
    $specialty = Specialty::query()->create([
        'name' => 'Изобразително изкуство',
        'code' => 'ИИ',
        'degree' => 'Бакалавър',
    ]);
    $curriculumVersion = CurriculumVersion::query()->create([
        'specialty_id' => $specialty->id,
        'name' => 'Учебен план 2026',
        'version_year' => 2026,
        'is_active' => true,
    ]);
    $subject = Subject::query()->create([
        'code' => 'RIS-101',
        'name' => 'Рисуване',
        'auditorium_hours' => 60,
        'lecture_hours' => 30,
        'exercise_hours' => 30,
        'self_study_hours' => 20,
        'control_type' => 'И',
    ]);
    CurriculumSubject::query()->create([
        'curriculum_version_id' => $curriculumVersion->id,
        'subject_id' => $subject->id,
        'semester_no' => 1,
        'course_year' => 1,
    ]);
    $teacher = Teacher::query()->create([
        'first_name' => 'Иван',
        'last_name' => 'Иванов',
        'max_daily_hours' => 8,
        'weekly_workload_hours' => 20,
        'active' => true,
    ]);
    $assignment = TeacherAssignment::query()->create([
        'teacher_id' => $teacher->id,
        'subject_id' => $subject->id,
        'curriculum_version_id' => $curriculumVersion->id,
        'course_year' => 1,
        'semester' => 'winter',
        'coverage_type' => 'lecture',
    ]);
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('admin'));
    $this->actingAs($user);

    $this->get(route('catalog.curriculum-versions.show', $curriculumVersion))
        ->assertOk()
        ->assertSee('Рисуване')
        ->assertSee('Изпит (И)')
        ->assertSee('Иван Иванов')
        ->assertSee('80 ч.');

    $this->get(route('catalog.teachers.show', $teacher))
        ->assertOk()
        ->assertSee('Предпочитани дни за провеждане на занятия')
        ->assertSee('Рисуване')
        ->assertSee('Изобразително изкуство');

    $this->delete(route('catalog.teacher-assignments.destroy', $assignment))
        ->assertRedirect(route('catalog.teachers.show', $teacher));

    $this->assertDatabaseMissing('teacher_assignments', ['id' => $assignment->id]);
});
