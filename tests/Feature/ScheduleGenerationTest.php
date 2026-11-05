<?php

use App\Models\CurriculumSubject;
use App\Models\CurriculumVersion;
use App\Models\Room;
use App\Models\Specialty;
use App\Models\StudentFlow;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\TimetableEntry;
use App\Models\User;
use App\Services\ScheduleService;
use Illuminate\Support\Facades\DB;
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

function createScheduleFixtures(): array
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
        'auditorium_hours' => 60,
        'lecture_hours' => 60,
    ]);

    $curriculumSubject = CurriculumSubject::query()->create([
        'curriculum_version_id' => $curriculumVersion->id,
        'subject_id' => $subject->id,
        'semester_no' => 1,
        'course_year' => 1,
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
        'coverage_type' => 'lecture',
    ]);

    $room = Room::query()->create([
        'name' => 'Ателие 1',
        'room_type' => 'atelier',
        'capacity' => 24,
        'is_active' => true,
    ]);

    return compact('specialty', 'curriculumVersion', 'group', 'subject', 'curriculumSubject', 'teacher', 'room');
}

it('generates the selected curriculum semester as a recurring three-week pattern', function () {
    $fixtures = createScheduleFixtures();
    $summerSubject = Subject::query()->create([
        'code' => 'HIS-201',
        'name' => 'История на изкуството',
        'auditorium_hours' => 45,
        'lecture_hours' => 45,
    ]);

    CurriculumSubject::query()->create([
        'curriculum_version_id' => $fixtures['curriculumVersion']->id,
        'subject_id' => $summerSubject->id,
        'semester_no' => 2,
        'course_year' => 1,
    ]);

    $result = app(ScheduleService::class)->generateForGroup($fixtures['group'], '2026/2027', 'winter');

    expect($result['entries'])->toHaveCount(3)
        ->and($result['unscheduled'])->toBeEmpty()
        ->and(TimetableEntry::query()->pluck('cycle_week')->unique()->count())->toBe(1)
        ->and(count($result['analysis'][0]['weekdays']))->toBe(3);
});

it('reports when a curriculum subject has no auditorium or component hours', function () {
    $fixtures = createScheduleFixtures();
    $fixtures['subject']->update(['auditorium_hours' => 0, 'lecture_hours' => 0]);

    $result = app(ScheduleService::class)->generateForGroup($fixtures['group'], '2026/2027', 'winter');

    expect($result['entries'])->toBeEmpty()
        ->and($result['unscheduled'])->toHaveCount(1)
        ->and($result['unscheduled'][0]['reason'])->toContain('Не са зададени аудиторни часове');
});

it('generates draft entries when a teacher or suitable room is missing', function () {
    $fixtures = createScheduleFixtures();
    TeacherAssignment::query()->delete();
    $fixtures['room']->update(['is_active' => false]);

    $result = app(ScheduleService::class)->generateForGroup($fixtures['group'], '2026/2027', 'winter');

    expect($result['entries'])->toHaveCount(3)
        ->and(collect($result['entries'])->every(fn (TimetableEntry $entry): bool => $entry->teacher_id === null && $entry->room_id === null))->toBeTrue()
        ->and($result['warnings'])->not->toBeEmpty()
        ->and($result['unscheduled'])->toBeEmpty();
});

it('respects teacher and room availability while generating', function () {
    $fixtures = createScheduleFixtures();
    $fixtures['teacher']->update([
        'unavailable_weekdays' => [],
    ]);
    $fixtures['teacher']->dayPreferences()->where('cycle_week', 1)->where('weekday_no', 1)->update(['preference' => 'unavailable']);
    $fixtures['teacher']->dayPreferences()->where('cycle_week', 1)->where('weekday_no', 2)->update(['preference' => 'preferred']);
    $fixtures['room']->update([
        'unavailable_periods' => [[
            'cycle_week' => 1,
            'weekday_no' => 2,
            'start_time' => '13:00',
            'end_time' => '15:45',
        ]],
    ]);

    $result = app(ScheduleService::class)->generateForGroup($fixtures['group'], '2026/2027', 'winter');

    expect($result['entries'])->toHaveCount(3)
        ->and(collect($result['entries'])->pluck('weekday_no')->all())->toBe([2, 3, 4])
        ->and(collect($result['entries'])->pluck('start_time')->unique()->all())->toBe(['08:00:00'])
        ->and($result['unscheduled'])->toBeEmpty();
});

it('preserves locked entries without scheduling a duplicate during regeneration', function () {
    $fixtures = createScheduleFixtures();
    $service = app(ScheduleService::class);
    $firstGeneration = $service->generateForGroup($fixtures['group'], '2026/2027', 'winter');
    $lockedEntry = $firstGeneration['entries'][0];
    $lockedEntry->update(['locked' => true]);

    $result = $service->generateForGroup($fixtures['group'], '2026/2027', 'winter');

    expect($result['entries'])->toBeEmpty()
        ->and($result['warnings'])->toHaveCount(1)
        ->and(TimetableEntry::query()->count())->toBe(1)
        ->and(TimetableEntry::query()->whereKey($lockedEntry->id)->value('locked'))->toBe(1)
        ->and(TimetableEntry::query()->value('cycle_week'))->toBe($lockedEntry->cycle_week);
});

it('rejects manual moves that overlap a teacher, room, or group booking', function () {
    $fixtures = createScheduleFixtures();
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
        'cycle_week' => 1,
        'weekday_no' => 2,
        'start_time' => '08:00:00',
        'end_time' => '12:45:00',
        'duration_hours' => 4,
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
    ])->assertRedirect(route('catalog.index', ['tab' => 'plans']));

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
    ])->assertRedirect(route('catalog.index', ['tab' => 'plans']));

    $version = CurriculumVersion::query()->firstOrFail();

    $this->post(route('catalog.groups.store'), [
        'specialty_id' => $specialty->id,
        'curriculum_version_id' => $version->id,
        'course_year' => 1,
        'group_name' => 'А',
        'student_count' => 20,
    ])->assertRedirect(route('catalog.index', ['tab' => 'organization']));

    $this->post(route('catalog.subjects.store'), [
        'code' => 'RIS-101',
        'name' => 'Рисуване',
        'auditorium_hours' => 60,
        'lecture_hours' => 30,
        'exercise_hours' => 30,
        'self_study_hours' => 30,
    ])->assertRedirect(route('catalog.index', ['tab' => 'subjects']));

    $subject = Subject::query()->firstOrFail();

    $this->post(route('catalog.teachers.store'), [
        'first_name' => 'Иван',
        'last_name' => 'Иванов',
        'title' => 'доц. д-р',
        'max_daily_hours' => 8,
        'weekly_workload_hours' => 12,
    ])->assertRedirect(route('catalog.teachers.show', Teacher::query()->firstOrFail()));

    $teacher = Teacher::query()->firstOrFail();

    $this->post(route('catalog.rooms.store'), [
        'name' => 'Ателие 1',
        'room_type' => 'atelier',
        'capacity' => 24,
        'equipment' => 'стативи, мивки',
        'unavailable_periods' => '1/2/08:00-10:00',
    ])->assertRedirect(route('catalog.index', ['tab' => 'organization']));

    $this->post(route('catalog.teacher-assignments.store'), [
        'teacher_id' => $teacher->id,
        'subject_id' => $subject->id,
        'curriculum_version_id' => $version->id,
        'course_year' => 1,
        'semester' => 'winter',
        'coverage_type' => 'lecture',
    ])->assertRedirect(route('catalog.teachers.show', $teacher));

    $this->actingAs($admin)
        ->get(route('catalog.index'))
        ->assertOk()
        ->assertSee('Учебен план 2026')
        ->assertDontSee('Предметно-часова структура');
});

it('organizes catalog sections into tabs and copies editable curriculum versions', function () {
    $fixtures = createScheduleFixtures();
    $admin = createScheduleAdmin();
    $this->actingAs($admin);

    $this->get(route('catalog.index', ['tab' => 'teachers']))
        ->assertOk()
        ->assertSee('Преподаватели')
        ->assertDontSee('Зали и ателиета')
        ->assertDontSee('Специалности');

    $this->get(route('catalog.index', ['tab' => 'plans']))
        ->assertOk()
        ->assertSee('Копирай план');

    $this->post(route('catalog.curriculum-versions.duplicate', $fixtures['curriculumVersion']))
        ->assertRedirect(route('catalog.index', ['tab' => 'plans']));

    $copy = CurriculumVersion::query()->where('name', 'План 2026 — копие')->firstOrFail();
    expect((bool) $copy->is_active)->toBeFalse()
        ->and($copy->subjects()->count())->toBe(1);

    $this->patch(route('catalog.curriculum-versions.details.update', $copy), [
        'name' => 'План 2027',
        'version_year' => 2027,
    ])->assertRedirect(route('catalog.curriculum-versions.show', $copy));

    $extraSubject = Subject::query()->create([
        'code' => 'COM-102',
        'name' => 'Композиция',
        'auditorium_hours' => 30,
        'lecture_hours' => 15,
        'exercise_hours' => 15,
        'self_study_hours' => 0,
    ]);
    $this->post(route('catalog.curriculum-subjects.store', $copy), [
        'subject_id' => $extraSubject->id,
        'course_year' => 2,
        'semester_no' => 3,
    ])->assertRedirect(route('catalog.curriculum-versions.show', $copy));

    expect($copy->subjects()->count())->toBe(2);
});

it('generates editable sessions on a preferred Saturday without configured time blocks', function () {
    $fixtures = createScheduleFixtures();
    $fixtures['subject']->update(['auditorium_hours' => 60, 'lecture_hours' => 60]);
    $fixtures['teacher']->update(['preferred_weekdays' => [6]]);
    $fixtures['teacher']->update(['weekly_workload_hours' => 30]);
    $fixtures['teacher']->dayPreferences()
        ->where('cycle_week', 1)
        ->where('weekday_no', 6)
        ->update(['preference' => 'preferred']);
    $result = app(ScheduleService::class)->generateForGroup($fixtures['group'], '2026/2027', 'winter');
    $entries = collect($result['entries']);

    expect($entries)->toHaveCount(3)
        ->and($entries->pluck('weekday_no')->max())->toBeLessThanOrEqual(5)
        ->and($entries->pluck('cycle_week')->unique()->all())->toHaveCount(1)
        ->and($entries->pluck('start_time')->unique()->all())->toBe(['08:00:00'])
        ->and($entries->pluck('end_time')->unique()->all())->toBe(['11:45:00'])
        ->and($entries->pluck('duration_hours')->unique()->all())->toBe([4]);

    $admin = createScheduleAdmin();
    $this->actingAs($admin)
        ->get(route('schedules.index', [
            'group_id' => $fixtures['group']->id,
            'academic_year' => '2026/2027',
            'semester_type' => 'winter',
            'cycle_week' => 1,
        ]))
        ->assertOk()
        ->assertSee('data-drop-week="1"', false)
        ->assertSee('name="start_time"', false)
        ->assertDontSee('schedule_period_id');
});

it('generates separate lecture and exercise entries with their assigned teachers', function () {
    $fixtures = createScheduleFixtures();
    $exerciseTeacher = Teacher::query()->create([
        'first_name' => 'Мария',
        'last_name' => 'Петрова',
        'max_daily_hours' => 8,
        'weekly_workload_hours' => 12,
        'active' => true,
    ]);
    $fixtures['curriculumSubject']->update([
        'source_data' => ['columns' => ['Semester' => '1']],
    ]);
    $fixtures['subject']->update([
        'auditorium_hours' => 60,
        'lecture_hours' => 30,
        'exercise_hours' => 30,
    ]);
    TeacherAssignment::query()->create([
        'teacher_id' => $fixtures['teacher']->id,
        'subject_id' => $fixtures['subject']->id,
        'curriculum_version_id' => $fixtures['curriculumVersion']->id,
        'course_year' => 1,
        'semester' => 'winter',
        'coverage_type' => 'Лекции',
    ]);
    TeacherAssignment::query()->create([
        'teacher_id' => $exerciseTeacher->id,
        'subject_id' => $fixtures['subject']->id,
        'curriculum_version_id' => $fixtures['curriculumVersion']->id,
        'course_year' => 1,
        'semester' => 'winter',
        'coverage_type' => 'Упражнения',
    ]);

    $result = app(ScheduleService::class)->generateForGroup($fixtures['group'], '2026/2027', 'winter');
    $entriesByType = collect($result['entries'])->groupBy('coverage_type');

    expect($entriesByType)->toHaveKeys(['lecture', 'exercise'])
        ->and($entriesByType['lecture'])->toHaveCount(2)
        ->and($entriesByType['lecture']->pluck('teacher_id')->unique()->all())->toBe([$fixtures['teacher']->id])
        ->and($entriesByType['exercise'])->toHaveCount(2)
        ->and($entriesByType['exercise']->pluck('teacher_id')->unique()->all())->toBe([$exerciseTeacher->id])
        ->and($entriesByType['lecture']->pluck('duration_hours')->unique()->all())->toBe([4])
        ->and($entriesByType['exercise']->pluck('duration_hours')->unique()->all())->toBe([4])
        ->and($result['unscheduled'])->toBeEmpty();

    $snapshot = json_decode(
        DB::table('schedule_revisions')->orderByDesc('revision_no')->value('snapshot'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($snapshot)->toHaveCount(4)
        ->and(collect($snapshot)->pluck('coverage_type')->sort()->values()->all())->toBe(['exercise', 'exercise', 'lecture', 'lecture']);
});

it('schedules a common flow lecture only once and exposes it to every member group', function () {
    $fixtures = createScheduleFixtures();
    $otherSpecialty = Specialty::query()->create([
        'name' => 'Графичен дизайн',
        'code' => 'GD',
        'degree' => 'Бакалавър',
    ]);
    $otherVersion = CurriculumVersion::query()->create([
        'specialty_id' => $otherSpecialty->id,
        'name' => 'План 2026',
        'version_year' => 2026,
        'is_active' => true,
    ]);
    $otherGroup = StudentGroup::query()->create([
        'specialty_id' => $otherSpecialty->id,
        'curriculum_version_id' => $otherVersion->id,
        'course_year' => 1,
        'group_name' => 'Б',
        'student_count' => 18,
    ]);
    CurriculumSubject::query()->create([
        'curriculum_version_id' => $otherVersion->id,
        'subject_id' => $fixtures['subject']->id,
        'semester_no' => 1,
        'course_year' => 1,
    ]);
    $flow = StudentFlow::query()->create(['name' => 'Общи лекции']);
    $flow->groups()->sync([$fixtures['group']->id, $otherGroup->id]);
    TeacherAssignment::query()->create([
        'teacher_id' => $fixtures['teacher']->id,
        'subject_id' => $fixtures['subject']->id,
        'curriculum_version_id' => $fixtures['curriculumVersion']->id,
        'course_year' => 1,
        'semester' => 'winter',
        'coverage_type' => 'lecture',
        'student_flow_id' => $flow->id,
    ]);

    $service = app(ScheduleService::class);
    $first = $service->generateForGroup($fixtures['group'], '2026/2027', 'winter');
    $second = $service->generateForGroup($otherGroup, '2026/2027', 'winter');

    expect($first['entries'])->toHaveCount(3)
        ->and($second['entries'])->toBeEmpty()
        ->and(TimetableEntry::query()->where('student_flow_id', $flow->id)->count())->toBe(3);

    $admin = createScheduleAdmin();
    $this->actingAs($admin)
        ->get(route('schedules.index', [
            'group_id' => $otherGroup->id,
            'academic_year' => '2026/2027',
            'semester_type' => 'winter',
        ]))
        ->assertOk()
        ->assertSee('Общ поток: Общи лекции');
});

it('allows administrators to create shared student flows for group lectures', function () {
    $fixtures = createScheduleFixtures();
    $secondGroup = StudentGroup::query()->create([
        'specialty_id' => $fixtures['specialty']->id,
        'curriculum_version_id' => $fixtures['curriculumVersion']->id,
        'course_year' => 1,
        'group_name' => 'Б',
        'student_count' => 18,
    ]);
    $admin = createScheduleAdmin();

    $this->actingAs($admin)
        ->post(route('catalog.student-flows.store'), [
            'name' => 'Поток лекции',
            'student_group_ids' => [$fixtures['group']->id, $secondGroup->id],
        ])
        ->assertRedirect(route('catalog.index', ['tab' => 'organization']));

    $flow = StudentFlow::query()->firstOrFail();
    expect($flow->groups()->count())->toBe(2);

    $this->patch(route('catalog.student-flows.update', $flow), [
        'name' => 'Общи лекции',
        'student_group_ids' => [$fixtures['group']->id, $secondGroup->id],
    ])->assertRedirect(route('catalog.index', ['tab' => 'organization']));
    expect($flow->fresh()->name)->toBe('Общи лекции');
});

it('updates timetable time ranges and duration directly', function () {
    $fixtures = createScheduleFixtures();
    $entry = TimetableEntry::query()->create([
        'group_id' => $fixtures['group']->id,
        'subject_id' => $fixtures['subject']->id,
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
        'cycle_week' => 1,
        'weekday_no' => 1,
        'start_time' => '08:00:00',
        'end_time' => '11:45:00',
        'duration_hours' => 4,
        'locked' => false,
    ]);
    $admin = createScheduleAdmin();

    $this->actingAs($admin)
        ->patch(route('schedules.update', $entry), [
            'cycle_week' => 2,
            'weekday_no' => 6,
            'start_time' => '14:00',
            'end_time' => '18:45',
            'duration_hours' => 5,
            'teacher_id' => '',
            'room_id' => '',
            'locked' => 0,
            'group_id' => $fixtures['group']->id,
            'academic_year' => '2026/2027',
            'semester_type' => 'winter',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('timetable_entries', [
        'id' => $entry->id,
        'cycle_week' => 2,
        'weekday_no' => 6,
        'start_time' => '14:00:00',
        'end_time' => '18:45:00',
        'duration_hours' => 5,
    ]);
});

it('shows schedule diagnostics only after the explicit test action', function () {
    $fixtures = createScheduleFixtures();
    TeacherAssignment::query()->delete();
    $admin = createScheduleAdmin();
    $filters = [
        'group_id' => $fixtures['group']->id,
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
    ];

    $this->actingAs($admin)
        ->post(route('schedules.generate'), $filters)
        ->assertRedirect();

    $this->get(route('schedules.index', $filters))
        ->assertOk()
        ->assertSee('Тествай разписанието')
        ->assertDontSee('Забележки и препоръки от проверката')
        ->assertDontSee('Няма зададен преподавател.');

    $this->post(route('schedules.test'), $filters)
        ->assertRedirect(route('schedules.index', $filters));

    $this->get(route('schedules.index', $filters))
        ->assertOk()
        ->assertSee('Забележки и препоръки от проверката')
        ->assertSee('Няма зададен преподавател.');
});

it('shows all three weeks as drop targets and lists all distinct subject teachers without coverage labels', function () {
    $fixtures = createScheduleFixtures();
    $exerciseTeacher = Teacher::query()->create([
        'first_name' => 'Мария',
        'last_name' => 'Петрова',
        'max_daily_hours' => 8,
        'weekly_workload_hours' => 12,
        'active' => true,
    ]);
    foreach ([
        ['week' => 1, 'day' => 1, 'teacher_id' => $fixtures['teacher']->id, 'coverage' => 'lecture'],
        ['week' => 2, 'day' => 2, 'teacher_id' => $exerciseTeacher->id, 'coverage' => 'exercise'],
    ] as $lesson) {
        TimetableEntry::query()->create([
            'group_id' => $fixtures['group']->id,
            'subject_id' => $fixtures['subject']->id,
            'teacher_id' => $lesson['teacher_id'],
            'academic_year' => '2026/2027',
            'semester_type' => 'winter',
            'cycle_week' => $lesson['week'],
            'weekday_no' => $lesson['day'],
            'start_time' => '08:00:00',
            'end_time' => '11:45:00',
            'duration_hours' => 4,
            'coverage_type' => $lesson['coverage'],
            'locked' => false,
        ]);
    }

    $admin = createScheduleAdmin();
    $this->actingAs($admin)
        ->get(route('schedules.index', [
            'group_id' => $fixtures['group']->id,
            'academic_year' => '2026/2027',
            'semester_type' => 'winter',
        ]))
        ->assertOk()
        ->assertSee('aria-label="Разписание за седмица 1"', false)
        ->assertSee('aria-label="Разписание за седмица 2"', false)
        ->assertSee('aria-label="Разписание за седмица 3"', false)
        ->assertSee('data-drop-week="3"', false)
        ->assertSee('Иван Иванов, Мария Петрова')
        ->assertDontSee('Рисуване · Лекции')
        ->assertDontSee('Рисуване · Упражнения');

    TimetableEntry::query()->update(['teacher_id' => $fixtures['teacher']->id]);

    $this->get(route('schedules.index', [
        'group_id' => $fixtures['group']->id,
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
    ]))
        ->assertOk()
        ->assertDontSee('Иван Иванов, Иван Иванов');
});

it('allocates the 45-hour example in one recurring week and counts excess as self-study', function () {
    $fixtures = createScheduleFixtures();
    $exerciseTeacher = Teacher::query()->create([
        'first_name' => 'Георги',
        'last_name' => 'Млячков',
        'max_daily_hours' => 8,
        'weekly_workload_hours' => 12,
        'active' => true,
    ]);
    $fixtures['subject']->update([
        'auditorium_hours' => 45,
        'lecture_hours' => 15,
        'exercise_hours' => 30,
        'self_study_hours' => 5,
    ]);
    TeacherAssignment::query()->create([
        'teacher_id' => $exerciseTeacher->id,
        'subject_id' => $fixtures['subject']->id,
        'curriculum_version_id' => $fixtures['curriculumVersion']->id,
        'course_year' => 1,
        'semester' => 'winter',
        'coverage_type' => 'exercise',
    ]);

    $result = app(ScheduleService::class)->generateForGroup($fixtures['group'], '2026/2027', 'winter');
    $analysis = $result['analysis'][0];
    $entries = collect($result['entries']);

    expect($entries)->toHaveCount(3)
        ->and($entries->pluck('coverage_type')->countBy()->all())->toBe(['lecture' => 1, 'exercise' => 2])
        ->and($entries->pluck('cycle_week')->unique())->toHaveCount(1)
        ->and($entries->pluck('weekday_no')->sort()->values()->all())->toBe(range(
            $entries->min('weekday_no'),
            $entries->min('weekday_no') + 2,
        ))
        ->and($entries->pluck('duration_hours')->unique()->all())->toBe([4])
        ->and($analysis['auditorium_hours'])->toBe(45)
        ->and($analysis['scheduled_hours'])->toBe(60)
        ->and($analysis['self_study_hours'])->toBe(15)
        ->and($result['warnings'][0]['reason'])->toContain('добавя 10 ч. самоподготовка');
});

it('adds self-study hours to the scheduled workload', function () {
    $fixtures = createScheduleFixtures();
    $fixtures['subject']->update(['auditorium_hours' => 60, 'lecture_hours' => 60, 'self_study_hours' => 40]);
    $fixtures['teacher']->update(['weekly_workload_hours' => 0]);

    $result = app(ScheduleService::class)->generateForGroup($fixtures['group'], '2026/2027', 'winter');

    expect($result['entries'])->toHaveCount(5)
        ->and(collect($result['entries'])->max('weekday_no'))->toBeLessThanOrEqual(5);
});

it('moves an entry between weeks without copying it or blocking the freed slot', function () {
    $fixtures = createScheduleFixtures();
    $service = app(ScheduleService::class);
    $entry = $service->addManualEntry([
        'group_id' => $fixtures['group']->id,
        'subject_id' => null,
        'custom_subject_name' => 'Тест',
        'teacher_id' => null,
        'room_id' => null,
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
        'cycle_week' => 1,
        'weekday_no' => 1,
        'start_time' => '08:00:00',
        'end_time' => '11:45:00',
        'duration_hours' => 4,
        'note' => null,
    ]);
    $service->moveEntry($entry, [
        'cycle_week' => 2,
        'weekday_no' => 2,
        'start_time' => '13:00:00',
        'end_time' => '16:45:00',
        'duration_hours' => 4,
        'teacher_id' => null,
        'room_id' => null,
        'locked' => true,
    ]);

    expect(TimetableEntry::query()->count())->toBe(1)
        ->and($entry->refresh()->cycle_week)->toBe(2);
});

it('keeps hours missing from the lecture and exercise split unassigned instead of guessing', function () {
    $fixtures = createScheduleFixtures();
    $fixtures['subject']->update([
        'auditorium_hours' => 50,
        'lecture_hours' => 20,
        'exercise_hours' => 20,
    ]);
    TeacherAssignment::query()->create([
        'teacher_id' => $fixtures['teacher']->id,
        'subject_id' => $fixtures['subject']->id,
        'curriculum_version_id' => $fixtures['curriculumVersion']->id,
        'course_year' => 1,
        'semester' => 'winter',
        'coverage_type' => 'exercise',
    ]);

    $result = app(ScheduleService::class)->generateForGroup($fixtures['group'], '2026/2027', 'winter');
    $unsplitEntry = collect($result['entries'])->firstWhere('coverage_type', null);

    expect($result['entries'])->toHaveCount(3)
        ->and($unsplitEntry)->not->toBeNull()
        ->and($unsplitEntry->teacher_id)->toBeNull()
        ->and(collect($result['warnings'])->pluck('reason')->join(' '))->toContain('без преподавател')
        ->and($result['analysis'][0]['scheduled_hours'])->toBe(60)
        ->and($result['analysis'][0]['self_study_hours'])->toBe(10);
});

it('preserves legacy schedule generation when no teacher assignment exists', function () {
    $fixtures = createScheduleFixtures();
    TeacherAssignment::query()->where('teacher_id', $fixtures['teacher']->id)->delete();

    $result = app(ScheduleService::class)->generateForGroup($fixtures['group'], '2026/2027', 'winter');

    expect($result['entries'])->toHaveCount(3)
        ->and(collect($result['entries'])->pluck('teacher_id')->unique()->all())->toBe([null])
        ->and(collect($result['warnings'])->pluck('reason')->join(' '))->toContain('Няма активен преподавател')
        ->and($result['unscheduled'])->toBeEmpty();
});

it('lets administrators update subject scheduling hours and control type', function () {
    $fixtures = createScheduleFixtures();
    $admin = createScheduleAdmin();

    $this->actingAs($admin)
        ->patch(route('catalog.subjects.update', $fixtures['subject']), [
            'code' => $fixtures['subject']->code,
            'name' => $fixtures['subject']->name,
            'auditorium_hours' => 60,
            'lecture_hours' => 30,
            'exercise_hours' => 30,
            'self_study_hours' => 30,
            'control_type' => 'И',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('subjects', [
        'id' => $fixtures['subject']->id,
        'auditorium_hours' => 60,
        'lecture_hours' => 30,
        'exercise_hours' => 30,
        'self_study_hours' => 30,
        'control_type' => 'И',
    ]);
});

it('removes time-block management and supports a public global notice', function () {
    $admin = createScheduleAdmin();

    $this->actingAs($admin)
        ->put(route('settings.update'), ['public_schedule_notice' => 'Промяна на зали'])
        ->assertRedirect(route('settings.index'));

    $this->get(route('catalog.index'))
        ->assertOk()
        ->assertDontSee('Времеви блокове');

    $this->get(route('settings.index'))
        ->assertOk()
        ->assertSee('Съобщение към студентите');
    $this->get(route('home'))->assertSee('Промяна на зали');
    $this->assertDatabaseHas('software_settings', [
        'key' => 'public_schedule_notice',
        'value' => 'Промяна на зали',
    ]);
});

it('adds a subjectless Sunday entry and keeps a schedule note', function () {
    $fixtures = createScheduleFixtures();
    $fixtures['curriculumSubject']->update([
        'source_data' => ['columns' => ['Semester' => '1']],
    ]);
    $fixtures['subject']->update([
        'auditorium_hours' => 60,
        'lecture_hours' => 30,
        'exercise_hours' => 30,
    ]);
    $summerSubject = Subject::query()->create([
        'code' => 'SUM-101',
        'name' => 'Лятна предмет',
    ]);
    CurriculumSubject::query()->create([
        'curriculum_version_id' => $fixtures['curriculumVersion']->id,
        'subject_id' => $summerSubject->id,
        'semester_no' => 2,
        'course_year' => 1,
    ]);
    $admin = createScheduleAdmin();
    $this->actingAs($admin)
        ->post(route('schedules.entries.store'), [
            'group_id' => $fixtures['group']->id,
            'academic_year' => '2026/2027',
            'semester_type' => 'winter',
            'cycle_week' => 1,
            'weekday_no' => 7,
            'start_time' => '08:00',
            'end_time' => '11:45',
            'duration_hours' => 4,
            'custom_subject_name' => 'Магистърска консултация',
            'note' => 'Допълнено ръчно',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('timetable_entries', [
        'group_id' => $fixtures['group']->id,
        'subject_id' => null,
        'custom_subject_name' => 'Магистърска консултация',
        'weekday_no' => 7,
        'locked' => true,
    ]);

    $this->put(route('schedules.note.update'), [
        'group_id' => $fixtures['group']->id,
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
        'cycle_week' => 1,
        'note' => 'Графикът подлежи на ръчни корекции.',
    ])->assertRedirect();

    $this->get(route('schedules.index', [
        'group_id' => $fixtures['group']->id,
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
    ]))->assertOk()
        ->assertSee('Магистърска консултация')
        ->assertSee('Графикът подлежи на ръчни корекции.')
        ->assertSee('АЗ 60')
        ->assertSee('Л 30')
        ->assertSee('У 30')
        ->assertDontSee('Лятна предмет');
});

it('allows a group without a curriculum to hold manual Sunday Masters entries', function () {
    $admin = createScheduleAdmin();
    $specialty = Specialty::query()->create([
        'name' => 'Магистърска програма',
        'code' => 'MP',
        'degree' => 'Магистър',
    ]);

    $this->actingAs($admin)
        ->post(route('catalog.groups.store'), [
            'specialty_id' => $specialty->id,
            'course_year' => 1,
            'group_name' => 'Магистри',
            'student_count' => 12,
        ])
        ->assertRedirect(route('catalog.index', ['tab' => 'organization']));

    $group = StudentGroup::query()->where('group_name', 'Магистри')->firstOrFail();
    $this->assertNull($group->curriculum_version_id);
    $this->post(route('schedules.entries.store'), [
        'group_id' => $group->id,
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
        'cycle_week' => 1,
        'weekday_no' => 7,
        'start_time' => '08:00',
        'end_time' => '11:45',
        'duration_hours' => 4,
        'custom_subject_name' => 'Магистърски семинар',
        'note' => 'Добавен ръчно',
    ])->assertRedirect();

    $this->get(route('schedules.index', [
        'group_id' => $group->id,
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
        'cycle_week' => 1,
    ]))->assertOk()
        ->assertSee('Магистърски семинар')
        ->assertSee('Добавен ръчно')
        ->assertSee('Групата няма учебен план. Добавяйте занятията ръчно.')
        ->assertDontSee('Генерирай отново');
});

it('rejects manual entries that overlap a teacher absence', function () {
    $fixtures = createScheduleFixtures();
    $admin = createScheduleAdmin();
    $this->actingAs($admin)
        ->post(route('catalog.teachers.absences.store', $fixtures['teacher']), [
            'starts_on' => '2026-09-07',
            'starts_time' => '09:00',
            'ends_on' => '2026-09-07',
            'ends_time' => '10:00',
            'reason' => 'Еразъм',
        ])
        ->assertRedirect();

    $this->post(route('schedules.entries.store'), [
        'group_id' => $fixtures['group']->id,
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
        'cycle_week' => 2,
        'weekday_no' => 1,
        'start_time' => '08:00',
        'end_time' => '11:45',
        'duration_hours' => 4,
        'custom_subject_name' => 'Гостуваща лекция',
        'teacher_id' => $fixtures['teacher']->id,
    ])->assertSessionHasErrors('schedule');
});

it('prioritizes preferred days and avoids explicitly unavailable weekdays', function () {
    $fixtures = createScheduleFixtures();
    $fixtures['subject']->update(['auditorium_hours' => 15, 'lecture_hours' => 15]);
    $fixtures['teacher']->dayPreferences()->where('cycle_week', 1)->where('weekday_no', 1)->update(['preference' => 'unavailable']);
    $fixtures['teacher']->dayPreferences()->where('cycle_week', 1)->where('weekday_no', 2)->update(['preference' => 'preferred']);

    $result = app(ScheduleService::class)->generateForGroup($fixtures['group'], '2026/2027', 'winter');

    expect($result['entries'])->toHaveCount(1)
        ->and($result['entries'][0]->weekday_no)->toBe(2)
        ->and($result['unscheduled'])->toBeEmpty();
});

it('generates and exports a group schedule', function () {
    $fixtures = createScheduleFixtures();
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
    $fixtures = createScheduleFixtures();
    $admin = createScheduleAdmin();
    $this->actingAs($admin);

    $entry = app(ScheduleService::class)
        ->generateForGroup($fixtures['group'], '2026/2027', 'winter')['entries'][0];

    app(ScheduleService::class)->moveEntry($entry, [
        'cycle_week' => 1,
        'weekday_no' => 5,
        'start_time' => '13:00:00',
        'end_time' => '17:45:00',
        'duration_hours' => 4,
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
        ->assertSee('Петък');

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
