<?php

use App\Models\CurriculumSubject;
use App\Models\CurriculumVersion;
use App\Models\Specialty;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TimetableEntry;
use App\Models\User;
use App\Services\ScheduleService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

function planAzHtml(
    string $teacherName = 'Доцент д-р Иван Иванов',
    string $specialtyName = 'Изобразително изкуство - ССО',
    ?array $rows = null,
    ?string $departmentName = null,
): string {
    $rows ??= [['Рисуване', $specialtyName, '1', '1', '1', 'Л', '30', '24']];
    $htmlRows = implode('', array_map(
        fn (array $row): string => '<tr>'.implode('', array_map(fn (string $cell): string => '<td>'.$cell.'</td>', $row)).'</tr>',
        $rows,
    ));

    return '<!doctype html><html lang="bg"><meta charset="utf-8">'
        .($departmentName !== null ? '<div>Катедра „'.$departmentName.'“</div>' : '')
        .'<p>'.$teacherName.' · Възложен и редуциран: 120</p>'
        .'<table><tr><th>Дисциплина</th><th>Специалност</th><th>Курс</th><th>Сем.</th><th>Гр.</th><th>Л/У</th><th>Пл.ч.</th><th>Брой студ.</th></tr>'
        .$htmlRows.'</table></html>';
}

function submitStudyImportPreview(
    string $kind,
    ?CurriculumVersion $version,
    string $html,
    string $filename = 'source.html',
    string $employmentCategory = 'full_time',
    string $academicYear = '2026/2027',
) {
    $data = [
        'kind' => $kind,
        'file' => UploadedFile::fake()->createWithContent($filename, $html),
    ];
    if ($version !== null) {
        $data['curriculum_version_id'] = $version->id;
    }
    if ($kind === 'teachers') {
        $data['employment_category'] = $employmentCategory;
        $data['academic_year'] = $academicYear;
    }

    return test()->post(route('imports.preview'), $data);
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
    $sourceData = $subject->curriculumSubjects()->firstOrFail()->source_data;
    expect($sourceData['columns']['Кредити'])->toBe('5')
        ->and($preview['rows'][0]['warnings'])->toContain(
            'Часовете за лекции (Л) липсват в източника; стойността остава неизвестна.',
        );
    $curriculumSubject = $version->subjects()->firstOrFail();
    expect($curriculumSubject->subject_category)->toBe('mandatory')
        ->and($curriculumSubject->is_selected)->toBeTrue()
        ->and($curriculumSubject->auditorium_hours)->toBe(60)
        ->and($curriculumSubject->lecture_hours)->toBe(0)
        ->and($curriculumSubject->exercise_hours)->toBe(0)
        ->and($curriculumSubject->self_study_hours)->toBe(30)
        ->and($curriculumSubject->control_type)->toBe('И');
});

it('marks elective curriculum rows and skips elective rows without auditorium hours by default', function () {
    $version = createStudyImportVersion();
    $admin = createStudyImportAdmin();
    $headers = ['Код', 'Дисциплина', 'Курс', 'Семестър', 'АЗ', 'Кредити'];
    $rows = [
        ['E-001', 'Избираема дисциплина: Илюстрация', '1', '1', '0', '2'],
        ['F-001', 'Факултативна дисциплина: Калиграфия', '1', '1', '', '2'],
        ['M-001', 'Рисуване', '1', '1', '60', '2'],
    ];
    $html = '<html><table><tr>'.implode('', array_map(fn (string $header): string => '<th>'.$header.'</th>', $headers)).'</tr>'
        .implode('', array_map(
            fn (array $row): string => '<tr>'.implode('', array_map(fn (string $cell): string => '<td>'.$cell.'</td>', $row)).'</tr>',
            $rows,
        )).'</table></html>';

    $response = $this->actingAs($admin)
        ->post(route('imports.preview'), [
            'kind' => 'curriculum',
            'curriculum_version_id' => $version->id,
            'file' => UploadedFile::fake()->createWithContent('plan.html', $html),
        ])
        ->assertOk();
    $previewRows = $response->viewData('preview')['rows'];

    expect($previewRows[0]['subject_category'])->toBe('elective')
        ->and($previewRows[0]['default_skip'])->toBeTrue()
        ->and($previewRows[1]['subject_category'])->toBe('optional')
        ->and($previewRows[1]['default_skip'])->toBeTrue()
        ->and($previewRows[2]['subject_category'])->toBe('mandatory')
        ->and($previewRows[2]['default_skip'])->toBeFalse();

    $this->post(route('imports.confirm'), [
        'token' => $response->viewData('token'),
        'subjects' => [0 => 'create', 1 => 'skip', 2 => 'create'],
    ])->assertRedirect(route('catalog.index'));

    $this->assertDatabaseHas('curriculum_subjects', [
        'subject_category' => 'elective',
        'is_selected' => false,
    ]);
    $this->assertDatabaseHas('curriculum_subjects', [
        'subject_category' => 'mandatory',
        'is_selected' => true,
    ]);
    $this->assertDatabaseCount('curriculum_subjects', 2);

    $electiveCurriculumSubject = $version->subjects()
        ->where('subject_category', 'elective')
        ->firstOrFail();
    $this->patch(route('catalog.curriculum-subjects.selection.update', $electiveCurriculumSubject), [
        'is_selected' => true,
    ])->assertRedirect();
    $repeat = submitStudyImportPreview('curriculum', $version, $html)->assertOk();
    $this->post(route('imports.confirm'), [
        'token' => $repeat->viewData('token'),
        'subjects' => [
            0 => (string) Subject::query()->where('code', 'E-001')->value('id'),
            1 => 'skip',
            2 => (string) Subject::query()->where('code', 'M-001')->value('id'),
        ],
    ])->assertRedirect(route('catalog.index'));

    $this->assertDatabaseCount('curriculum_subjects', 2);
    expect($electiveCurriculumSubject->fresh()->is_selected)->toBeTrue();
});

it('reads elective and optional section headings and resets to mandatory after semester summaries', function () {
    $version = createStudyImportVersion();
    $headers = ['Код', 'Наименование', 'Курс', 'Сем.', 'АЗ', 'Л', 'У'];
    $html = '<html><table><tr>'.implode('', array_map(fn (string $header): string => '<th>'.$header.'</th>', $headers)).'</tr>'
        .'<tr><td colspan="7"><b>Избираема дисциплина 3 - избира се 1 дисциплина/блок</b></td></tr>'
        .'<tr><td>E-101</td><td>Типография</td><td>3</td><td>5</td><td>30</td><td>30</td><td>0</td></tr>'
        .'<tr><td colspan="7"><b>Пояснение за вида и семестъра</b></td></tr>'
        .'<tr><td colspan="7"><b>Факултативна дисциплина 2 - избира се 1 дисциплина/блок</b></td></tr>'
        .'<tr><td>F-101</td><td>Калиграфия</td><td>3</td><td>5</td><td>30</td><td>0</td><td>30</td></tr>'
        .'<tr><td>0</td><td>Всичко за ПЕТИ СЕМЕСТЪР - всички</td><td>3</td><td>5</td><td>60</td><td>30</td><td>30</td></tr>'
        .'<tr><td>M-101</td><td>Практика</td><td>3</td><td>5</td><td>60</td><td>0</td><td>60</td></tr>'
        .'</table></html>';

    $response = $this->actingAs(createStudyImportAdmin())
        ->post(route('imports.preview'), [
            'kind' => 'curriculum',
            'curriculum_version_id' => $version->id,
            'file' => UploadedFile::fake()->createWithContent('categorized-plan.html', $html),
        ])
        ->assertOk();
    $rows = $response->viewData('preview')['rows'];

    expect($rows)->toHaveCount(4)
        ->and($rows[0]['subject_name'])->toBe('Типография')
        ->and($rows[0]['subject_category'])->toBe('elective')
        ->and($rows[1]['subject_category'])->toBe('optional')
        ->and($rows[2]['valid'])->toBeFalse()
        ->and($rows[2]['warnings'])->toContain('Редът е обобщение за семестър, а не дисциплина; не може да бъде записан.')
        ->and($rows[3]['subject_category'])->toBe('mandatory');

    $this->post(route('imports.confirm'), [
        'token' => $response->viewData('token'),
        'subjects' => [0 => 'create', 1 => 'create', 2 => 'skip', 3 => 'create'],
    ])->assertRedirect(route('catalog.index'));

    expect($version->subjects()->orderBy('subject_id')->pluck('subject_category')->all())
        ->toContain('elective', 'optional', 'mandatory');
    $this->assertDatabaseHas('curriculum_subjects', [
        'subject_category' => 'elective',
        'is_selected' => false,
    ]);
    $this->assertDatabaseHas('curriculum_subjects', [
        'subject_category' => 'optional',
        'is_selected' => false,
    ]);
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
    $curriculumSubject = $version->subjects()->firstOrFail();
    expect($curriculumSubject->auditorium_hours)->toBe(48)
        ->and($curriculumSubject->lecture_hours)->toBe(30)
        ->and($curriculumSubject->exercise_hours)->toBe(18);

    $this->get(route('catalog.curriculum-versions.show', $version))
        ->assertOk()
        ->assertSee('48')
        ->assertSee('30')
        ->assertSee('18');
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
    expect($version->subjects()->firstOrFail()->auditorium_hours)->toBe(48)
        ->and($version->subjects()->firstOrFail()->control_type)->toBe('И');
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
    $second = submitStudyImportPreview('curriculum', $version, curriculumHtml())->assertOk();
    expect($second->viewData('preview')['rows'][0]['status'])->toBe('already_imported');
    $this->post(route('imports.confirm'), [
        'token' => $second->viewData('token'),
        'subjects' => [0 => $subjectId],
    ])->assertRedirect();

    $this->assertDatabaseCount('subjects', 1);
    $this->assertDatabaseCount('curriculum_subjects', 1);
    expect($version->subjects()->firstOrFail()->control_type)->toBe('И');
});

it('keeps discipline workload values separate between curriculum versions', function () {
    $version = createStudyImportVersion();
    $otherVersion = $version->replicate();
    $otherVersion->name = 'Учебен план 2027';
    $otherVersion->version_year = 2027;
    $otherVersion->save();
    $subject = Subject::query()->create([
        'code' => 'ART-101',
        'name' => 'Рисуване',
    ]);

    $firstPlanSubject = CurriculumSubject::query()->create([
        'curriculum_version_id' => $version->id,
        'subject_id' => $subject->id,
        'semester_no' => 1,
        'course_year' => 1,
        'auditorium_hours' => 60,
        'lecture_hours' => 40,
        'exercise_hours' => 20,
        'self_study_hours' => 10,
        'control_type' => 'И',
    ]);
    $secondPlanSubject = CurriculumSubject::query()->create([
        'curriculum_version_id' => $otherVersion->id,
        'subject_id' => $subject->id,
        'semester_no' => 1,
        'course_year' => 1,
        'auditorium_hours' => 30,
        'lecture_hours' => 20,
        'exercise_hours' => 10,
        'self_study_hours' => 5,
        'control_type' => 'ТО',
    ]);

    expect($firstPlanSubject->hourValues()['auditorium_hours'])->toBe(60)
        ->and($firstPlanSubject->hourValues()['self_study_hours'])->toBe(10)
        ->and($secondPlanSubject->hourValues()['auditorium_hours'])->toBe(30)
        ->and($secondPlanSubject->hourValues()['self_study_hours'])->toBe(5)
        ->and($subject->fresh()->code)->toBe('ART-101');
});

it('migrates legacy subject workload into plan rows before removing catalog columns', function () {
    $version = createStudyImportVersion();
    $subject = Subject::query()->create([
        'code' => 'ART-101',
        'name' => 'Рисуване',
    ]);
    $curriculumSubject = CurriculumSubject::query()->create([
        'curriculum_version_id' => $version->id,
        'subject_id' => $subject->id,
        'semester_no' => 1,
        'course_year' => 1,
    ]);

    Schema::table('subjects', function (Blueprint $table): void {
        $table->unsignedSmallInteger('auditorium_hours')->nullable();
        $table->unsignedSmallInteger('lecture_hours')->nullable();
        $table->unsignedSmallInteger('exercise_hours')->nullable();
        $table->unsignedSmallInteger('self_study_hours')->nullable();
        $table->string('control_type', 30)->nullable();
    });
    DB::table('subjects')->where('id', $subject->id)->update([
        'auditorium_hours' => 60,
        'lecture_hours' => 30,
        'exercise_hours' => 30,
        'self_study_hours' => 20,
        'control_type' => 'И',
    ]);

    $migration = require database_path('migrations/2026_11_11_150000_remove_shared_subject_workload_columns.php');
    $migration->up();

    expect($curriculumSubject->fresh()->hourValues())->toBe([
        'auditorium_hours' => 60,
        'lecture_hours' => 30,
        'exercise_hours' => 30,
        'self_study_hours' => 20,
        'control_type' => 'И',
    ])->and(Schema::hasColumn('subjects', 'auditorium_hours'))->toBeFalse();
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

it('previews and imports PlanAZ rows without requiring one curriculum version', function () {
    $admin = createStudyImportAdmin();

    $response = $this->actingAs($admin)
        ->post(route('imports.preview'), [
            'kind' => 'teachers',
            'employment_category' => 'full_time',
            'academic_year' => '2026/2027',
            'file' => UploadedFile::fake()->createWithContent('PlanAZ.html', planAzHtml()),
        ])
        ->assertOk()
        ->assertViewHas('preview');
    $preview = $response->viewData('preview');
    expect(count($preview['rows']))->toBe(1)
        ->and($preview['version_id'])->toBeNull()
        ->and($preview['rows'][0]['teacher_name'])->toBe('Доцент д-р Иван Иванов')
        ->and($preview['rows'][0]['specialty_name'])->toBe('Изобразително изкуство')
        ->and($preview['rows'][0]['coverage_type'])->toBe('lecture')
        ->and($preview['rows'][0]['planned_hours'])->toBe(30);

    $this->assertDatabaseCount('specialties', 0);
    $this->assertDatabaseCount('student_groups', 0);
    $this->post(route('imports.confirm'), [
        'token' => $response->viewData('token'),
        'subjects' => [0 => 'create'],
        'teachers' => [0 => 'create'],
    ])->assertRedirect(route('catalog.index'));

    $this->assertDatabaseHas('specialties', ['name' => 'Изобразително изкуство']);
    $this->assertDatabaseHas('student_groups', [
        'course_year' => 1,
        'group_name' => '1',
        'student_count' => 24,
    ]);
    $importedGroup = StudentGroup::query()->firstOrFail();
    expect($importedGroup->display_name)->toBe('1 курс');

    StudentGroup::query()->create([
        'specialty_id' => $importedGroup->specialty_id,
        'curriculum_version_id' => $importedGroup->curriculum_version_id,
        'course_year' => 1,
        'group_name' => '2',
        'student_count' => 20,
    ]);

    expect($importedGroup->fresh()->display_name)->toBe('1 курс · група 1')
        ->and(StudentGroup::query()->where('group_name', '2')->firstOrFail()->display_name)->toBe('1 курс · група 2');

    $teacher = Teacher::query()->firstOrFail();
    $subject = Subject::query()->firstOrFail();
    $groupId = StudentGroup::query()->value('id');
    $this->assertDatabaseHas('teacher_assignments', [
        'teacher_id' => $teacher->id,
        'subject_id' => $subject->id,
        'curriculum_version_id' => null,
        'student_group_id' => $groupId,
        'academic_year' => '2026/2027',
        'employment_category' => 'full_time',
        'planned_hours' => 30,
        'course_year' => 1,
        'semester' => 'winter',
        'coverage_type' => 'lecture',
    ]);

    $source = $teacher->assignments()->firstOrFail()->source_data;
    expect($source['columns'])->toHaveKey('Брой студ.')
        ->and($source['teacher_total_assigned_hours'])->toBe(120)
        ->and($teacher->first_name)->toBe('Иван')
        ->and($teacher->last_name)->toBe('Иванов');
});

it('associates each PlanAZ table with its preceding teacher name and strips workload metadata', function () {
    $admin = createStudyImportAdmin();
    $existingTeacher = Teacher::query()->create([
        'first_name' => 'Мария Стефанова',
        'last_name' => 'Иванова',
        'title' => 'доц.',
        'max_daily_hours' => 8,
        'weekly_workload_hours' => 12,
        'active' => true,
    ]);
    $headers = ['Дисциплина', 'Вид', 'Специалност', 'ФО', 'Курс', 'Сем.', 'Гр.', 'Л/У', 'Пл.ч.', 'Брой студ.'];
    $table = fn (array $row): string => '<table><tr>'.implode('', array_map(fn (string $header): string => '<th>'.$header.'</th>', $headers))
        .'</tr><tr>'.implode('', array_map(fn (string $cell): string => '<td>'.$cell.'</td>', $row)).'</tr></table>';
    $html = '<!doctype html><html lang="bg"><meta charset="utf-8"><body>'
        .'<p><span class="h3">ас.</span> <span class="h3">Борислав</span> <span class="h3">Александров</span> <span class="h3">Петров</span> Задължителен норматив: 0 Възложен и редуциран: 24</p>'
        .$table(['Рисуване', 'Задължителна', 'Изобразително изкуство - ССО', 'РФ', '1', '1', '1', 'Л', '24', '24'])
        .'<p><span class="h3">доц.</span> <span class="h3">Мария</span> <span class="h3">Стефанова</span> <span class="h3">Иванова</span> Задължителен норматив: 80 Възложен и редуциран: 18</p>'
        .$table(['Композиция', 'Задължителна', 'Изобразително изкуство - ССО', 'РФ', '1', '1', '2', 'У', '18', '20'])
        .'</body></html>';

    $response = $this->actingAs($admin)
        ->post(route('imports.preview'), [
            'kind' => 'teachers',
            'employment_category' => 'full_time',
            'academic_year' => '2026/2027',
            'file' => UploadedFile::fake()->createWithContent('PlanAZ.html', $html),
        ])
        ->assertOk();
    $preview = $response->viewData('preview');

    expect(count($preview['rows']))->toBe(2)
        ->and($preview['rows'][0]['teacher_name'])->toBe('ас. Борислав Александров Петров')
        ->and($preview['rows'][0]['teacher_parts']['first_name'])->toBe('Борислав Александров')
        ->and($preview['rows'][0]['teacher_parts']['last_name'])->toBe('Петров')
        ->and($preview['rows'][0]['teacher_total_assigned_hours'])->toBe(24)
        ->and($preview['rows'][1]['teacher_name'])->toBe('доц. Мария Стефанова Иванова')
        ->and($preview['rows'][1]['teacher_candidates'][0]['id'])->toBe($existingTeacher->id)
        ->and($preview['rows'][1]['teacher_total_assigned_hours'])->toBe(18);

    $this->post(route('imports.confirm'), [
        'token' => $response->viewData('token'),
        'subjects' => [0 => 'create', 1 => 'create'],
        'teachers' => [0 => 'create', 1 => (string) $existingTeacher->id],
    ])->assertRedirect(route('catalog.index'));

    $createdTeacher = Teacher::query()->where('last_name', 'Петров')->firstOrFail();
    $this->assertDatabaseHas('teacher_assignments', [
        'teacher_id' => $createdTeacher->id,
        'subject_id' => Subject::query()->where('name', 'Рисуване')->value('id'),
        'coverage_type' => 'lecture',
    ]);
    $this->assertDatabaseHas('teacher_assignments', [
        'teacher_id' => $existingTeacher->id,
        'subject_id' => Subject::query()->where('name', 'Композиция')->value('id'),
        'coverage_type' => 'exercise',
    ]);
});

it('assigns imported PlanAZ teachers to the matching group and lesson type', function () {
    $admin = createStudyImportAdmin();
    $specialty = Specialty::query()->create(['name' => 'Изобразително изкуство']);
    $version = CurriculumVersion::query()->create([
        'specialty_id' => $specialty->id,
        'name' => 'Учебен план 2026',
        'version_year' => 2026,
        'is_active' => true,
    ]);
    $subject = Subject::query()->create(['code' => 'ART-101', 'name' => 'Рисуване']);
    CurriculumSubject::query()->create([
        'curriculum_version_id' => $version->id,
        'subject_id' => $subject->id,
        'semester_no' => 1,
        'course_year' => 1,
        'subject_category' => 'mandatory',
        'is_selected' => true,
        'auditorium_hours' => 60,
        'lecture_hours' => 30,
        'exercise_hours' => 30,
    ]);
    $lectureTeacher = Teacher::query()->create([
        'first_name' => 'Иван',
        'last_name' => 'Иванов',
        'title' => 'ас.',
        'max_daily_hours' => 8,
        'weekly_workload_hours' => 12,
        'active' => true,
    ]);
    $groupTwoLectureTeacher = Teacher::query()->create([
        'first_name' => 'Мария',
        'last_name' => 'Петрова',
        'title' => 'доц.',
        'max_daily_hours' => 8,
        'weekly_workload_hours' => 12,
        'active' => true,
    ]);
    $exerciseTeacher = Teacher::query()->create([
        'first_name' => 'Елена',
        'last_name' => 'Георгиева',
        'title' => 'гл. ас.',
        'max_daily_hours' => 8,
        'weekly_workload_hours' => 12,
        'active' => true,
    ]);
    $headers = ['Дисциплина', 'Вид', 'Специалност', 'ФО', 'Курс', 'Сем.', 'Гр.', 'Л/У', 'Пл.ч.', 'Брой студ.'];
    $table = fn (array $row): string => '<table><tr>'.implode('', array_map(fn (string $header): string => '<th>'.$header.'</th>', $headers))
        .'</tr><tr>'.implode('', array_map(fn (string $cell): string => '<td>'.$cell.'</td>', $row)).'</tr></table>';
    $html = '<!doctype html><html lang="bg"><meta charset="utf-8"><body>'
        .'<p><span class="h3">ас.</span> <span class="h3">Иван</span> <span class="h3">Иванов</span> Възложен и редуциран: 30</p>'
        .$table(['Рисуване', 'Задължителна', 'Изобразително изкуство - ССО', 'РФ', '1', '1', '1', 'Л', '30', '20'])
        .'<p><span class="h3">доц.</span> <span class="h3">Мария</span> <span class="h3">Петрова</span> Възложен и редуциран: 30</p>'
        .$table(['Рисуване', 'Задължителна', 'Изобразително изкуство - ССО', 'РФ', '1', '1', '2', 'Л', '30', '18'])
        .'<p><span class="h3">гл. ас.</span> <span class="h3">Елена</span> <span class="h3">Георгиева</span> Възложен и редуциран: 30</p>'
        .$table(['Рисуване', 'Задължителна', 'Изобразително изкуство - ССО', 'РФ', '1', '1', '1', 'У', '30', '20'])
        .'</body></html>';

    $response = $this->actingAs($admin)
        ->post(route('imports.preview'), [
            'kind' => 'teachers',
            'employment_category' => 'full_time',
            'academic_year' => '2026/2027',
            'file' => UploadedFile::fake()->createWithContent('PlanAZ.html', $html),
        ])
        ->assertOk()
        ->assertSee('value="'.$lectureTeacher->id.'" selected', false)
        ->assertSee('value="'.$groupTwoLectureTeacher->id.'" selected', false)
        ->assertSee('value="'.$exerciseTeacher->id.'" selected', false);
    $preview = $response->viewData('preview');

    expect(count($preview['rows']))->toBe(3)
        ->and($preview['rows'][0]['subject_candidates'][0]['id'])->toBe($subject->id)
        ->and($preview['rows'][0]['teacher_candidates'][0]['id'])->toBe($lectureTeacher->id)
        ->and($preview['rows'][1]['teacher_candidates'][0]['id'])->toBe($groupTwoLectureTeacher->id)
        ->and($preview['rows'][2]['teacher_candidates'][0]['id'])->toBe($exerciseTeacher->id)
        ->and($preview['rows'][0]['coverage_type'])->toBe('lecture')
        ->and($preview['rows'][1]['coverage_type'])->toBe('lecture')
        ->and($preview['rows'][2]['coverage_type'])->toBe('exercise');

    $this->post(route('imports.confirm'), [
        'token' => $response->viewData('token'),
        'subjects' => [0 => (string) $subject->id, 1 => (string) $subject->id, 2 => (string) $subject->id],
        'teachers' => [
            0 => (string) $lectureTeacher->id,
            1 => (string) $groupTwoLectureTeacher->id,
            2 => (string) $exerciseTeacher->id,
        ],
    ])->assertRedirect(route('catalog.index'));

    $groupOne = StudentGroup::query()->where('group_name', '1')->firstOrFail();
    $groupTwo = StudentGroup::query()->where('group_name', '2')->firstOrFail();
    $this->assertDatabaseCount('student_groups', 2);
    $scheduleService = app(ScheduleService::class);
    $scheduleService->generateForGroup($groupOne, '2026/2027', 'winter');
    $scheduleService->generateForGroup($groupTwo, '2026/2027', 'winter');

    $groupOneLecture = TimetableEntry::query()
        ->where('group_id', $groupOne->id)
        ->where('coverage_type', 'lecture')
        ->firstOrFail();
    $groupOneExercise = TimetableEntry::query()
        ->where('group_id', $groupOne->id)
        ->where('coverage_type', 'exercise')
        ->firstOrFail();
    $groupTwoLecture = TimetableEntry::query()
        ->where('group_id', $groupTwo->id)
        ->where('coverage_type', 'lecture')
        ->firstOrFail();
    expect($groupOneLecture->teacher_id)->toBe($lectureTeacher->id)
        ->and($groupOneExercise->teacher_id)->toBe($exerciseTeacher->id)
        ->and($groupTwoLecture->teacher_id)->toBe($groupTwoLectureTeacher->id);

    $manualTeacher = Teacher::query()->create([
        'first_name' => 'Николай',
        'last_name' => 'Димитров',
        'max_daily_hours' => 8,
        'weekly_workload_hours' => 12,
        'active' => true,
    ]);
    $scheduleService->moveEntry($groupOneLecture, [
        'cycle_week' => $groupOneLecture->cycle_week,
        'weekday_no' => $groupOneLecture->weekday_no,
        'start_time' => $groupOneLecture->start_time,
        'end_time' => $groupOneLecture->end_time,
        'duration_hours' => $groupOneLecture->duration_hours,
        'teacher_id' => $manualTeacher->id,
        'room_id' => $groupOneLecture->room_id,
        'locked' => false,
        'is_in_pool' => true,
    ]);

    $this->assertDatabaseHas('teacher_assignments', [
        'teacher_id' => $manualTeacher->id,
        'student_group_id' => $groupOne->id,
        'coverage_type' => 'lecture',
    ]);
    $this->assertDatabaseHas('teacher_assignments', [
        'teacher_id' => $groupTwoLectureTeacher->id,
        'student_group_id' => $groupTwo->id,
        'coverage_type' => 'lecture',
    ]);
    $this->assertDatabaseHas('teacher_assignments', [
        'teacher_id' => $exerciseTeacher->id,
        'student_group_id' => $groupOne->id,
        'coverage_type' => 'exercise',
    ]);
    $this->get(route('catalog.curriculum-versions.show', $version))
        ->assertOk()
        ->assertSee('Група 1')
        ->assertSee('Група 2');
});

it('updates matching PlanAZ assignments on reimport without duplicating them', function () {
    $this->actingAs(createStudyImportAdmin());
    $specialty = Specialty::query()->create(['name' => 'Изобразително изкуство - ССО']);
    $version = CurriculumVersion::query()->create([
        'specialty_id' => $specialty->id,
        'name' => 'План 2026',
        'version_year' => 2026,
        'is_active' => true,
    ]);

    $first = submitStudyImportPreview('teachers', null, planAzHtml())->assertOk();
    $this->post(route('imports.confirm'), [
        'token' => $first->viewData('token'),
        'subjects' => [0 => 'create'],
        'teachers' => [0 => 'create'],
    ])->assertRedirect();

    $subject = Subject::query()->firstOrFail();
    $teacher = Teacher::query()->firstOrFail();
    $same = submitStudyImportPreview('teachers', null, planAzHtml())->assertOk();
    $this->post(route('imports.confirm'), [
        'token' => $same->viewData('token'),
        'subjects' => [0 => (string) $subject->id],
        'teachers' => [0 => (string) $teacher->id],
    ])->assertRedirect();
    $this->assertDatabaseCount('teacher_assignments', 1);

    $changedHtml = planAzHtml(rows: [['Рисуване', 'Изобразително изкуство - ССО', '1', '1', '1', 'Л', '32', '24']]);
    $second = submitStudyImportPreview('teachers', null, $changedHtml)->assertOk();
    $this->post(route('imports.confirm'), [
        'token' => $second->viewData('token'),
        'subjects' => [0 => (string) $subject->id],
        'teachers' => [0 => (string) $teacher->id],
    ])->assertRedirect();

    $this->assertDatabaseCount('teacher_assignments', 1);
    $this->assertDatabaseHas('teacher_assignments', [
        'teacher_id' => $teacher->id,
        'subject_id' => $subject->id,
        'curriculum_version_id' => $version->id,
        'planned_hours' => 32,
    ]);
    $this->assertDatabaseCount('specialties', 1);
    $this->assertDatabaseCount('student_groups', 1);
});

it('imports a department for a teacher and does not erase it when the source omits the department', function () {
    $this->actingAs(createStudyImportAdmin());
    $teacher = Teacher::query()->create([
        'first_name' => 'Иван',
        'last_name' => 'Иванов',
        'department' => 'Стара катедра',
        'max_daily_hours' => 8,
        'weekly_workload_hours' => 0,
        'active' => true,
    ]);

    $withDepartment = submitStudyImportPreview(
        'teachers',
        null,
        planAzHtml(departmentName: 'Нова катедра'),
    )->assertOk()->assertViewHas('preview', fn (array $preview): bool => $preview['rows'][0]['department_name'] === 'Нова катедра');
    $this->post(route('imports.confirm'), [
        'token' => $withDepartment->viewData('token'),
        'subjects' => [0 => 'create'],
        'teachers' => [0 => (string) $teacher->id],
    ])->assertRedirect();
    expect($teacher->fresh()->department)->toBe('Нова катедра');

    $withoutDepartment = submitStudyImportPreview('teachers', null, planAzHtml())->assertOk();
    $this->post(route('imports.confirm'), [
        'token' => $withoutDepartment->viewData('token'),
        'subjects' => [0 => 'create'],
        'teachers' => [0 => (string) $teacher->id],
    ])->assertRedirect();
    expect($teacher->fresh()->department)->toBe('Нова катедра');
});

it('imports separate staff and honorary files without duplicating a teacher', function () {
    $this->actingAs(createStudyImportAdmin());

    $staff = submitStudyImportPreview('teachers', null, planAzHtml())->assertOk();
    $this->post(route('imports.confirm'), [
        'token' => $staff->viewData('token'),
        'subjects' => [0 => 'create'],
        'teachers' => [0 => 'create'],
    ])->assertRedirect();

    $honorary = submitStudyImportPreview(
        'teachers',
        null,
        planAzHtml(rows: [['Композиция', 'Изобразително изкуство - ССО', '1', '1', '2', 'У', '20', '18']]),
        'PlanAZ-honorary.html',
        'honorary',
    )->assertOk();
    $this->post(route('imports.confirm'), [
        'token' => $honorary->viewData('token'),
        'subjects' => [0 => 'create'],
        'teachers' => [0 => (string) Teacher::query()->value('id')],
    ])->assertRedirect();

    $this->assertDatabaseCount('teachers', 1);
    $this->assertDatabaseCount('teacher_assignments', 2);
    $this->assertDatabaseHas('teacher_assignments', ['employment_category' => 'full_time']);
    $this->assertDatabaseHas('teacher_assignments', ['employment_category' => 'honorary']);
    $this->assertDatabaseCount('student_groups', 2);

    $teacher = Teacher::query()->firstOrFail();
    expect($teacher->title)->toBe('хон. пр. Доцент д-р');

    $repeat = submitStudyImportPreview(
        'teachers',
        null,
        planAzHtml(rows: [['Композиция', 'Изобразително изкуство - ССО', '1', '1', '2', 'У', '20', '18']]),
        'PlanAZ-honorary.html',
        'honorary',
    )->assertOk();
    $this->post(route('imports.confirm'), [
        'token' => $repeat->viewData('token'),
        'subjects' => [0 => (string) Subject::query()->where('name', 'Композиция')->value('id')],
        'teachers' => [0 => (string) $teacher->id],
    ])->assertRedirect();

    $this->assertDatabaseCount('teachers', 1);
    $this->assertDatabaseCount('teacher_assignments', 2);
    expect($teacher->fresh()->title)->toBe('хон. пр. Доцент д-р');
});

it('rejects legacy spreadsheet imports and old teacher workload tables', function () {
    $this->actingAs(createStudyImportAdmin());

    $this->post(route('imports.preview'), [
        'kind' => 'teachers',
        'employment_category' => 'full_time',
        'academic_year' => '2026/2027',
        'file' => UploadedFile::fake()->createWithContent('PlanAZ.xls', planAzHtml()),
    ])->assertSessionHasErrors('file');

    $legacy = '<html><table><tr><th>Сем.</th><th>Дисциплина / Преподавател</th><th>Вид заетост</th></tr>'
        .'<tr><td>1</td><td>Иван Иванов</td><td>Лекции</td></tr></table></html>';
    submitStudyImportPreview('teachers', null, $legacy)->assertSessionHasErrors('file');

    $this->assertDatabaseCount('teachers', 0);
    $this->assertDatabaseCount('teacher_assignments', 0);
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
