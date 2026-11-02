<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ScheduleController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::get('/dashboard', [AdminController::class, 'index'])->name('dashboard');
    Route::get('/admin', [AdminController::class, 'index'])->name('admin.dashboard');

    Route::get('/admin/catalog', [CatalogController::class, 'index'])->name('catalog.index');
    Route::post('/admin/catalog/specialties', [CatalogController::class, 'storeSpecialty'])->name('catalog.specialties.store');
    Route::patch('/admin/catalog/specialties/{specialty}', [CatalogController::class, 'updateSpecialty'])->name('catalog.specialties.update');
    Route::post('/admin/catalog/curriculum-versions', [CatalogController::class, 'storeCurriculumVersion'])->name('catalog.curriculum-versions.store');
    Route::patch('/admin/catalog/curriculum-versions/{curriculumVersion}', [CatalogController::class, 'updateCurriculumVersion'])->name('catalog.curriculum-versions.update');
    Route::post('/admin/catalog/groups', [CatalogController::class, 'storeGroup'])->name('catalog.groups.store');
    Route::patch('/admin/catalog/groups/{group}', [CatalogController::class, 'updateGroup'])->name('catalog.groups.update');
    Route::post('/admin/catalog/subjects', [CatalogController::class, 'storeSubject'])->name('catalog.subjects.store');
    Route::patch('/admin/catalog/subjects/{subject}', [CatalogController::class, 'updateSubject'])->name('catalog.subjects.update');
    Route::post('/admin/catalog/curriculum-subjects', [CatalogController::class, 'storeCurriculumSubject'])->name('catalog.curriculum-subjects.store');
    Route::post('/admin/catalog/teachers', [CatalogController::class, 'storeTeacher'])->name('catalog.teachers.store');
    Route::patch('/admin/catalog/teachers/{teacher}', [CatalogController::class, 'updateTeacher'])->name('catalog.teachers.update');
    Route::post('/admin/catalog/teacher-assignments', [CatalogController::class, 'storeTeacherAssignment'])->name('catalog.teacher-assignments.store');
    Route::post('/admin/catalog/rooms', [CatalogController::class, 'storeRoom'])->name('catalog.rooms.store');
    Route::patch('/admin/catalog/rooms/{room}', [CatalogController::class, 'updateRoom'])->name('catalog.rooms.update');

    Route::get('/schedules', [ScheduleController::class, 'index'])->name('schedules.index');
    Route::post('/schedules/generate', [ScheduleController::class, 'generate'])->name('schedules.generate');
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
