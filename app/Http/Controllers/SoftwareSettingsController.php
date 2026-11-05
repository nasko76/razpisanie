<?php

namespace App\Http\Controllers;

use App\Models\SoftwareSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SoftwareSettingsController extends Controller
{
    public function index(): View
    {
        return view('admin.settings', [
            'publicScheduleNotice' => SoftwareSetting::text('public_schedule_notice'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'public_schedule_notice' => ['nullable', 'string', 'max:1000'],
        ]);

        SoftwareSetting::query()->updateOrCreate(
            ['key' => 'public_schedule_notice'],
            ['value' => $data['public_schedule_notice'] ?? ''],
        );

        return redirect()
            ->route('settings.index')
            ->with('status', 'Общите настройки са запазени.');
    }
}
