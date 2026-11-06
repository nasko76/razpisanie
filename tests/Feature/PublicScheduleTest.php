<?php

use App\Models\Specialty;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TimetableEntry;

it('shows a blank public schedule until a specialty and course are selected', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Изберете специалност и курс')
        ->assertSee('name="specialty_id"', false)
        ->assertSee('name="course_year"', false)
        ->assertDontSee('name="academic_year"', false)
        ->assertDontSee('name="semester_type"', false);
});

it('shows the latest available term for the selected specialty and course', function () {
    $specialty = Specialty::query()->create([
        'name' => 'Изобразително изкуство',
        'code' => 'ИИ',
        'degree' => 'Бакалавър',
    ]);
    $group = StudentGroup::query()->create([
        'specialty_id' => $specialty->id,
        'curriculum_version_id' => null,
        'course_year' => 2,
        'group_name' => 'Група А',
        'student_count' => 20,
    ]);
    TimetableEntry::query()->create([
        'group_id' => $group->id,
        'subject_id' => null,
        'teacher_id' => null,
        'room_id' => null,
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
        'cycle_week' => 1,
        'weekday_no' => 6,
        'start_time' => '09:00:00',
        'end_time' => '10:30:00',
        'duration_hours' => 2,
        'custom_subject_name' => 'Гост-лектор',
    ]);

    $this->get(route('home', [
        'specialty_id' => $specialty->id,
        'course_year' => 2,
    ]))
        ->assertOk()
        ->assertSee('2026/2027')
        ->assertSee('Зимен семестър')
        ->assertSee('Седмица 1')
        ->assertSee('Гост-лектор')
        ->assertSee('Група А')
        ->assertSee('Сутрин')
        ->assertSee('Следобед')
        ->assertSee('Вечер');
});

it('shows both distinct teachers for a subject in the public schedule', function () {
    $specialty = Specialty::query()->create([
        'name' => 'Изобразително изкуство',
        'code' => 'ИИ',
        'degree' => 'Бакалавър',
    ]);
    $group = StudentGroup::query()->create([
        'specialty_id' => $specialty->id,
        'curriculum_version_id' => null,
        'course_year' => 2,
        'group_name' => 'Група А',
        'student_count' => 20,
    ]);
    $subject = Subject::query()->create([
        'code' => 'RIS-101',
        'name' => 'Рисуване',
        'auditorium_hours' => 60,
        'lecture_hours' => 30,
        'exercise_hours' => 30,
    ]);
    $teachers = collect([
        ['first_name' => 'Иван', 'last_name' => 'Иванов'],
        ['first_name' => 'Мария', 'last_name' => 'Петрова'],
    ])->map(fn (array $name): Teacher => Teacher::query()->create([
        ...$name,
        'max_daily_hours' => 8,
        'weekly_workload_hours' => 12,
        'active' => true,
    ]));

    foreach ($teachers as $index => $teacher) {
        TimetableEntry::query()->create([
            'group_id' => $group->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'academic_year' => '2026/2027',
            'semester_type' => 'winter',
            'cycle_week' => 1,
            'weekday_no' => $index + 1,
            'start_time' => '09:00:00',
            'end_time' => '12:45:00',
            'duration_hours' => 4,
            'coverage_type' => $index === 0 ? 'lecture' : 'exercise',
        ]);
    }

    $this->get(route('home', [
        'specialty_id' => $specialty->id,
        'course_year' => 2,
        'group_id' => $group->id,
    ]))
        ->assertOk()
        ->assertSee('Лекции: Иван Иванов, Упражнения: Мария Петрова')
        ->assertDontSee('Лекции: Иван Иванов, Упражнения: Иван Иванов');
});
