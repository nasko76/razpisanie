<?php

namespace App\Http\Controllers;

use App\Models\CurriculumVersion;
use App\Services\StudyDataImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StudyDataImportController extends Controller
{
    public function index(): View
    {
        return view('admin.imports.index', [
            'curriculumVersions' => CurriculumVersion::query()
                ->with('specialty')
                ->orderByDesc('version_year')
                ->get(),
        ]);
    }

    public function preview(Request $request, StudyDataImportService $importer): View
    {
        $data = $request->validate([
            'kind' => ['required', 'in:curriculum,teachers'],
            'curriculum_version_id' => ['required_if:kind,curriculum', 'nullable', 'exists:curriculum_versions,id'],
            'employment_category' => ['required_if:kind,teachers', 'nullable', 'in:full_time,honorary'],
            'academic_year' => ['required_if:kind,teachers', 'nullable', 'regex:/^\d{4}\/\d{4}$/'],
            'file' => ['required', 'file', 'max:20480'],
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        $html = $file->getContent();

        if (! in_array($extension, ['html', 'htm'], true) || stripos($html, '<table') === false) {
            throw ValidationException::withMessages([
                'file' => 'Поддържат се HTML/HTM експорти с таблици. Excel .xls/.xlsx файлове не се поддържат.',
            ]);
        }

        $version = isset($data['curriculum_version_id'])
            ? CurriculumVersion::query()->with('specialty')->findOrFail($data['curriculum_version_id'])
            : null;
        $preview = $importer->preview(
            $data['kind'],
            $html,
            $version,
            $data['employment_category'] ?? null,
            $data['academic_year'] ?? null,
        );
        $token = (string) Str::uuid();
        $request->session()->put('study_data_imports.'.$token, $preview);

        return view('admin.imports.preview', compact('preview', 'token'));
    }

    public function confirm(Request $request, StudyDataImportService $importer): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'uuid'],
            'subjects' => ['nullable', 'array'],
            'subjects.*' => ['nullable', 'string', 'max:20'],
            'teachers' => ['nullable', 'array'],
            'teachers.*' => ['nullable', 'string', 'max:20'],
        ]);
        $sessionKey = 'study_data_imports.'.$data['token'];
        $preview = $request->session()->get($sessionKey);

        if (! is_array($preview)) {
            throw ValidationException::withMessages([
                'token' => 'Прегледът е изтекъл. Качете файла отново.',
            ]);
        }

        $result = $importer->confirm($preview, $data);
        $request->session()->forget($sessionKey);

        return redirect()->route('catalog.index')->with(
            'status',
            "Импортът приключи: {$result['created']} добавени, {$result['updated']} обновени, {$result['skipped']} без промяна или пропуснати.",
        );
    }
}
