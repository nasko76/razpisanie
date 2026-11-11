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

it('places newly generated individual lessons in the manual pool', function () {
    $fixtures = createScheduleFixtures();

    $result = app(ScheduleService::class)->generateForGroup($fixtures['group'], '2026/2027', 'winter');
    $entries = TimetableEntry::query()->where('group_id', $fixtures['group']->id)->get();

    expect($result['entries'])->toHaveCount(3)
        ->and($entries)->toHaveCount(3)
        ->and($entries->every(fn (TimetableEntry $entry): bool => $entry->is_in_pool && $entry->pool_reason === 'auto'))->toBeTrue()
        ->and($entries->where('is_in_pool', false))->toBeEmpty();
});

it('generates paired course groups in opposite dayparts without duplicating lessons', function () {
    $fixtures = createScheduleFixtures();
    $fixtures['subject']->update([
        'auditorium_hours' => 20,
        'lecture_hours' => 20,
        'exercise_hours' => 0,
    ]);
    $secondSubject = Subject::query()->create([
        'code' => 'MOD-101',
        'name' => 'Моделиране',
        'auditorium_hours' => 20,
        'lecture_hours' => 20,
        'exercise_hours' => 0,
    ]);
    CurriculumSubject::query()->create([
        'curriculum_version_id' => $fixtures['curriculumVersion']->id,
        'subject_id' => $secondSubject->id,
        'semester_no' => 1,
        'course_year' => 1,
    ]);
    $secondGroup = StudentGroup::query()->create([
        'specialty_id' => $fixtures['specialty']->id,
        'curriculum_version_id' => $fixtures['curriculumVersion']->id,
        'course_year' => 1,
        'group_name' => 'Б',
        'student_count' => 18,
    ]);

    $result = app(ScheduleService::class)->generateForGroup($fixtures['group'], '2026/2027', 'winter');
    $firstEntries = TimetableEntry::query()->where('group_id', $fixtures['group']->id)->get();
    $secondEntries = TimetableEntry::query()->where('group_id', $secondGroup->id)->get();

    expect($result['entries'])->toHaveCount(4)
        ->and($firstEntries)->toHaveCount(2)
        ->and($secondEntries)->toHaveCount(2)
        ->and($firstEntries->contains(fn (TimetableEntry $entry): bool => $entry->start_time < '12:00:00'))->toBeTrue()
        ->and($firstEntries->contains(fn (TimetableEntry $entry): bool => $entry->start_time >= '12:00:00'))->toBeTrue()
        ->and($secondEntries->contains(fn (TimetableEntry $entry): bool => $entry->start_time < '12:00:00'))->toBeTrue()
        ->and($secondEntries->contains(fn (TimetableEntry $entry): bool => $entry->start_time >= '12:00:00'))->toBeTrue();

    foreach ([$fixtures['subject'], $secondSubject] as $subject) {
        $firstEntry = $firstEntries->firstWhere('subject_id', $subject->id);
        $secondEntry = $secondEntries->firstWhere('subject_id', $subject->id);

        expect($firstEntry)->not->toBeNull()
            ->and($secondEntry)->not->toBeNull()
            ->and($secondEntry->cycle_week)->toBe($firstEntry->cycle_week)
            ->and($secondEntry->weekday_no)->toBe($firstEntry->weekday_no)
            ->and(($firstEntry->start_time < '12:00:00') !== ($secondEntry->start_time < '12:00:00'))->toBeTrue();
    }
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

it('uses imported teacher assignments only for their group and academic year', function () {
    $fixtures = createScheduleFixtures();
    TeacherAssignment::query()->delete();

    $otherGroup = StudentGroup::query()->create([
        'specialty_id' => $fixtures['specialty']->id,
        'curriculum_version_id' => $fixtures['curriculumVersion']->id,
        'course_year' => 2,
        'group_name' => 'Б',
        'student_count' => 18,
    ]);
    $otherTeacher = Teacher::query()->create([
        'first_name' => 'Петър',
        'last_name' => 'Петров',
        'max_daily_hours' => 8,
        'weekly_workload_hours' => 12,
        'active' => true,
    ]);
    $matchingTeacher = Teacher::query()->create([
        'first_name' => 'Мария',
        'last_name' => 'Маринова',
        'max_daily_hours' => 8,
        'weekly_workload_hours' => 12,
        'active' => true,
    ]);

    foreach ([
        [$fixtures['group'], $fixtures['teacher'], '2025/2026'],
        [$otherGroup, $otherTeacher, '2026/2027'],
    ] as [$group, $teacher, $academicYear]) {
        TeacherAssignment::query()->create([
            'teacher_id' => $teacher->id,
            'subject_id' => $fixtures['subject']->id,
            'curriculum_version_id' => $fixtures['curriculumVersion']->id,
            'student_group_id' => $group->id,
            'course_year' => $group->course_year,
            'semester' => 'winter',
            'coverage_type' => 'lecture',
            'academic_year' => $academicYear,
        ]);
    }

    $withoutMatchingAssignment = app(ScheduleService::class)->generateForGroup($fixtures['group'], '2026/2027', 'winter');
    expect(collect($withoutMatchingAssignment['entries'])->pluck('teacher_id')->unique()->all())->toBe([null]);

    TeacherAssignment::query()->create([
        'teacher_id' => $matchingTeacher->id,
        'subject_id' => $fixtures['subject']->id,
        'curriculum_version_id' => $fixtures['curriculumVersion']->id,
        'student_group_id' => $fixtures['group']->id,
        'course_year' => 1,
        'semester' => 'winter',
        'coverage_type' => 'lecture',
        'academic_year' => '2026/2027',
    ]);
    $withMatchingAssignment = app(ScheduleService::class)->generateForGroup($fixtures['group'], '2026/2027', 'winter');

    expect(collect($withMatchingAssignment['entries'])->pluck('teacher_id')->unique()->all())
        ->toBe([$matchingTeacher->id]);
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
    $lockedEntry->update(['locked' => true, 'is_in_pool' => false, 'pool_reason' => null]);

    $result = $service->generateForGroup($fixtures['group'], '2026/2027', 'winter');

    expect($result['entries'])->toBeEmpty()
        ->and($result['warnings'])->toHaveCount(1)
        ->and(TimetableEntry::query()->count())->toBe(1)
        ->and(TimetableEntry::query()->whereKey($lockedEntry->id)->value('locked'))->toBeTrue()
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
        ->assertDontSee('Специалности')
        ->assertDontSee('Редактиране')
        ->assertSee(route('catalog.teachers.show', $fixtures['teacher']));

    $this->get(route('catalog.teachers.show', $fixtures['teacher']))
        ->assertOk()
        ->assertSee('Изтрий преподавателя');

    $this->get(route('catalog.index', ['tab' => 'plans']))
        ->assertOk()
        ->assertSee(route('catalog.curriculum-versions.show', $fixtures['curriculumVersion']))
        ->assertSee('<summary class="record-summary">', false)
        ->assertDontSee('Редактиране')
        ->assertDontSee('Копирай план');

    $this->get(route('catalog.curriculum-versions.show', $fixtures['curriculumVersion']))
        ->assertOk()
        ->assertSee('Копирай план')
        ->assertSee('Архивирай')
        ->assertSee('Изтрий версията')
        ->assertDontSee('Редактиране на версията');

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

it('advances the current semester and archives a curriculum after semester four', function () {
    $fixtures = createScheduleFixtures();
    $admin = createScheduleAdmin();
    $this->actingAs($admin);

    foreach ([2, 3, 4] as $semester) {
        $this->post(route('catalog.curriculum-versions.advance-semester', $fixtures['curriculumVersion']))
            ->assertRedirect(route('catalog.curriculum-versions.show', $fixtures['curriculumVersion']));
        expect($fixtures['curriculumVersion']->fresh()->current_semester)->toBe($semester);
    }

    $this->post(route('catalog.curriculum-versions.advance-semester', $fixtures['curriculumVersion']))
        ->assertRedirect(route('catalog.curriculum-versions.show', $fixtures['curriculumVersion']));

    expect((bool) $fixtures['curriculumVersion']->fresh()->is_active)->toBeFalse()
        ->and($fixtures['curriculumVersion']->fresh()->current_semester)->toBe(4);
});

it('copies a previous winter schedule when its effective inputs are unchanged', function () {
    $fixtures = createScheduleFixtures();
    $service = app(ScheduleService::class);
    $service->generateForGroup($fixtures['group'], '2025/2026', 'winter');
    $entry = TimetableEntry::query()->where('group_id', $fixtures['group']->id)->firstOrFail();

    $service->moveEntry($entry, [
        'teacher_id' => $fixtures['teacher']->id,
        'room_id' => $fixtures['room']->id,
        'cycle_week' => $entry->cycle_week,
        'weekday_no' => $entry->weekday_no,
        'start_time' => $entry->start_time,
        'end_time' => $entry->end_time,
        'duration_hours' => $entry->duration_hours,
        'locked' => false,
        'is_in_pool' => false,
    ]);

    $result = $service->copyPreviousWinter($fixtures['group'], '2026/2027');

    expect($result)->toMatchArray(['copied' => 3, 'source_academic_year' => '2025/2026'])
        ->and(TimetableEntry::query()->where('group_id', $fixtures['group']->id)->where('academic_year', '2026/2027')->count())->toBe(3)
        ->and(TimetableEntry::query()->where('group_id', $fixtures['group']->id)->where('academic_year', '2026/2027')->where('is_in_pool', false)->count())->toBe(1);
});

it('refuses to copy a previous winter schedule after its plan changes', function () {
    $fixtures = createScheduleFixtures();
    $service = app(ScheduleService::class);
    $service->generateForGroup($fixtures['group'], '2025/2026', 'winter');
    $fixtures['curriculumSubject']->update(['semester_no' => 3]);

    expect(fn () => $service->copyPreviousWinter($fixtures['group'], '2026/2027'))
        ->toThrow(ValidationException::class, 'учебният план');
});

it('refuses to copy a previous winter schedule after its teacher assignment changes', function () {
    $fixtures = createScheduleFixtures();
    $service = app(ScheduleService::class);
    $service->generateForGroup($fixtures['group'], '2025/2026', 'winter');
    $replacementTeacher = Teacher::query()->create([
        'first_name' => 'Мария',
        'last_name' => 'Петрова',
        'max_daily_hours' => 8,
        'weekly_workload_hours' => 12,
        'active' => true,
    ]);
    TeacherAssignment::query()->where('teacher_id', $fixtures['teacher']->id)->update([
        'teacher_id' => $replacementTeacher->id,
    ]);

    expect(fn () => $service->copyPreviousWinter($fixtures['group'], '2026/2027'))
        ->toThrow(ValidationException::class, 'преподавателските възлагания');
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

    expect(TimetableEntry::query()->where('group_id', $fixtures['group']->id)->where('is_in_pool', true)->count())->toBe(4);
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
    $flow = StudentFlow::query()->create([
        'name' => 'Общи лекции',
        'subject_id' => $fixtures['subject']->id,
    ]);
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
    $flowEntry = TimetableEntry::query()->where('student_flow_id', $flow->id)->firstOrFail();
    $service->moveEntry($flowEntry, [
        'teacher_id' => $fixtures['teacher']->id,
        'room_id' => null,
        'cycle_week' => 1,
        'weekday_no' => 2,
        'start_time' => '08:00:00',
        'end_time' => '11:45:00',
        'duration_hours' => 4,
        'locked' => false,
        'is_in_pool' => false,
    ]);
    $second = $service->generateForGroup($otherGroup, '2026/2027', 'winter');

    expect($first['entries'])->toBeEmpty()
        ->and($second['entries'])->toBeEmpty()
        ->and(TimetableEntry::query()->where('student_flow_id', $flow->id)->count())->toBe(3)
        ->and(TimetableEntry::query()->where('student_flow_id', $flow->id)->where('is_in_pool', true)->count())->toBe(2)
        ->and(TimetableEntry::query()->where('student_flow_id', $flow->id)->where('is_in_pool', false)->value('weekday_no'))->toBe(2)
        ->and(TimetableEntry::query()->where('student_flow_id', $flow->id)->where('is_in_pool', false)->value('start_time'))->toBe('08:00:00');

    $admin = createScheduleAdmin();
    $this->actingAs($admin)
        ->get(route('schedules.index', [
            'group_id' => $otherGroup->id,
            'academic_year' => '2026/2027',
            'semester_type' => 'winter',
        ]))
        ->assertOk()
        ->assertSee('Общ поток: Общи лекции');
    $this->get(route('schedules.index', [
        'group_id' => $fixtures['group']->id,
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
    ]))->assertOk()->assertSee('Общ поток: Общи лекции');

    TimetableEntry::query()->create([
        'group_id' => $otherGroup->id,
        'custom_subject_name' => 'Заето занятие',
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
        'cycle_week' => 1,
        'weekday_no' => 7,
        'start_time' => '08:00:00',
        'end_time' => '11:45:00',
        'duration_hours' => 4,
        'locked' => true,
    ]);
    $fixtures['teacher']->dayPreferences()
        ->where('cycle_week', 1)
        ->where('weekday_no', 7)
        ->update(['preference' => 'neutral']);
    $pendingFlowEntry = TimetableEntry::query()
        ->where('student_flow_id', $flow->id)
        ->where('is_in_pool', true)
        ->firstOrFail();
    $this->from(route('schedules.index', ['group_id' => $fixtures['group']->id]))
        ->actingAs($admin)
        ->patch(route('schedules.update', $pendingFlowEntry), [
            'cycle_week' => 1,
            'weekday_no' => 7,
            'start_time' => '08:00',
            'end_time' => '11:45',
            'duration_hours' => 4,
            'teacher_id' => $fixtures['teacher']->id,
            'room_id' => '',
            'coverage_type' => 'lecture',
            'student_flow_id' => $flow->id,
            'locked' => 0,
            'is_in_pool' => 0,
        ])
        ->assertSessionHasErrors('schedule');
    expect($pendingFlowEntry->fresh()->is_in_pool)->toBeTrue();
    $this->get(route('catalog.curriculum-versions.show', $otherVersion))
        ->assertOk()
        ->assertSee('Иван Иванов')
        ->assertSee('Общи лекции');
});

it('treats a created shared flow as a pool item even without a flow-specific teacher assignment', function () {
    $fixtures = createScheduleFixtures();
    $secondGroup = StudentGroup::query()->create([
        'specialty_id' => $fixtures['specialty']->id,
        'curriculum_version_id' => $fixtures['curriculumVersion']->id,
        'course_year' => 1,
        'group_name' => 'Б',
        'student_count' => 18,
    ]);
    $flow = StudentFlow::query()->create([
        'name' => 'Общи лекции',
        'subject_id' => $fixtures['subject']->id,
    ]);
    $flow->groups()->sync([$fixtures['group']->id, $secondGroup->id]);

    $result = app(ScheduleService::class)->generateForGroup($fixtures['group'], '2026/2027', 'winter');

    expect($result['entries'])->toBeEmpty()
        ->and(TimetableEntry::query()->where('student_flow_id', $flow->id)->where('is_in_pool', true)->count())->toBe(3)
        ->and(TimetableEntry::query()->where('subject_id', $fixtures['subject']->id)->whereNull('student_flow_id')->where('is_in_pool', false)->count())->toBe(0);
});

it('syncs a flow assigned from the timetable to every member group and pools the old lectures', function () {
    $fixtures = createScheduleFixtures();
    $secondGroup = StudentGroup::query()->create([
        'specialty_id' => $fixtures['specialty']->id,
        'curriculum_version_id' => $fixtures['curriculumVersion']->id,
        'course_year' => 1,
        'group_name' => 'Б',
        'student_count' => 18,
    ]);
    app(ScheduleService::class)->generateForGroup($fixtures['group'], '2026/2027', 'winter');
    TimetableEntry::query()
        ->where('group_id', $fixtures['group']->id)
        ->where('subject_id', $fixtures['subject']->id)
        ->update(['is_in_pool' => false, 'pool_reason' => null]);
    $flow = StudentFlow::query()->create([
        'name' => 'Общи лекции',
        'subject_id' => $fixtures['subject']->id,
    ]);
    $flow->groups()->sync([$fixtures['group']->id, $secondGroup->id]);
    $entry = TimetableEntry::query()
        ->where('group_id', $fixtures['group']->id)
        ->where('subject_id', $fixtures['subject']->id)
        ->whereNull('student_flow_id')
        ->where('is_in_pool', false)
        ->firstOrFail();
    $fixtures['teacher']->dayPreferences()
        ->where('cycle_week', 1)
        ->where('weekday_no', 7)
        ->update(['preference' => 'neutral']);

    app(ScheduleService::class)->moveEntry($entry, [
        'teacher_id' => $fixtures['teacher']->id,
        'room_id' => null,
        'cycle_week' => 1,
        'weekday_no' => 7,
        'start_time' => '08:00:00',
        'end_time' => '11:45:00',
        'duration_hours' => 4,
        'coverage_type' => 'lecture',
        'student_flow_id' => $flow->id,
        'locked' => false,
        'is_in_pool' => false,
    ]);

    expect(TimetableEntry::query()->where('student_flow_id', $flow->id)->where('is_in_pool', false)->count())->toBe(1)
        ->and(TimetableEntry::query()->where('student_flow_id', $flow->id)->where('is_in_pool', true)->count())->toBe(2)
        ->and(TimetableEntry::query()->where('subject_id', $fixtures['subject']->id)->whereNull('student_flow_id')->where('is_in_pool', false)->count())->toBe(0);
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
            'subject_id' => $fixtures['subject']->id,
            'student_group_ids' => [$fixtures['group']->id, $secondGroup->id],
        ])
        ->assertRedirect(route('catalog.index', ['tab' => 'organization']));

    $flow = StudentFlow::query()->firstOrFail();
    expect($flow->groups()->count())->toBe(2);

    $this->patch(route('catalog.student-flows.update', $flow), [
        'name' => 'Общи лекции',
        'subject_id' => $fixtures['subject']->id,
        'student_group_ids' => [$fixtures['group']->id, $secondGroup->id],
    ])->assertRedirect(route('catalog.index', ['tab' => 'organization']));
    expect($flow->fresh()->name)->toBe('Общи лекции');

    $this->delete(route('catalog.student-flows.destroy', $flow))
        ->assertRedirect(route('catalog.index', ['tab' => 'organization']));
    $this->assertDatabaseMissing('student_flows', ['id' => $flow->id]);
});

it('deletes the requested catalog records and leaves groups without a deleted curriculum', function () {
    $fixtures = createScheduleFixtures();
    $admin = createScheduleAdmin();
    $this->actingAs($admin);

    $this->delete(route('catalog.teachers.destroy', $fixtures['teacher']))
        ->assertRedirect(route('catalog.index', ['tab' => 'teachers']));
    $this->assertDatabaseMissing('teachers', ['id' => $fixtures['teacher']->id]);

    $this->delete(route('catalog.subjects.destroy', $fixtures['subject']))
        ->assertRedirect(route('catalog.index', ['tab' => 'subjects']));
    $this->assertDatabaseMissing('subjects', ['id' => $fixtures['subject']->id]);

    $this->delete(route('catalog.curriculum-versions.destroy', $fixtures['curriculumVersion']))
        ->assertRedirect(route('catalog.index', ['tab' => 'plans']));
    $this->assertDatabaseHas('student_groups', [
        'id' => $fixtures['group']->id,
        'curriculum_version_id' => null,
    ]);

    $this->delete(route('catalog.groups.destroy', $fixtures['group']))
        ->assertRedirect(route('catalog.index', ['tab' => 'organization']));
    $this->assertDatabaseMissing('student_groups', ['id' => $fixtures['group']->id]);

    $this->delete(route('catalog.specialties.destroy', $fixtures['specialty']))
        ->assertRedirect(route('catalog.index', ['tab' => 'plans']));
    $this->assertDatabaseMissing('specialties', ['id' => $fixtures['specialty']->id]);
});

it('allows unnamed groups and keeps the curriculum detail link singular', function () {
    $fixtures = createScheduleFixtures();
    $admin = createScheduleAdmin();

    $this->actingAs($admin)
        ->post(route('catalog.groups.store'), [
            'specialty_id' => $fixtures['specialty']->id,
            'curriculum_version_id' => $fixtures['curriculumVersion']->id,
            'course_year' => 2,
            'group_name' => '',
            'student_count' => 20,
        ])
        ->assertRedirect(route('catalog.index', ['tab' => 'organization']));

    $unnamedGroup = StudentGroup::query()->where('course_year', 2)->firstOrFail();
    expect($unnamedGroup->group_name)->toBeNull()
        ->and($unnamedGroup->display_name)->toBe('2 курс');

    $response = $this->get(route('catalog.index', ['tab' => 'plans']))
        ->assertOk()
        ->assertSee(route('catalog.curriculum-versions.show', $fixtures['curriculumVersion']));

    expect(substr_count($response->getContent(), route('catalog.curriculum-versions.show', $fixtures['curriculumVersion'])))->toBe(1)
        ->and(substr_count($response->getContent(), 'Редактиране'))->toBe(0);

    $this->get(route('catalog.index', ['tab' => 'organization']))
        ->assertOk()
        ->assertSee('2 курс');
});

it('rejects shared flows when a selected course does not include that subject', function () {
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
        'student_count' => 18,
    ]);

    $this->actingAs(createScheduleAdmin())
        ->from(route('catalog.index', ['tab' => 'organization']))
        ->post(route('catalog.student-flows.store'), [
            'name' => 'Невалиден общ поток',
            'subject_id' => $fixtures['subject']->id,
            'student_group_ids' => [$fixtures['group']->id, $otherGroup->id],
        ])
        ->assertSessionHasErrors('student_group_ids');

    expect(StudentFlow::query()->count())->toBe(0);
});

it('saves free timetable times and calculates teaching hours from the clock times', function () {
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
            'start_time' => '11:00',
            'end_time' => '16:45',
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
        'start_time' => '11:00:00',
        'end_time' => '16:45:00',
        'duration_hours' => 6,
    ]);
    expect(app(ScheduleService::class)->teachingHours('08:00', '10:45'))->toBe(3);
});

it('refreshes lesson counts in the pool without moving existing schedule entries', function () {
    $fixtures = createScheduleFixtures();
    $service = app(ScheduleService::class);
    $generated = $service->generateForGroup($fixtures['group'], '2026/2027', 'winter');
    TimetableEntry::query()
        ->where('group_id', $fixtures['group']->id)
        ->whereNull('student_flow_id')
        ->update(['is_in_pool' => false, 'pool_reason' => null]);
    $originalSlots = collect($generated['entries'])->map(fn (TimetableEntry $entry): array => [
        $entry->cycle_week,
        $entry->weekday_no,
        $entry->start_time,
        $entry->end_time,
    ])->all();

    $replacementTeacher = Teacher::query()->create([
        'first_name' => 'Мария',
        'last_name' => 'Петрова',
        'max_daily_hours' => 8,
        'weekly_workload_hours' => 12,
        'active' => true,
    ]);
    TeacherAssignment::query()
        ->where('subject_id', $fixtures['subject']->id)
        ->update(['teacher_id' => $replacementTeacher->id]);
    $fixtures['subject']->update(['auditorium_hours' => 100, 'lecture_hours' => 100]);
    $result = $service->refreshForGroup($fixtures['group'], '2026/2027', 'winter');
    $placedEntries = TimetableEntry::query()
        ->where('group_id', $fixtures['group']->id)
        ->where('is_in_pool', false)
        ->orderBy('cycle_week')
        ->orderBy('weekday_no')
        ->orderBy('start_time')
        ->get();

    expect($result['added'])->toBe(2)
        ->and($placedEntries->map(fn (TimetableEntry $entry): array => [
            $entry->cycle_week,
            $entry->weekday_no,
            $entry->start_time,
            $entry->end_time,
        ])->all())->toBe($originalSlots)
        ->and($placedEntries->pluck('teacher_id')->unique()->all())->toBe([$replacementTeacher->id])
        ->and(TimetableEntry::query()->where('group_id', $fixtures['group']->id)->where('is_in_pool', true)->count())->toBe(2);
});

it('provides a reload action that reports the reconciliation result', function () {
    $fixtures = createScheduleFixtures();
    $filters = [
        'group_id' => $fixtures['group']->id,
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
    ];
    app(ScheduleService::class)->generateForGroup($fixtures['group'], $filters['academic_year'], $filters['semester_type']);
    $fixtures['subject']->update(['auditorium_hours' => 80, 'lecture_hours' => 80]);

    $this->actingAs(createScheduleAdmin())
        ->post(route('schedules.refresh'), $filters)
        ->assertRedirect(route('schedules.index', $filters))
        ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'Добавени в пула: 1.'));
});

it('adds a newly selected elective as a pool block during refresh', function () {
    $fixtures = createScheduleFixtures();
    $elective = Subject::query()->create([
        'code' => 'EL-201',
        'name' => 'Избираема дисциплина',
        'auditorium_hours' => 20,
        'lecture_hours' => 20,
    ]);
    CurriculumSubject::query()->create([
        'curriculum_version_id' => $fixtures['curriculumVersion']->id,
        'subject_id' => $elective->id,
        'semester_no' => 1,
        'course_year' => 1,
        'subject_category' => 'elective',
        'is_selected' => false,
    ]);
    $service = app(ScheduleService::class);
    $service->generateForGroup($fixtures['group'], '2026/2027', 'winter');
    TimetableEntry::query()
        ->where('group_id', $fixtures['group']->id)
        ->where('subject_id', $fixtures['subject']->id)
        ->update(['is_in_pool' => false, 'pool_reason' => null]);
    $electiveCurriculumSubject = CurriculumSubject::query()->where('subject_id', $elective->id)->firstOrFail();
    $electiveCurriculumSubject->update(['is_selected' => true]);

    $result = $service->refreshForGroup($fixtures['group'], '2026/2027', 'winter');

    expect($result['added'])->toBe(1)
        ->and(TimetableEntry::query()->where('subject_id', $elective->id)->where('is_in_pool', true)->count())->toBe(1)
        ->and(TimetableEntry::query()->where('subject_id', $fixtures['subject']->id)->where('is_in_pool', false)->count())->toBe(3);
});

it('moves individual lecture blocks to the pool when the subject joins a shared flow', function () {
    $fixtures = createScheduleFixtures();
    $secondGroup = StudentGroup::query()->create([
        'specialty_id' => $fixtures['specialty']->id,
        'curriculum_version_id' => $fixtures['curriculumVersion']->id,
        'course_year' => 1,
        'group_name' => 'Б',
        'student_count' => 18,
    ]);
    $service = app(ScheduleService::class);
    $service->generateForGroup($fixtures['group'], '2026/2027', 'winter');
    TimetableEntry::query()
        ->where('group_id', $fixtures['group']->id)
        ->where('subject_id', $fixtures['subject']->id)
        ->update(['is_in_pool' => false, 'pool_reason' => null]);
    $flow = StudentFlow::query()->create(['name' => 'Общи лекции', 'subject_id' => $fixtures['subject']->id]);
    $flow->groups()->sync([$fixtures['group']->id, $secondGroup->id]);

    TeacherAssignment::query()->create([
        'teacher_id' => $fixtures['teacher']->id,
        'subject_id' => $fixtures['subject']->id,
        'course_year' => 1,
        'semester' => 'winter',
        'coverage_type' => 'lecture',
        'student_flow_id' => $flow->id,
    ]);

    $result = $service->refreshForGroup($fixtures['group'], '2026/2027', 'winter');

    expect($result['added'])->toBe(3)
        ->and($result['pooled'])->toBe(3)
        ->and(TimetableEntry::query()->where('group_id', $fixtures['group']->id)->where('subject_id', $fixtures['subject']->id)->where('is_in_pool', true)->count())->toBe(6)
        ->and(TimetableEntry::query()->where('student_flow_id', $flow->id)->where('is_in_pool', true)->count())->toBe(3)
        ->and(TimetableEntry::query()->where('subject_id', $fixtures['subject']->id)->whereNull('student_flow_id')->where('is_in_pool', false)->count())->toBe(0);
});

it('converts excess manually extended blocks to self-study during refresh', function () {
    $fixtures = createScheduleFixtures();
    $fixtures['subject']->update(['auditorium_hours' => 120, 'lecture_hours' => 120]);
    foreach (range(1, 6) as $weekdayNo) {
        TimetableEntry::query()->create([
            'group_id' => $fixtures['group']->id,
            'subject_id' => $fixtures['subject']->id,
            'teacher_id' => $fixtures['teacher']->id,
            'coverage_type' => 'lecture',
            'academic_year' => '2026/2027',
            'semester_type' => 'winter',
            'cycle_week' => 1,
            'weekday_no' => $weekdayNo,
            'start_time' => $weekdayNo === 6 ? '13:00:00' : '08:00:00',
            'end_time' => $weekdayNo === 6 ? '16:45:00' : '12:45:00',
            'duration_hours' => $weekdayNo === 6 ? 4 : 5,
            'locked' => false,
        ]);
    }

    $result = app(ScheduleService::class)->refreshForGroup($fixtures['group'], '2026/2027', 'winter');

    expect($result['pooled'])->toBe(1)
        ->and($fixtures['subject']->fresh()->self_study_hours)->toBe(5)
        ->and(TimetableEntry::query()->where('group_id', $fixtures['group']->id)->where('is_in_pool', false)->count())->toBe(5)
        ->and(TimetableEntry::query()->where('group_id', $fixtures['group']->id)->where('is_in_pool', true)->count())->toBe(1);
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
        ->assertSee('Презареди данните без преместване')
        ->assertDontSee('Забележки и препоръки от проверката')
        ->assertDontSee('Няма зададен преподавател.');

    $this->post(route('schedules.test'), $filters)
        ->assertRedirect(route('schedules.index', $filters));

    $this->get(route('schedules.index', $filters))
        ->assertOk()
        ->assertSee('Забележки и препоръки от проверката')
        ->assertSee('Няма зададен преподавател.');
});

it('shows all three weeks as drop targets and labels lecture and exercise teachers', function () {
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
        ->assertSee('Лекции: Иван Иванов')
        ->assertSee('Упражнения: Мария Петрова');

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
        ->and($analysis['self_study_hours'])->toBe(15);
});

it('adds self-study hours to the scheduled workload', function () {
    $fixtures = createScheduleFixtures();
    $fixtures['subject']->update(['auditorium_hours' => 60, 'lecture_hours' => 60, 'self_study_hours' => 40]);
    $fixtures['teacher']->update(['weekly_workload_hours' => 0]);

    $result = app(ScheduleService::class)->generateForGroup($fixtures['group'], '2026/2027', 'winter');

    $entries = collect($result['entries'])->sortBy('weekday_no')->values();

    expect($entries)->toHaveCount(5)
        ->and(collect($result['entries'])->max('weekday_no'))->toBeLessThanOrEqual(5)
        ->and(collect($result['entries'])->pluck('weekday_no')->unique())->toHaveCount(5)
        ->and($entries->pluck('auditorium_hours')->map(fn ($hours): float => (float) $hours)->all())->toBe([3.0, 3.0, 2.0, 2.0, 2.0])
        ->and($entries->pluck('self_study_hours')->map(fn ($hours): float => (float) $hours)->all())->toBe([1.0, 1.0, 2.0, 2.0, 2.0])
        ->and($entries->sum('auditorium_hours') * 5)->toBe(60.0)
        ->and($entries->sum('self_study_hours') * 5)->toBe(40.0);
});

it('uses default schedule times without exposing configurable generator blocks', function () {
    $fixtures = createScheduleFixtures();
    $fixtures['teacher']->update(['weekly_workload_hours' => 12]);
    $admin = createScheduleAdmin();
    $this->actingAs($admin)
        ->get(route('settings.index'))
        ->assertOk()
        ->assertDontSee('Времеви блокове за генериране');

    $result = app(ScheduleService::class)->generateForGroup($fixtures['group'], '2026/2027', 'winter');

    expect($result['entries'])->toHaveCount(3)
        ->and(collect($result['entries'])->map(fn (TimetableEntry $entry): string => substr($entry->start_time, 0, 5))->unique()->all())->toBe(['08:00'])
        ->and(collect($result['entries'])->map(fn (TimetableEntry $entry): string => substr($entry->end_time, 0, 5))->unique()->all())->toBe(['11:45'])
        ->and(collect($result['entries'])->pluck('duration_hours')->unique()->all())->toBe([4])
        ->and(collect($result['entries'])->sum('duration_hours') * 5)->toBe(60);
});

it('keeps every generated lesson in the pool even when all timetable blocks are occupied', function () {
    $fixtures = createScheduleFixtures();
    foreach ([1, 2, 3] as $cycleWeek) {
        foreach (range(1, 6) as $weekdayNo) {
            foreach ([
                ['08:00:00', '11:45:00', 4],
                ['13:00:00', '16:45:00', 4],
                ['16:00:00', '18:30:00', 3],
            ] as [$startTime, $endTime, $durationHours]) {
                TimetableEntry::query()->create([
                    'group_id' => $fixtures['group']->id,
                    'academic_year' => '2026/2027',
                    'semester_type' => 'winter',
                    'cycle_week' => $cycleWeek,
                    'weekday_no' => $weekdayNo,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'duration_hours' => $durationHours,
                    'locked' => true,
                ]);
            }
        }
    }

    $result = app(ScheduleService::class)->generateForGroup($fixtures['group'], '2026/2027', 'winter');

    expect($result['entries'])->toHaveCount(3)
        ->and($result['unscheduled'])->toBeEmpty()
        ->and(TimetableEntry::query()->where('is_in_pool', true)->count())->toBe(3)
        ->and(TimetableEntry::query()->where('is_in_pool', true)->where('pool_reason', 'auto')->count())->toBe(3);
});

it('does not generate unselected elective subjects and starts generating them after selection', function () {
    $fixtures = createScheduleFixtures();
    $elective = Subject::query()->create([
        'code' => 'EL-101',
        'name' => 'Избираема дисциплина: Графика',
        'auditorium_hours' => 20,
        'lecture_hours' => 20,
    ]);
    $curriculumSubject = CurriculumSubject::query()->create([
        'curriculum_version_id' => $fixtures['curriculumVersion']->id,
        'subject_id' => $elective->id,
        'semester_no' => 1,
        'course_year' => 1,
        'subject_category' => 'elective',
        'is_selected' => false,
    ]);

    $result = app(ScheduleService::class)->generateForGroup($fixtures['group'], '2026/2027', 'winter');
    expect(collect($result['entries'])->where('subject_id', $elective->id))->toBeEmpty();

    $this->actingAs(createScheduleAdmin())
        ->patch(route('catalog.curriculum-subjects.selection.update', $curriculumSubject), ['is_selected' => true])
        ->assertRedirect(route('catalog.curriculum-versions.show', $fixtures['curriculumVersion']));

    $result = app(ScheduleService::class)->generateForGroup($fixtures['group'], '2026/2027', 'winter');
    expect(collect($result['entries'])->where('subject_id', $elective->id))->toHaveCount(1);
});

it('sorts subjects and filters by curriculum inclusion', function () {
    $fixtures = createScheduleFixtures();
    $included = Subject::query()->create([
        'code' => 'Z-100',
        'name' => 'Альфа',
        'auditorium_hours' => 20,
    ]);
    $excluded = Subject::query()->create([
        'code' => 'A-100',
        'name' => 'Бета',
        'auditorium_hours' => 80,
    ]);
    CurriculumSubject::query()->create([
        'curriculum_version_id' => $fixtures['curriculumVersion']->id,
        'subject_id' => $included->id,
        'semester_no' => 1,
        'course_year' => 1,
    ]);
    $this->actingAs(createScheduleAdmin());

    $this->get(route('catalog.index', [
        'tab' => 'subjects',
        'subject_sort' => 'auditorium_hours',
        'subject_in_curriculum' => 'included',
    ]))->assertOk()->assertViewHas('subjects', fn ($subjects): bool => $subjects->pluck('id')->all() === [
        $fixtures['subject']->id,
        $included->id,
    ]);

    $this->get(route('catalog.index', [
        'tab' => 'subjects',
        'subject_sort' => 'code',
        'subject_in_curriculum' => 'not_included',
    ]))->assertOk()->assertViewHas('subjects', fn ($subjects): bool => $subjects->pluck('id')->all() === [$excluded->id]);

    $this->get(route('catalog.index', [
        'tab' => 'subjects',
        'subject_sort' => 'code',
    ]))->assertOk()->assertViewHas('subjects', fn ($subjects): bool => $subjects->first()->id === $excluded->id
        && $subjects->last()->id === $included->id);
    $this->get(route('catalog.index', [
        'tab' => 'subjects',
        'subject_sort' => 'name',
    ]))->assertOk()->assertViewHas('subjects', fn ($subjects): bool => $subjects->first()->id === $included->id);
});

it('lists all teachers in the catalog without pagination', function () {
    $admin = createScheduleAdmin();
    collect(range(1, 13))->each(fn (int $number): Teacher => Teacher::query()->create([
        'first_name' => 'Име'.$number,
        'last_name' => 'Преподавател'.$number,
        'max_daily_hours' => 8,
        'weekly_workload_hours' => 20,
        'active' => true,
    ]));

    $this->actingAs($admin)
        ->get(route('catalog.index', ['tab' => 'teachers']))
        ->assertOk()
        ->assertSee('13 общо, показани без пагинация.')
        ->assertSee('Преподавател13')
        ->assertViewHas('teachers', fn ($listedTeachers): bool => $listedTeachers->count() === 13);
});

it('filters teachers by the curriculum plan that contains their assignments', function () {
    $fixtures = createScheduleFixtures();
    $otherSpecialty = Specialty::query()->create([
        'name' => 'Графичен дизайн',
        'code' => 'GD',
        'degree' => 'Бакалавър',
    ]);
    $otherVersion = CurriculumVersion::query()->create([
        'specialty_id' => $otherSpecialty->id,
        'name' => 'План 2027',
        'version_year' => 2027,
        'is_active' => true,
    ]);
    $otherTeacher = Teacher::query()->create([
        'first_name' => 'Мария',
        'last_name' => 'Петрова',
        'max_daily_hours' => 8,
        'weekly_workload_hours' => 12,
        'active' => true,
    ]);
    TeacherAssignment::query()->create([
        'teacher_id' => $otherTeacher->id,
        'subject_id' => $fixtures['subject']->id,
        'curriculum_version_id' => $otherVersion->id,
        'course_year' => 1,
        'semester' => 'winter',
        'coverage_type' => 'lecture',
    ]);

    $this->actingAs(createScheduleAdmin())
        ->get(route('catalog.index', [
            'tab' => 'teachers',
            'teacher_plan_id' => $fixtures['curriculumVersion']->id,
        ]))
        ->assertOk()
        ->assertViewHas('teachers', fn ($teachers): bool => $teachers->pluck('id')->all() === [$fixtures['teacher']->id])
        ->assertSee('Преподаватели по учебен план');
});

it('rejects a teacher assignment that would exceed ten daily hours', function () {
    $fixtures = createScheduleFixtures();
    $fixtures['teacher']->update(['max_daily_hours' => 16]);
    foreach ([
        ['start' => '08:00:00', 'end' => '11:45:00'],
        ['start' => '13:00:00', 'end' => '16:45:00'],
    ] as $range) {
        TimetableEntry::query()->create([
            'group_id' => $fixtures['group']->id,
            'subject_id' => null,
            'custom_subject_name' => 'Съществуващо занятие',
            'teacher_id' => $fixtures['teacher']->id,
            'academic_year' => '2026/2027',
            'semester_type' => 'winter',
            'cycle_week' => 1,
            'weekday_no' => 1,
            'start_time' => $range['start'],
            'end_time' => $range['end'],
            'duration_hours' => 4,
            'locked' => true,
        ]);
    }

    expect(fn () => app(ScheduleService::class)->addManualEntry([
        'group_id' => $fixtures['group']->id,
        'subject_id' => null,
        'custom_subject_name' => 'Допълнително занятие',
        'teacher_id' => $fixtures['teacher']->id,
        'room_id' => null,
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
        'cycle_week' => 1,
        'weekday_no' => 1,
        'start_time' => '17:00:00',
        'end_time' => '20:45:00',
        'duration_hours' => 4,
        'note' => null,
    ]))->toThrow(ValidationException::class, 'Дневното ограничение за преподавателя ще бъде надвишено.');
});

it('schedules five-day subjects first on separate weekday morning blocks', function () {
    $fixtures = createScheduleFixtures();
    $fiveDaySubject = Subject::query()->create([
        'code' => 'FIVE-100',
        'name' => 'Петдневна дисциплина',
        'auditorium_hours' => 100,
        'lecture_hours' => 100,
    ]);
    $sixDaySubject = Subject::query()->create([
        'code' => 'SIX-120',
        'name' => 'Шестдневна дисциплина',
        'auditorium_hours' => 120,
        'lecture_hours' => 120,
    ]);
    CurriculumSubject::query()->create([
        'curriculum_version_id' => $fixtures['curriculumVersion']->id,
        'subject_id' => $fiveDaySubject->id,
        'semester_no' => 1,
        'course_year' => 1,
    ]);
    CurriculumSubject::query()->create([
        'curriculum_version_id' => $fixtures['curriculumVersion']->id,
        'subject_id' => $sixDaySubject->id,
        'semester_no' => 1,
        'course_year' => 1,
    ]);

    $result = app(ScheduleService::class)->generateForGroup($fixtures['group'], '2026/2027', 'winter');
    $entries = collect($result['entries']);
    $fiveDayEntries = $entries->where('subject_id', $fiveDaySubject->id)->values();
    $sixDayEntries = $entries->where('subject_id', $sixDaySubject->id)->values();
    $otherEntries = $entries->where('subject_id', $fixtures['subject']->id)->values();

    expect($fiveDayEntries)->toHaveCount(5)
        ->and($fiveDayEntries->pluck('weekday_no')->sort()->values()->all())->toBe([1, 2, 3, 4, 5])
        ->and($fiveDayEntries->pluck('start_time')->unique()->all())->toBe(['08:00:00'])
        ->and($sixDayEntries)->toHaveCount(6)
        ->and($sixDayEntries->first()->id)->toBe($entries[5]->id)
        ->and($otherEntries->first()->id)->toBe($entries[11]->id)
        ->and($entries->every(fn (TimetableEntry $entry): bool => $entry->weekday_no <= 6))->toBeTrue();

    expect($entries->every(fn (TimetableEntry $entry): bool => $entry->is_in_pool))->toBeTrue();
});

it('uses Saturday after weekday morning and afternoon slots and never fills Sunday automatically', function () {
    $fixtures = createScheduleFixtures();
    $fixtures['subject']->update([
        'auditorium_hours' => 260,
        'lecture_hours' => 260,
        'self_study_hours' => 0,
    ]);
    TeacherAssignment::query()->delete();

    $result = app(ScheduleService::class)->generateForGroup($fixtures['group'], '2026/2027', 'winter');
    $entries = collect($result['entries']);

    expect($entries)->toHaveCount(13)
        ->and($entries->where('weekday_no', 7)->isEmpty())->toBeTrue()
        ->and($entries->every(fn (TimetableEntry $entry): bool => $entry->is_in_pool))->toBeTrue()
        ->and($result['unscheduled'])->toBeEmpty();
});

it('uses Saturday as overflow when weekday blocks are full despite the default weekend preference', function () {
    $fixtures = createScheduleFixtures();
    $fixtures['subject']->update([
        'auditorium_hours' => 20,
        'lecture_hours' => 20,
        'self_study_hours' => 0,
    ]);

    foreach ([1, 2, 3] as $cycleWeek) {
        foreach (range(1, 5) as $weekdayNo) {
            foreach ([
                ['start' => '08:00:00', 'end' => '11:45:00'],
                ['start' => '13:00:00', 'end' => '16:45:00'],
            ] as $range) {
                TimetableEntry::query()->create([
                    'group_id' => $fixtures['group']->id,
                    'subject_id' => null,
                    'custom_subject_name' => 'Заключено занятие',
                    'teacher_id' => null,
                    'room_id' => null,
                    'academic_year' => '2026/2027',
                    'semester_type' => 'winter',
                    'cycle_week' => $cycleWeek,
                    'weekday_no' => $weekdayNo,
                    'start_time' => $range['start'],
                    'end_time' => $range['end'],
                    'duration_hours' => 4,
                    'locked' => true,
                ]);
            }
        }
    }

    $result = app(ScheduleService::class)->generateForGroup($fixtures['group'], '2026/2027', 'winter');

    expect($result['entries'])->toHaveCount(1)
        ->and($result['entries'][0]->weekday_no)->toBe(6)
        ->and($result['entries'][0]->cycle_week)->toBe(1)
        ->and($result['unscheduled'])->toBeEmpty();
});

it('turns scheduled excess into self-study during the explicit schedule test', function () {
    $fixtures = createScheduleFixtures();
    foreach (range(1, 4) as $weekdayNo) {
        TimetableEntry::query()->create([
            'group_id' => $fixtures['group']->id,
            'subject_id' => $fixtures['subject']->id,
            'teacher_id' => null,
            'room_id' => null,
            'academic_year' => '2026/2027',
            'semester_type' => 'winter',
            'cycle_week' => 1,
            'weekday_no' => $weekdayNo,
            'start_time' => '08:00:00',
            'end_time' => '11:45:00',
            'duration_hours' => 4,
            'locked' => false,
        ]);
    }

    $warnings = app(ScheduleService::class)->testSchedule($fixtures['group'], '2026/2027', 'winter');

    expect($fixtures['subject']->fresh()->self_study_hours)->toBe(20)
        ->and(collect($warnings)->pluck('reason')->join(' '))->toContain('са добавени 20 ч.')
        ->and(collect($warnings)->pluck('reason')->join(' '))->not->toContain('надвишава планираните')
        ->and(collect(app(ScheduleService::class)->testSchedule($fixtures['group'], '2026/2027', 'winter'))
            ->pluck('reason')->join(' '))->not->toContain('са добавени');
});

it('reports when a group or teacher exceeds ten teaching hours in a day', function () {
    $fixtures = createScheduleFixtures();
    $fixtures['teacher']->update(['max_daily_hours' => 16]);
    foreach ([
        ['start' => '08:00:00', 'end' => '11:45:00'],
        ['start' => '13:00:00', 'end' => '16:45:00'],
        ['start' => '17:00:00', 'end' => '20:45:00'],
    ] as $range) {
        TimetableEntry::query()->create([
            'group_id' => $fixtures['group']->id,
            'subject_id' => $fixtures['subject']->id,
            'teacher_id' => $fixtures['teacher']->id,
            'room_id' => null,
            'academic_year' => '2026/2027',
            'semester_type' => 'winter',
            'cycle_week' => 1,
            'weekday_no' => 1,
            'start_time' => $range['start'],
            'end_time' => $range['end'],
            'duration_hours' => 4,
            'locked' => false,
        ]);
    }

    $warnings = collect(app(ScheduleService::class)->testSchedule($fixtures['group'], '2026/2027', 'winter'));
    $warningText = $warnings->pluck('reason')->join(' ');

    expect($warningText)->toContain('Групата има 12 учебни часа')
        ->and($warningText)->toContain('Преподавателят има 12 учебни часа');
});

it('counts a teacher day across all groups when checking one group schedule', function () {
    $fixtures = createScheduleFixtures();
    $fixtures['teacher']->update([
        'max_daily_hours' => 16,
        'weekly_workload_hours' => 0,
    ]);
    $otherGroup = StudentGroup::query()->create([
        'specialty_id' => $fixtures['specialty']->id,
        'curriculum_version_id' => $fixtures['curriculumVersion']->id,
        'course_year' => 1,
        'group_name' => 'Б',
        'student_count' => 18,
    ]);
    foreach ([
        ['group_id' => $fixtures['group']->id, 'start' => '08:00:00', 'end' => '11:45:00'],
        ['group_id' => $fixtures['group']->id, 'start' => '13:00:00', 'end' => '16:45:00'],
        ['group_id' => $otherGroup->id, 'start' => '17:00:00', 'end' => '20:45:00'],
    ] as $lesson) {
        TimetableEntry::query()->create([
            'group_id' => $lesson['group_id'],
            'subject_id' => $fixtures['subject']->id,
            'teacher_id' => $fixtures['teacher']->id,
            'room_id' => null,
            'academic_year' => '2026/2027',
            'semester_type' => 'winter',
            'cycle_week' => 1,
            'weekday_no' => 1,
            'start_time' => $lesson['start'],
            'end_time' => $lesson['end'],
            'duration_hours' => 4,
            'locked' => false,
        ]);
    }

    $warningText = collect(app(ScheduleService::class)
        ->testSchedule($fixtures['group'], '2026/2027', 'winter'))
        ->pluck('reason')
        ->join(' ');

    expect($warningText)->toContain('Преподавателят има 12 учебни часа')
        ->and($warningText)->not->toContain('Групата има 12 учебни часа');
});

it('syncs a teacher selected in a schedule entry to the curriculum and teacher profile', function () {
    $fixtures = createScheduleFixtures();
    $newTeacher = Teacher::query()->create([
        'first_name' => 'Мария',
        'last_name' => 'Петрова',
        'max_daily_hours' => 8,
        'weekly_workload_hours' => 12,
        'active' => true,
    ]);
    $entry = TimetableEntry::query()->create([
        'group_id' => $fixtures['group']->id,
        'subject_id' => $fixtures['subject']->id,
        'teacher_id' => $fixtures['teacher']->id,
        'room_id' => null,
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
        'cycle_week' => 1,
        'weekday_no' => 1,
        'start_time' => '08:00:00',
        'end_time' => '11:45:00',
        'duration_hours' => 4,
        'coverage_type' => 'lecture',
        'locked' => false,
    ]);
    $repeatedEntry = TimetableEntry::query()->create([
        'group_id' => $fixtures['group']->id,
        'subject_id' => $fixtures['subject']->id,
        'teacher_id' => $fixtures['teacher']->id,
        'room_id' => null,
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
        'cycle_week' => 2,
        'weekday_no' => 1,
        'start_time' => '08:00:00',
        'end_time' => '11:45:00',
        'duration_hours' => 4,
        'coverage_type' => 'lecture',
        'locked' => false,
    ]);
    TeacherAssignment::query()->create([
        'teacher_id' => $fixtures['teacher']->id,
        'subject_id' => $fixtures['subject']->id,
        'curriculum_version_id' => $fixtures['curriculumVersion']->id,
        'course_year' => 1,
        'semester' => 'winter',
        'coverage_type' => 'lecture',
        'student_group_id' => $fixtures['group']->id,
        'academic_year' => '2026/2027',
    ]);
    TeacherAssignment::query()->create([
        'teacher_id' => $newTeacher->id,
        'subject_id' => $fixtures['subject']->id,
        'curriculum_version_id' => $fixtures['curriculumVersion']->id,
        'course_year' => 1,
        'semester' => 'winter',
        'coverage_type' => 'lecture',
        'student_group_id' => $fixtures['group']->id,
        'academic_year' => '2026/2027',
    ]);

    app(ScheduleService::class)->moveEntry($entry, [
        'cycle_week' => 1,
        'weekday_no' => 1,
        'start_time' => '08:00:00',
        'end_time' => '11:45:00',
        'duration_hours' => 4,
        'teacher_id' => $newTeacher->id,
        'room_id' => null,
        'coverage_type' => 'lecture',
        'locked' => false,
    ]);

    $this->assertDatabaseHas('teacher_assignments', [
        'teacher_id' => $newTeacher->id,
        'subject_id' => $fixtures['subject']->id,
        'curriculum_version_id' => $fixtures['curriculumVersion']->id,
        'student_group_id' => $fixtures['group']->id,
        'academic_year' => '2026/2027',
        'course_year' => 1,
        'semester' => 'winter',
        'coverage_type' => 'lecture',
    ]);
    $this->assertDatabaseMissing('teacher_assignments', [
        'teacher_id' => $fixtures['teacher']->id,
        'subject_id' => $fixtures['subject']->id,
        'student_group_id' => $fixtures['group']->id,
        'academic_year' => '2026/2027',
        'coverage_type' => 'lecture',
    ]);
    expect($repeatedEntry->refresh()->teacher_id)->toBe($newTeacher->id);

    TimetableEntry::query()->create([
        'group_id' => $fixtures['group']->id,
        'subject_id' => $fixtures['subject']->id,
        'teacher_id' => $fixtures['teacher']->id,
        'room_id' => null,
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
        'cycle_week' => 1,
        'weekday_no' => 2,
        'start_time' => '08:00:00',
        'end_time' => '11:45:00',
        'duration_hours' => 4,
        'coverage_type' => 'exercise',
        'locked' => false,
    ]);

    $admin = createScheduleAdmin();
    $this->actingAs($admin)
        ->get(route('catalog.curriculum-versions.show', $fixtures['curriculumVersion']))
        ->assertOk()
        ->assertSee('Мария Петрова');
    $this->get(route('imports.index'))
        ->assertOk()
        ->assertSee('Преподавателско възлагане (HTML)');
    $this->get(route('catalog.teachers.show', $newTeacher))
        ->assertOk()
        ->assertSee('Рисуване');
    $this->get(route('schedules.index', [
        'group_id' => $fixtures['group']->id,
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
    ]))
        ->assertOk()
        ->assertSee('Лекции: Мария Петрова')
        ->assertSee('Упражнения: Иван Иванов');
});

it('shows the self-study summary within the subject catalog', function () {
    $fixtures = createScheduleFixtures();
    $fixtures['subject']->update(['self_study_hours' => 18]);
    $admin = createScheduleAdmin();

    $this->actingAs($admin)
        ->get(route('catalog.index', ['tab' => 'subjects']))
        ->assertOk()
        ->assertSee('Справка: дисциплини със самоподготовка')
        ->assertSee('Рисуване')
        ->assertSee('18 ч.');
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

it('parks a lesson and restores it into a free block', function () {
    $fixtures = createScheduleFixtures();
    $service = app(ScheduleService::class);
    $entry = $service->addManualEntry([
        'group_id' => $fixtures['group']->id,
        'subject_id' => null,
        'custom_subject_name' => 'Ръчна корекция',
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
    $service->parkEntry($entry);

    $restored = $service->moveEntry($entry, [
        'cycle_week' => 2,
        'weekday_no' => 2,
        'start_time' => '13:00:00',
        'end_time' => '16:45:00',
        'duration_hours' => 4,
        'teacher_id' => null,
        'room_id' => null,
        'locked' => false,
        'is_in_pool' => false,
    ]);

    expect($restored->is_in_pool)->toBeFalse()
        ->and($restored->pool_reason)->toBeNull()
        ->and($restored->cycle_week)->toBe(2)
        ->and($restored->weekday_no)->toBe(2);
});

it('ignores pooled lessons for conflicts but checks them when restoring', function () {
    $fixtures = createScheduleFixtures();
    $service = app(ScheduleService::class);
    $pooledEntry = $service->addManualEntry([
        'group_id' => $fixtures['group']->id,
        'subject_id' => null,
        'custom_subject_name' => 'Извадено занятие',
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
    $service->parkEntry($pooledEntry);
    $service->addManualEntry([
        'group_id' => $fixtures['group']->id,
        'subject_id' => null,
        'custom_subject_name' => 'Новото занятие',
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

    expect(fn () => $service->moveEntry($pooledEntry, [
        'cycle_week' => 1,
        'weekday_no' => 1,
        'start_time' => '08:00:00',
        'end_time' => '11:45:00',
        'duration_hours' => 4,
        'teacher_id' => null,
        'room_id' => null,
        'locked' => false,
        'is_in_pool' => false,
    ]))->toThrow(ValidationException::class);
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

    $entry = TimetableEntry::query()->create([
        'group_id' => $fixtures['group']->id,
        'subject_id' => $fixtures['subject']->id,
        'teacher_id' => $fixtures['teacher']->id,
        'room_id' => null,
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
        'cycle_week' => 1,
        'weekday_no' => 1,
        'start_time' => '08:00:00',
        'end_time' => '11:45:00',
        'duration_hours' => 4,
        'coverage_type' => 'lecture',
        'locked' => false,
    ]);
    app(ScheduleService::class)->recordRevision($fixtures['group'], '2026/2027', 'winter', 'Начално разписание');

    app(ScheduleService::class)->moveEntry($entry, [
        'cycle_week' => 1,
        'weekday_no' => 5,
        'start_time' => '13:00:00',
        'end_time' => '17:45:00',
        'duration_hours' => 4,
        'teacher_id' => $fixtures['teacher']->id,
        'room_id' => $fixtures['room']->id,
        'locked' => false,
        'is_in_pool' => false,
    ]);

    $this->assertDatabaseCount('schedule_revisions', 2);

    $this->get(route('schedules.history', [
        'group' => $fixtures['group']->id,
        'academic_year' => '2026/2027',
        'semester_type' => 'winter',
    ]))->assertOk()
        ->assertSee('Версия 1')
        ->assertSee('Версия 2')
        ->assertSee('Начално разписание')
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
