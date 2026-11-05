<?php

use App\Models\CurriculumVersion;
use App\Models\Specialty;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;

function createStudyImportVersion(): CurriculumVersion
{
    config(['app.key' => 'base64:'.base64_encode(str_repeat('b', 32))]);

    $specialty = Specialty::query()->create([
        'name' => 'Изобразително изкуство',
        'code' => 'II',
    ]);

    return CurriculumVersion::query()->create([
        'specialty_id' => $specialty->id,
        'name' => 'Учебен план 2026',
        'version_year' => 2026,
        'is_active' => true,
    ]);
}

function createStudyImportAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->firstOrCreate([
        'name' => 'admin',
        'guard_name' => 'web',
    ]));

    return $admin;
}

function curriculumHtml(string $subjectCode = 'ART-101', string $subjectName = 'Рисуване'): string
{
    return '<!doctype html><html lang="bg"><meta charset="utf-8"><table><tr>'
        .'<th>Код</th><th>Дисциплина</th><th>Курс</th><th>Семестър</th>'
        .'<th>Общо аудиторни занятия</th><th>Самостоятелни часове</th>'
        .'<th>Седмични часове</th><th>Форма на контрол</th><th>Кредити</th></tr><tr>'
        .'<td>'.$subjectCode.'</td><td>'.$subjectName.'</td><td>1</td><td>1</td>'
        .'<td>60</td><td>30</td><td>4</td><td>Изпит</td><td>5</td></tr></table></html>';
}

function wideCurriculumHtml(): string
{
    $headers = [
        'Код',
        'Дисциплина',
        'Курс',
        'Семестър',
        'АЗ',
        'Л',
        'У',
        'Контролен семестър',
        'Форма на контрол',
        'Кредити',
        'Общо часове',
        'Аудиторни часове',
        'Самостоятелна работа',
        'Лекции общо',
        'Упражнения общо',
        'Практика',
        'Лабораторни занятия',
        'Курсова работа',
        'Изпит',
        'Проект',
        'Седмици',
        'Избираема',
        'Общо точки',
        'Допълнителна информация',
    ];
    $values = [
        'ART-201',
        'Композиция',
        '2',
        '3',
        '48',
        '30',
        '18',
        '4',
        'Изпит',
        '6',
        '120',
        '48',
        '72',
        '30',
        '18',
        '0',
        '0',
        '0',
        '6',
        '0',
        '15',
        'Не',
        '100',
        '',
    ];

    return '<html><table><tr>'.implode('', array_map(fn (string $header): string => '<th>'.$header.'</th>', $headers))
        .'</tr><tr>'.implode('', array_map(fn (string $value): string => '<td>'.$value.'</td>', $values)).'</tr></table></html>';
}

function savedCurriculumHtml(): string
{
    $headers = [
        'Код',
        'Наименование',
        'Изуч. се в сем.',
        'АЗ',
        'Л',
        'У',
        'Контр. в сем.',
        'Форма на контрол',
        'Конс.',
        'кр.',
        'Сам. раб.',
        'кр.',
        'Курс. раб.',
        'кр.',
        'Практик.',
        'кр.',
        'Реф.',
        'кр.',
        'Хор. ИАЗ',
        'Общ хор.',
        'Кр. АЗ',
        'ИАЗ кр.',
        'Общо кр.',
        '',
    ];
    $values = [
        '20232040',
        'Информационен дизайн и цвят',
        '3',
        '48',
        '30',
        '18',
        '4',
        'Изпит',
        '0',
        '0',
        '0',
        '0',
        '1',
        '0',
        '0',
        '0',
        '0',
        '0',
        '30',
        '60',
        '1',
        '1',
        '0',
        '',
    ];
    $summaryValues = $values;
    $summaryValues[0] = '0';
    $summaryValues[1] = 'Всичко за ТРЕТИ СЕМЕСТЪР - всички';
    $summaryValues[3] = '0';

    return '<html><body><table><tr><td>Учебен план 2026</td></tr></table>'
        .'<table id="ContentPlaceHolder1_ContentPlaceHolder1_gv_vvplan" class="footable"><tr>'
        .implode('', array_map(fn (string $header): string => '<th>'.$header.'</th>', $headers))
        .'</tr><tr>'.implode('', array_map(fn (string $value): string => '<td>'.$value.'</td>', $values))
        .'</tr><tr>'.implode('', array_map(fn (string $value): string => '<td>'.$value.'</td>', $summaryValues))
        .'</tr></table></body></html>';
}

function workloadHtml(): string
{
    return '<!doctype html><html lang="bg"><meta charset="utf-8"><table id="ContentPlaceHolder1_gv_disc_obezp">'
        .'<tr><th>Сем.</th><th>Дисциплина / Преподавател</th><th>Статут на дисциплината / Вид заетост</th><th>Катедра</th><th>Възраст</th></tr>'
        .'<tr style="font-weight:bold"><td>1</td><td>Рисуване</td><td>Задължителна</td><td></td><td></td></tr>'
        .'<tr><td>1</td><td>Иван Иванов</td><td>Лекции</td><td>Катедра А</td><td>48</td></tr>'
        .'</table></html>';
}

function submitStudyImportPreview(string $kind, CurriculumVersion $version, string $html, string $filename = 'source.html')
{
    return test()->post(route('imports.preview'), [
        'kind' => $kind,
        'curriculum_version_id' => $version->id,
        'file' => UploadedFile::fake()->createWithContent($filename, $html),
    ]);
}

it('previews and confirms curriculum rows while retaining source columns and missing values', function () {
    $version = createStudyImportVersion();
    $admin = createStudyImportAdmin();

    $response = $this->actingAs($admin)
        ->post(route('imports.preview'), [
            'kind' => 'curriculum',
            'curriculum_version_id' => $version->id,
            'file' => UploadedFile::fake()->createWithContent('plan.html', curriculumHtml()),
        ])
        ->assertOk()
        ->assertSee('Преглед')
        ->assertViewHas('preview', fn (array $preview): bool => count($preview['rows']) === 1
            && $preview['rows'][0]['semester_no'] === 1
            && $preview['rows'][0]['scheduled_hours'] === 4);

    $preview = $response->viewData('preview');
    $this->assertDatabaseCount('subjects', 0);

    $this->post(route('imports.confirm'), [
        'token' => $response->viewData('token'),
        'subjects' => [0 => 'create'],
    ])->assertRedirect(route('catalog.index'));

    $subject = Subject::query()->firstOrFail();
    $this->assertDatabaseHas('curriculum_subjects', [
        'curriculum_version_id' => $version->id,
        'subject_id' => $subject->id,
        'semester_no' => 1,
        'course_year' => 1,
    ]);
    $subject->refresh();
    expect($subject->auditorium_hours)->toBe(60)
        ->and($subject->lecture_hours)->toBe(0)
        ->and($subject->exercise_hours)->toBe(0)
        ->and($subject->self_study_hours)->toBe(30)
        ->and($subject->control_type)->toBe('И');

    $sourceData = $subject->curriculumSubjects()->firstOrFail()->source_data;
    expect($sourceData['columns']['Кредити'])->toBe('5')
        ->and($preview['rows'][0]['warnings'])->toContain(
            'Часовете за лекции (Л) липсват в източника; стойността остава неизвестна.',
        );
});

it('maps identified study-plan fields and preserves the remaining 24-column source row', function () {
    $version = createStudyImportVersion();
    $admin = createStudyImportAdmin();

    $response = $this->actingAs($admin)
        ->post(route('imports.preview'), [
            'kind' => 'curriculum',
            'curriculum_version_id' => $version->id,
            'file' => UploadedFile::fake()->createWithContent('plan.html', wideCurriculumHtml()),
        ])
        ->assertOk();

    $row = $response->viewData('preview')['rows'][0];
    expect(count($row['source']))->toBe(24)
        ->and($row['contact_hours'])->toBe(48)
        ->and($row['auditorium_hours'])->toBe(48)
        ->and($row['lecture_hours'])->toBe(30)
        ->and($row['exercise_hours'])->toBe(18)
        ->and($row['semester_no'])->toBe(3)
        ->and($row['source']['Л'])->toBe('30')
        ->and($row['source']['У'])->toBe('18')
        ->and($row['source']['Контролен семестър'])->toBe('4')
        ->and($row['source']['Кредити'])->toBe('6');

    $this->post(route('imports.confirm'), [
        'token' => $response->viewData('token'),
        'subjects' => [0 => 'create'],
    ])->assertRedirect(route('catalog.index'));

    $subject = Subject::query()->firstOrFail();
    expect($subject->auditorium_hours)->toBe(48)
        ->and($subject->lecture_hours)->toBe(30)
        ->and($subject->exercise_hours)->toBe(18);
});

it('discovers and imports the saved university plan table with abbreviated headers', function () {
    $version = createStudyImportVersion();
    $admin = createStudyImportAdmin();

    $response = $this->actingAs($admin)
        ->post(route('imports.preview'), [
            'kind' => 'curriculum',
            'curriculum_version_id' => $version->id,
            'file' => UploadedFile::fake()->createWithContent('plan_save.htm', savedCurriculumHtml()),
        ])
        ->assertOk();

    $row = $response->viewData('preview')['rows'][0];
    $summaryRow = $response->viewData('preview')['rows'][1];
    expect(count($row['source']))->toBe(24)
        ->and($row['subject_name'])->toBe('Информационен дизайн и цвят')
        ->and($row['semester_no'])->toBe(3)
        ->and($row['course_year'])->toBe(2)
        ->and($row['contact_hours'])->toBe(48)
        ->and($row['control_type'])->toBe('Изпит')
        ->and($summaryRow['valid'])->toBeFalse()
        ->and($summaryRow['warnings'])->toContain('Редът е обобщение за семестър, а не дисциплина; не може да бъде записан.');

    $this->post(route('imports.confirm'), [
        'token' => $response->viewData('token'),
        'subjects' => [0 => 'create'],
    ])->assertRedirect(route('catalog.index'));

    $this->assertDatabaseHas('curriculum_subjects', [
        'curriculum_version_id' => $version->id,
        'semester_no' => 3,
        'course_year' => 2,
    ]);
    expect(Subject::query()->firstOrFail()->auditorium_hours)->toBe(48)
        ->and(Subject::query()->firstOrFail()->control_type)->toBe('И');
    $this->assertDatabaseCount('subjects', 1);
    $this->assertDatabaseCount('curriculum_subjects', 1);
});

it('does not duplicate curriculum records when the same file is imported again', function () {
    $version = createStudyImportVersion();
    $admin = createStudyImportAdmin();
    $this->actingAs($admin);

    $first = submitStudyImportPreview('curriculum', $version, curriculumHtml())->assertOk();
    $this->post(route('imports.confirm'), [
        'token' => $first->viewData('token'),
        'subjects' => [0 => 'create'],
    ])->assertRedirect();

    $subjectId = (string) Subject::query()->value('id');
    Subject::query()->firstOrFail()->update(['control_type' => 'ТО']);
    $second = submitStudyImportPreview('curriculum', $version, curriculumHtml())->assertOk();
    expect($second->viewData('preview')['rows'][0]['status'])->toBe('already_imported');
    $this->post(route('imports.confirm'), [
        'token' => $second->viewData('token'),
        'subjects' => [0 => $subjectId],
    ])->assertRedirect();

    $this->assertDatabaseCount('subjects', 1);
    $this->assertDatabaseCount('curriculum_subjects', 1);
    expect(Subject::query()->firstOrFail()->control_type)->toBe('И');
});

it('shows ambiguous subject matches and imports only the explicitly selected record', function () {
    $version = createStudyImportVersion();
    $firstSubject = Subject::query()->create([
        'code' => 'ART-101',
        'name' => 'Рисуване',
    ]);
    Subject::query()->create([
        'code' => 'ART-201',
        'name' => 'Рисуване',
    ]);
    $admin = createStudyImportAdmin();

    $response = $this->actingAs($admin)
        ->post(route('imports.preview'), [
            'kind' => 'curriculum',
            'curriculum_version_id' => $version->id,
            'file' => UploadedFile::fake()->createWithContent('plan.html', curriculumHtml('', 'Рисуване')),
        ])
        ->assertOk();

    $row = $response->viewData('preview')['rows'][0];
    expect($row['status'])->toBe('ambiguous')
        ->and($row['subject_candidates'])->toHaveCount(2);

    $this->post(route('imports.confirm'), [
        'token' => $response->viewData('token'),
        'subjects' => [0 => (string) $firstSubject->id],
    ])->assertRedirect();

    $this->assertDatabaseHas('curriculum_subjects', [
        'subject_id' => $firstSubject->id,
        'curriculum_version_id' => $version->id,
    ]);
    $this->assertDatabaseCount('curriculum_subjects', 1);
});

it('imports teacher coverage without storing the age column', function () {
    $version = createStudyImportVersion();
    $subject = Subject::query()->create([
        'code' => 'ART-101',
        'name' => 'Рисуване',
    ]);
    $teacher = Teacher::query()->create([
        'first_name' => 'Иван',
        'last_name' => 'Иванов',
        'max_daily_hours' => 8,
        'weekly_workload_hours' => 0,
    ]);
    $admin = createStudyImportAdmin();

    $response = $this->actingAs($admin)
        ->post(route('imports.preview'), [
            'kind' => 'teachers',
            'curriculum_version_id' => $version->id,
            'file' => UploadedFile::fake()->createWithContent('plan.xls', workloadHtml()),
        ])
        ->assertOk()
        ->assertViewHas('preview', fn (array $preview): bool => count($preview['rows']) === 1
            && $preview['rows'][0]['teacher_name'] === 'Иван Иванов');

    $this->post(route('imports.confirm'), [
        'token' => $response->viewData('token'),
        'subjects' => [0 => (string) $subject->id],
        'teachers' => [0 => (string) $teacher->id],
    ])->assertRedirect(route('catalog.index'));

    $this->assertDatabaseHas('teacher_assignments', [
        'teacher_id' => $teacher->id,
        'subject_id' => $subject->id,
        'curriculum_version_id' => $version->id,
        'course_year' => 1,
        'semester' => 'winter',
        'coverage_type' => 'lecture',
    ]);

    $source = $teacher->assignments()->firstOrFail()->source_data['columns'];
    expect($source)->toHaveKey('Катедра')
        ->and($source['Статут на дисциплината / Вид заетост'])->toBe('Лекции')
        ->and($source)->not->toHaveKey('Възраст');

    $again = submitStudyImportPreview('teachers', $version, workloadHtml(), 'plan.xls')->assertOk();
    $this->post(route('imports.confirm'), [
        'token' => $again->viewData('token'),
        'subjects' => [0 => (string) $subject->id],
        'teachers' => [0 => (string) $teacher->id],
    ])->assertRedirect();
    $this->assertDatabaseCount('teacher_assignments', 1);
});

it('defaults missing subject and teacher matches to create and imports them after confirmation', function () {
    $version = createStudyImportVersion();
    $admin = createStudyImportAdmin();

    $response = $this->actingAs($admin)
        ->post(route('imports.preview'), [
            'kind' => 'teachers',
            'curriculum_version_id' => $version->id,
            'file' => UploadedFile::fake()->createWithContent('plan.xls', workloadHtml()),
        ])
        ->assertOk()
        ->assertSee('<option value="create" selected>Създай „Рисуване“ след потвърждение</option>', false)
        ->assertSee('<option value="create" selected>Създай „Иван Иванов“ след потвърждение (натовареността остава 0)</option>', false);

    $this->assertDatabaseCount('teachers', 0);
    $this->assertDatabaseCount('subjects', 0);
    $this->post(route('imports.confirm'), [
        'token' => $response->viewData('token'),
        'subjects' => [0 => 'create'],
        'teachers' => [0 => 'create'],
    ])->assertRedirect();

    $this->assertDatabaseHas('teachers', [
        'first_name' => 'Иван',
        'last_name' => 'Иванов',
        'weekly_workload_hours' => 0,
    ]);
    $this->assertDatabaseHas('subjects', ['name' => 'Рисуване']);
    $this->assertDatabaseCount('teacher_assignments', 1);
});

it('rejects malformed or unsupported workbook content during preview', function () {
    $version = createStudyImportVersion();
    $admin = createStudyImportAdmin();

    $this->actingAs($admin)
        ->post(route('imports.preview'), [
            'kind' => 'curriculum',
            'curriculum_version_id' => $version->id,
            'file' => UploadedFile::fake()->createWithContent('plan.xls', 'This is not an HTML spreadsheet.'),
        ])
        ->assertSessionHasErrors('file');

    $this->post(route('imports.preview'), [
        'kind' => 'curriculum',
        'curriculum_version_id' => $version->id,
        'file' => UploadedFile::fake()->createWithContent('broken.html', '<html><table><tr><td>Not a curriculum</td></tr></table></html>'),
    ])->assertSessionHasErrors('file');
});

it('marks invalid semester data and does not create records for that row', function () {
    $version = createStudyImportVersion();
    $admin = createStudyImportAdmin();
    $html = str_replace('<td>1</td><td>1</td><td>60</td>', '<td>7</td><td>99</td><td>60</td>', curriculumHtml());

    $response = $this->actingAs($admin)
        ->post(route('imports.preview'), [
            'kind' => 'curriculum',
            'curriculum_version_id' => $version->id,
            'file' => UploadedFile::fake()->createWithContent('invalid.html', $html),
        ])
        ->assertOk();

    expect($response->viewData('preview')['rows'][0]['valid'])->toBeFalse()
        ->and($response->viewData('preview')['rows'][0]['semester_no'])->toBeNull();

    $this->post(route('imports.confirm'), [
        'token' => $response->viewData('token'),
        'subjects' => [0 => 'create'],
    ])->assertRedirect();
    $this->assertDatabaseCount('subjects', 0);
    $this->assertDatabaseCount('curriculum_subjects', 0);
});

it('restricts imports to authenticated administrators', function () {
    $version = createStudyImportVersion();

    $this->get(route('imports.index'))->assertRedirect(route('login'));

    $user = User::factory()->create();
    $user->assignRole(Role::query()->firstOrCreate([
        'name' => 'student',
        'guard_name' => 'web',
    ]));
    $this->actingAs($user)->get(route('imports.index'))->assertForbidden();

    $this->assertDatabaseCount('curriculum_subjects', 0);
    expect($version->exists)->toBeTrue();
});
