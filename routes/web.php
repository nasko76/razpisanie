<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CatalogDetailsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicScheduleController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\SoftwareSettingsController;
use App\Http\Controllers\StudyDataImportController;
use App\Http\Controllers\TeacherDayPreferenceController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicScheduleController::class, 'index'])->name('home');

Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::get('/dashboard', [AdminController::class, 'index'])->name('dashboard');
    Route::get('/admin', [AdminController::class, 'index'])->name('admin.dashboard');

    Route::get('/admin/catalog', [CatalogController::class, 'index'])->name('catalog.index');
    Route::get('/admin/settings', [SoftwareSettingsController::class, 'index'])->name('settings.index');
    Route::put('/admin/settings', [SoftwareSettingsController::class, 'update'])->name('settings.update');
    Route::get('/admin/catalog/teachers/create', [CatalogDetailsController::class, 'createTeacher'])->name('catalog.teachers.create');
    Route::get('/admin/catalog/curriculum-versions/{curriculumVersion}', [CatalogDetailsController::class, 'curriculumVersion'])->name('catalog.curriculum-versions.show');
    Route::get('/admin/catalog/teachers/{teacher}', [CatalogDetailsController::class, 'teacher'])->name('catalog.teachers.show');
    Route::get('/admin/imports', [StudyDataImportController::class, 'index'])->name('imports.index');
    Route::post('/admin/imports/preview', [StudyDataImportController::class, 'preview'])->name('imports.preview');
    Route::post('/admin/imports/confirm', [StudyDataImportController::class, 'confirm'])->name('imports.confirm');
    Route::post('/admin/catalog/specialties', [CatalogController::class, 'storeSpecialty'])->name('catalog.specialties.store');
    Route::patch('/admin/catalog/specialties/{specialty}', [CatalogController::class, 'updateSpecialty'])->name('catalog.specialties.update');
    Route::delete('/admin/catalog/specialties/{specialty}', [CatalogController::class, 'destroySpecialty'])->name('catalog.specialties.destroy');
    Route::post('/admin/catalog/curriculum-versions', [CatalogController::class, 'storeCurriculumVersion'])->name('catalog.curriculum-versions.store');
    Route::patch('/admin/catalog/curriculum-versions/{curriculumVersion}', [CatalogController::class, 'updateCurriculumVersion'])->name('catalog.curriculum-versions.update');
    Route::patch('/admin/catalog/curriculum-versions/{curriculumVersion}/details', [CatalogController::class, 'updateCurriculumVersionDetails'])->name('catalog.curriculum-versions.details.update');
    Route::delete('/admin/catalog/curriculum-versions/{curriculumVersion}', [CatalogController::class, 'destroyCurriculumVersion'])->name('catalog.curriculum-versions.destroy');
    Route::post('/admin/catalog/curriculum-versions/{curriculumVersion}/duplicate', [CatalogController::class, 'duplicateCurriculumVersion'])->name('catalog.curriculum-versions.duplicate');
    Route::post('/admin/catalog/curriculum-versions/{curriculumVersion}/subjects', [CatalogController::class, 'storeCurriculumSubject'])->name('catalog.curriculum-subjects.store');
    Route::patch('/admin/catalog/curriculum-subjects/{curriculumSubject}', [CatalogController::class, 'updateCurriculumSubject'])->name('catalog.curriculum-subjects.update');
    Route::delete('/admin/catalog/curriculum-subjects/{curriculumSubject}', [CatalogController::class, 'destroyCurriculumSubject'])->name('catalog.curriculum-subjects.destroy');
    Route::post('/admin/catalog/groups', [CatalogController::class, 'storeGroup'])->name('catalog.groups.store');
    Route::patch('/admin/catalog/groups/{group}', [CatalogController::class, 'updateGroup'])->name('catalog.groups.update');
    Route::delete('/admin/catalog/groups/{group}', [CatalogController::class, 'destroyGroup'])->name('catalog.groups.destroy');
    Route::post('/admin/catalog/subjects', [CatalogController::class, 'storeSubject'])->name('catalog.subjects.store');
    Route::patch('/admin/catalog/subjects/{subject}', [CatalogController::class, 'updateSubject'])->name('catalog.subjects.update');
    Route::delete('/admin/catalog/subjects/{subject}', [CatalogController::class, 'destroySubject'])->name('catalog.subjects.destroy');
    Route::post('/admin/catalog/teachers', [CatalogController::class, 'storeTeacher'])->name('catalog.teachers.store');
    Route::patch('/admin/catalog/teachers/{teacher}', [CatalogController::class, 'updateTeacher'])->name('catalog.teachers.update');
    Route::delete('/admin/catalog/teachers/{teacher}', [CatalogController::class, 'destroyTeacher'])->name('catalog.teachers.destroy');
    Route::post('/admin/catalog/teacher-assignments', [CatalogController::class, 'storeTeacherAssignment'])->name('catalog.teacher-assignments.store');
    Route::delete('/admin/catalog/teacher-assignments/{assignment}', [CatalogDetailsController::class, 'destroyTeacherAssignment'])->name('catalog.teacher-assignments.destroy');
    Route::post('/admin/catalog/student-flows', [CatalogController::class, 'storeStudentFlow'])->name('catalog.student-flows.store');
    Route::patch('/admin/catalog/student-flows/{studentFlow}', [CatalogController::class, 'updateStudentFlow'])->name('catalog.student-flows.update');
    Route::delete('/admin/catalog/student-flows/{studentFlow}', [CatalogDetailsController::class, 'destroyStudentFlow'])->name('catalog.student-flows.destroy');
    Route::put('/admin/catalog/teachers/{teacher}/preferred-days', [TeacherDayPreferenceController::class, 'update'])->name('catalog.teachers.preferred-days');
    Route::post('/admin/catalog/teachers/{teacher}/absences', [CatalogController::class, 'storeTeacherAbsence'])->name('catalog.teachers.absences.store');
    Route::delete('/admin/catalog/teachers/{teacher}/absences/{absence}', [CatalogController::class, 'destroyTeacherAbsence'])->name('catalog.teachers.absences.destroy');
    Route::post('/admin/catalog/rooms', [CatalogController::class, 'storeRoom'])->name('catalog.rooms.store');
    Route::patch('/admin/catalog/rooms/{room}', [CatalogController::class, 'updateRoom'])->name('catalog.rooms.update');

    Route::get('/schedules', [ScheduleController::class, 'index'])->name('schedules.index');
    Route::post('/schedules/generate', [ScheduleController::class, 'generate'])->name('schedules.generate');
    Route::post('/schedules/test', [ScheduleController::class, 'test'])->name('schedules.test');
    Route::post('/schedules/entries', [ScheduleController::class, 'storeManualEntry'])->name('schedules.entries.store');
    Route::put('/schedules/note', [ScheduleController::class, 'updateNote'])->name('schedules.note.update');
    Route::get('/schedules/{group}/history', [ScheduleController::class, 'history'])->name('schedules.history');
    Route::patch('/schedules/{entry}', [ScheduleController::class, 'update'])->name('schedules.update');
    Route::delete('/schedules/{entry}', [ScheduleController::class, 'destroy'])->name('schedules.destroy');
    Route::get('/schedules/{group}/export/pdf', [ScheduleController::class, 'exportPdf'])->name('schedules.export-pdf');
    Route::get('/schedules/{group}/export/csv', [ScheduleController::class, 'exportCsv'])->name('schedules.export-csv');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
