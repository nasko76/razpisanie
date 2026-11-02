<?php

use App\Models\CurriculumSubject;
use App\Models\CurriculumVersion;
use App\Models\Room;
use App\Models\Specialty;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\TimetableEntry;
use App\Models\User;
use App\Services\ScheduleService;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

function createScheduleAdmin(): User
{
    config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->firstOrCreate([
        'name' => 'admin',
        'guard_name' => 'web',
    ]));

    return $admin;
}

function createScheduleFixtures(array $cycleWeeks = [1, 2, 3]): array
{
    $specialty = Specialty::query()->create([
        'name' => 'Изобразително изкуство',
        'code' => 'II',
        'degree' => 'Бакалавър',
    ]);

    $curriculumVersion = CurriculumVersion::query()->create([
        'specialty_id' => $specialty->id,
        'name' => 'План 2026',
        'version_year' => 2026,
        'is_active' => true,
    ]);

    $group = StudentGroup::query()->create([
        'specialty_id' => $specialty->id,
        'curriculum_version_id' => $curriculumVersion->id,
        'course_year' => 1,
        'group_name' => 'А',
        'student_count' => 20,
    ]);

    $subject = Subject::query()->create([
        'code' => 'RIS-101',
        'name' => 'Рисуване',
        'type' => 'atelier',
        'default_hours' => 4,
    ]);

    $curriculumSubject = CurriculumSubject::query()->create([
        'curriculum_version_id' => $curriculumVersion->id,
        'subject_id' => $subject->id,
        'semester_no' => 1,
        'course_year' => 1,
        'contact_hours' => 60,
        'self_study_hours' => 30,
        'scheduled_hours' => 4,
        'cycle_weeks' => $cycleWeeks,
    ]);

    $teacher = Teacher::query()->create([
        'first_name' => 'Иван',
        'last_name' => 'Иванов',
        'max_daily_hours' => 8,
        'weekly_workload_hours' => 12,
        'active' => true,
    ]);

    TeacherAssignment::query()->create([
        'teacher_id' => $teacher->id,
        'subject_id' => $subject->id,
        'curriculum_version_id' => $curriculumVersion->id,
        'course_year' => 1,
        'semester' => 'winter',
    ]);

    $room = Room::query()->create([
        'name' => 'Ателие 1',
        'room_type' => 'atelier',
        'capacity' => 24,
        'is_active' => true,
    ]);

    return compact('specialty', 'curriculumVersion', 'group', 'subject', 'curriculumSubject', 'teacher', 'room');
}

it('generates only curriculum subjects for the selected semester and cycle weeks', function () {
    $fixtures = createScheduleFixtures([1, 3]);
    $summerSubject = Subject::query()->create([
        'code' => 'HIS-201',
        'name' => 'История на изкуството',
        'type' => 'lecture',
        'default_hours' => 3,
    ]);

    CurriculumSubject::query()->create([
        'curriculum_version_id' => $fixtures['curriculumVersion']->id,
        'subject_id' => $summerSubject->id,
        'semester_no' => 2,
        'course_year' => 1,
        'contact_hours' => 45,
        'self_study_hours' => 20,
        'scheduled_hours' => 3,
        'cycle_weeks' => [1, 2, 3],
    ]);

    $result = app(ScheduleService::class)->generateForGroup($fixtures['group'], '2026/2027', 'winter');

    expect($result['entries'])->toHaveCount(2)
        ->and($result['unscheduled'])->toBeEmpty()
        ->and(TimetableEntry::query()->pluck('cycle_week')->sort()->values()->all())->toBe([1, 3]);
});

it('respects teacher and room availability while generating', function () {
    $fixtures = createScheduleFixtures([1]);
    $fixtures['teacher']->update([
        'unavailable_weekdays' => [1],
        'unavailable_periods' => [[
            'cycle_week' => 1,
            'weekday_no' => 2,
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]],
    ]);
    $fixtures['room']->update([
        'unavailable_periods' => [[
            'cycle_week' => 1,
            'weekday_no' => 2,
            'start_time' => '13:00',
            'end_time' => '15:45',
        ]],
    ]);

    $result = app(ScheduleService::class)->generateForGroup($fixtures['group'], '2026/2027', 'winter');

    expect($result['entries'])->toHaveCount(1)
        ->and($result['entries'][0]->weekday_no)->toBe(3)
        ->and($result['entries'][0]->start_time)->toBe('08:00:00')
        ->and($result['unscheduled'])->toBeEmpty();
});

it('preserves locked entries without scheduling a duplicate during regeneration', function () {
    $fixtures = createScheduleFixtures([1, 2]);
    $service = app(ScheduleService::class);
    $firstGeneration = $service->generateForGroup($fixtures['group'], '2026/2027', 'winter');
    $lockedEntry = $firstGeneration['entries'][0];
    $lockedEntry->update(['locked' => true]);
    $fixtures['curriculumSubject']->update(['cycle_weeks' => [1, 2, 3]]);

    $service->generateForGroup($fixtures['group'], '2026/2027', 'winter');

    expect(TimetableEntry::query()->count())->toBe(3)
        ->and(TimetableEntry::query()->whereKey($lockedEntry->id)->value('locked'))->toBe(1)
        ->and(TimetableEntry::query()->pluck('cycle_week')->sort()->values()->all())->toBe([1, 2, 3]);
});

it('rejects manual moves that overlap a teacher, room, or group booking', function () {
    $fixtures = createScheduleFixtures([1]);
    $otherGroup = StudentGroup::query()->create([
        'specialty_id' => $fixtures['specialty']->id,
        'curriculum_version_id' => $fixtures['curriculumVersion']->id,
        'course_year' => 1,
        'group_name' => 'Б',
        'student_count' => 18,
    ]);
    $otherSubject = Subject::query()->create([
        'code' => 'COM-102',
        'name' => 'Композиция',
        'type' => 'atelier',
        'default_hours' => 4,
    ]);

    $entry = TimetableEntry::query()->create([
        'group_id' => $fixtures['group']->id,
        'subject_id' => $fixtures['subject']->id,
        'teacher_id' => $fixtures['teacher']->id,
        'room_id' => $fixtures['room']->id,
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
        'cycle_week' => 1,
        'weekday_no' => 1,
        'start_time' => '08:00:00',
        'end_time' => '12:45:00',
        'duration_hours' => 4,
        'locked' => false,
    ]);

    TimetableEntry::query()->create([
        'group_id' => $otherGroup->id,
        'subject_id' => $otherSubject->id,
        'teacher_id' => $fixtures['teacher']->id,
        'room_id' => $fixtures['room']->id,
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
        'cycle_week' => 1,
        'weekday_no' => 2,
        'start_time' => '08:00:00',
        'end_time' => '12:45:00',
        'duration_hours' => 4,
        'locked' => true,
    ]);

    expect(fn () => app(ScheduleService::class)->moveEntry($entry, [
        'block' => 0,
        'cycle_week' => 1,
        'weekday_no' => 2,
        'teacher_id' => $fixtures['teacher']->id,
        'room_id' => $fixtures['room']->id,
        'locked' => false,
    ]))->toThrow(ValidationException::class);
});

it('allows administrators to open the schedule and catalog and create specialties', function () {
    $admin = createScheduleAdmin();

    $this->actingAs($admin)
        ->get(route('schedules.index'))
        ->assertOk()
        ->assertSee('Планиране');

    $this->actingAs($admin)
        ->get(route('catalog.index'))
        ->assertOk()
        ->assertSee('Учебен каталог');

    $this->post(route('catalog.specialties.store'), [
        'name' => 'Изобразително изкуство',
        'code' => 'II',
        'degree' => 'Бакалавър',
    ])->assertRedirect(route('catalog.index'));

    $this->assertDatabaseHas('specialties', [
        'name' => 'Изобразително изкуство',
        'code' => 'II',
    ]);

    $specialty = Specialty::query()->firstOrFail();

    $this->post(route('catalog.curriculum-versions.store'), [
        'specialty_id' => $specialty->id,
        'name' => 'Учебен план 2026',
        'version_year' => 2026,
        'is_active' => 1,
    ])->assertRedirect(route('catalog.index'));

    $version = CurriculumVersion::query()->firstOrFail();

    $this->post(route('catalog.groups.store'), [
        'specialty_id' => $specialty->id,
        'curriculum_version_id' => $version->id,
        'course_year' => 1,
        'group_name' => 'А',
        'student_count' => 20,
    ])->assertRedirect(route('catalog.index'));

    $this->post(route('catalog.subjects.store'), [
        'code' => 'RIS-101',
        'name' => 'Рисуване',
        'type' => 'atelier',
        'default_hours' => 4,
    ])->assertRedirect(route('catalog.index'));

    $subject = Subject::query()->firstOrFail();

    $this->post(route('catalog.curriculum-subjects.store'), [
        'curriculum_version_id' => $version->id,
        'subject_id' => $subject->id,
        'semester_no' => 1,
        'course_year' => 1,
        'contact_hours' => 60,
        'self_study_hours' => 30,
        'scheduled_hours' => 4,
        'cycle_weeks' => '1,3',
    ])->assertRedirect(route('catalog.index'));

    $this->post(route('catalog.teachers.store'), [
        'first_name' => 'Иван',
        'last_name' => 'Иванов',
        'title' => 'доц. д-р',
        'max_daily_hours' => 8,
        'weekly_workload_hours' => 12,
        'preferred_weekdays' => '2,4',
        'unavailable_weekdays' => '5',
        'unavailable_periods' => '1/2/08:00-10:00',
    ])->assertRedirect(route('catalog.index'));

    $teacher = Teacher::query()->firstOrFail();

    $this->post(route('catalog.rooms.store'), [
        'name' => 'Ателие 1',
        'room_type' => 'atelier',
        'capacity' => 24,
        'equipment' => 'стативи, мивки',
        'unavailable_periods' => '1/2/08:00-10:00',
    ])->assertRedirect(route('catalog.index'));

    $this->post(route('catalog.teacher-assignments.store'), [
        'teacher_id' => $teacher->id,
        'subject_id' => $subject->id,
        'curriculum_version_id' => $version->id,
        'course_year' => 1,
        'semester' => 'winter',
    ])->assertRedirect(route('catalog.index'));

    $this->actingAs($admin)
        ->get(route('catalog.index'))
        ->assertOk()
        ->assertSee('Учебен план 2026');
});

it('generates and exports a group schedule', function () {
    $fixtures = createScheduleFixtures([1, 2, 3]);
    $admin = createScheduleAdmin();

    $this->actingAs($admin)
        ->post(route('schedules.generate'), [
            'group_id' => $fixtures['group']->id,
            'academic_year' => '2026/2027',
            'semester_type' => 'winter',
        ])
        ->assertRedirect();

    $this->assertDatabaseCount('timetable_entries', 3);

    $this->get(route('schedules.index', [
        'group_id' => $fixtures['group']->id,
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
        'cycle_week' => 1,
    ]))->assertOk()->assertSee('Рисуване');

    $pdf = $this->actingAs($admin)->get(route('schedules.export-pdf', [
        'group' => $fixtures['group']->id,
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
    ]));
    $pdf->assertOk()->assertHeader('content-type', 'application/pdf');

    $csv = $this->actingAs($admin)->get(route('schedules.export-csv', [
        'group' => $fixtures['group']->id,
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
    ]));
    $csv->assertOk()->assertHeader('content-disposition');
});

it('records schedule revisions and compares the latest change', function () {
    $fixtures = createScheduleFixtures([1]);
    $admin = createScheduleAdmin();
    $this->actingAs($admin);

    $entry = app(ScheduleService::class)
        ->generateForGroup($fixtures['group'], '2026/2027', 'winter')['entries'][0];

    app(ScheduleService::class)->moveEntry($entry, [
        'block' => 2,
        'cycle_week' => 1,
        'weekday_no' => 2,
        'teacher_id' => $fixtures['teacher']->id,
        'room_id' => $fixtures['room']->id,
        'locked' => false,
    ]);

    $this->assertDatabaseCount('schedule_revisions', 2);

    $this->get(route('schedules.history', [
        'group' => $fixtures['group']->id,
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
    ]))->assertOk()
        ->assertSee('Версия 1')
        ->assertSee('Версия 2')
        ->assertSee('Автоматично генериране')
        ->assertSee('Ръчна редакция')
        ->assertSee('Променено: Рисуване')
        ->assertSee('Вторник');

    $this->delete(route('schedules.destroy', [
        'entry' => $entry->id,
        'group_id' => $fixtures['group']->id,
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
    ]))->assertRedirect();

    $this->assertDatabaseCount('schedule_revisions', 3);
    $this->get(route('schedules.history', [
        'group' => $fixtures['group']->id,
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
    ]))->assertOk()
        ->assertSee('Ръчно премахване')
        ->assertSee('Премахнато: Рисуване');
});
