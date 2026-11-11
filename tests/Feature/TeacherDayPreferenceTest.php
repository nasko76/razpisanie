<?php

use App\Models\CurriculumSubject;
use App\Models\CurriculumVersion;
use App\Models\Specialty;
use App\Models\StudentFlow;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\TeacherDayPreference;
use App\Models\TimetableEntry;
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

it('warns when a preference change affects scheduled classes and shows teacher schedule hours', function () {
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
    $specialty = Specialty::query()->create(['name' => 'Илюстрация']);
    $curriculumVersion = CurriculumVersion::query()->create([
        'specialty_id' => $specialty->id,
        'name' => 'План 2026',
        'version_year' => 2026,
        'is_active' => true,
    ]);
    $subject = Subject::query()->create([
        'name' => 'Рисуване',
    ]);
    CurriculumSubject::query()->create([
        'curriculum_version_id' => $curriculumVersion->id,
        'subject_id' => $subject->id,
        'semester_no' => 1,
        'course_year' => 1,
        'auditorium_hours' => 20,
        'lecture_hours' => 20,
    ]);
    $group = StudentGroup::query()->create([
        'specialty_id' => $specialty->id,
        'curriculum_version_id' => $curriculumVersion->id,
        'course_year' => 1,
        'group_name' => 'А',
        'student_count' => 20,
    ]);
    $secondGroup = StudentGroup::query()->create([
        'specialty_id' => $specialty->id,
        'curriculum_version_id' => $curriculumVersion->id,
        'course_year' => 1,
        'group_name' => 'Б',
        'student_count' => 18,
    ]);
    $flow = StudentFlow::query()->create(['name' => 'Общи лекции', 'subject_id' => $subject->id]);
    $flow->groups()->sync([$group->id, $secondGroup->id]);
    $entry = TimetableEntry::query()->create([
        'group_id' => $group->id,
        'subject_id' => $subject->id,
        'student_flow_id' => $flow->id,
        'teacher_id' => $teacher->id,
        'coverage_type' => 'lecture',
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
        'cycle_week' => 1,
        'weekday_no' => 1,
        'start_time' => '08:00:00',
        'end_time' => '11:45:00',
        'duration_hours' => 4,
        'auditorium_hours' => 3,
        'self_study_hours' => 1,
        'locked' => false,
    ]);

    $preferences = [];
    foreach ([1, 2, 3] as $cycleWeek) {
        foreach (range(1, 7) as $weekday) {
            $preferences[$cycleWeek][$weekday] = 'neutral';
        }
    }
    $preferences[1][1] = 'unavailable';

    $this->put(route('catalog.teachers.preferred-days', $teacher), ['preferences' => $preferences])
        ->assertRedirect(route('catalog.teachers.show', $teacher))
        ->assertSessionHas('warning', fn (string $warning): bool => str_contains($warning, '1 вече поставени занятия')
            && str_contains($warning, 'Рисуване')
            && str_contains($warning, 'не са преместени автоматично'));

    $this->assertDatabaseHas('timetable_entries', ['id' => $entry->id, 'teacher_id' => $teacher->id]);
    $this->get(route('catalog.teachers.show', $teacher))
        ->assertOk()
        ->assertSee('Поставени часове по разписания')
        ->assertSee('20 часа общо за 15-седмичен семестър')
        ->assertSee('АЗ 15 ч. · самоподготовка 5 ч.')
        ->assertSee('1 курс · семестър 1 · Илюстрация · група А')
        ->assertSee('1 курс · семестър 1 · Илюстрация · група Б')
        ->assertSee('АЗ 3 ч. · Самоподготовка 1 ч.');
});

it('does not warn about pool blocks when teacher preferences change', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('admin'));
    $teacher = Teacher::query()->create([
        'first_name' => 'Иван',
        'last_name' => 'Иванов',
        'max_daily_hours' => 8,
        'weekly_workload_hours' => 20,
        'active' => true,
    ]);
    $specialty = Specialty::query()->create(['name' => 'Дизайн']);
    $group = StudentGroup::query()->create([
        'specialty_id' => $specialty->id,
        'course_year' => 1,
        'group_name' => 'А',
        'student_count' => 20,
    ]);
    $subject = Subject::query()->create(['name' => 'Композиция']);
    TimetableEntry::query()->create([
        'group_id' => $group->id,
        'subject_id' => $subject->id,
        'teacher_id' => $teacher->id,
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
        'cycle_week' => 1,
        'weekday_no' => 7,
        'start_time' => '08:00:00',
        'end_time' => '11:45:00',
        'duration_hours' => 4,
        'is_in_pool' => true,
        'pool_reason' => 'auto',
    ]);

    $preferences = [];
    foreach ([1, 2, 3] as $cycleWeek) {
        foreach (range(1, 7) as $weekday) {
            $preferences[$cycleWeek][$weekday] = 'neutral';
        }
    }
    $preferences[1][7] = 'unavailable';

    $this->actingAs($user)
        ->put(route('catalog.teachers.preferred-days', $teacher), ['preferences' => $preferences])
        ->assertRedirect(route('catalog.teachers.show', $teacher))
        ->assertSessionMissing('warning');
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
    ]);
    CurriculumSubject::query()->create([
        'curriculum_version_id' => $curriculumVersion->id,
        'subject_id' => $subject->id,
        'semester_no' => 1,
        'course_year' => 1,
        'auditorium_hours' => 60,
        'lecture_hours' => 30,
        'exercise_hours' => 30,
        'self_study_hours' => 20,
        'control_type' => 'И',
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
