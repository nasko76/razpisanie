<?php

namespace App\Http\Controllers;

use App\Models\SoftwareSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        DB::transaction(function () use ($data): void {
            SoftwareSetting::query()->updateOrCreate(
                ['key' => 'public_schedule_notice'],
                ['value' => $data['public_schedule_notice'] ?? ''],
            );
        });

        return redirect()
            ->route('settings.index')
            ->with('status', 'Общите настройки са запазени.');
    }
}
