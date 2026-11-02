<?php

namespace App\Http\Controllers;

use App\Models\StudentGroup;
use App\Services\ScheduleService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    public function index()
    {
        $groups = StudentGroup::with('specialty')->get();

        return view('schedules.index', compact('groups'));
    }

    public function generate(Request $request, ScheduleService $scheduleService)
    {
        $request->validate([
            'group_id' => 'required|exists:student_groups,id',
        ]);

        $group = StudentGroup::with(['specialty', 'curriculumVersion'])->findOrFail($request->group_id);

        $entries = $scheduleService->generateForGroup($group, '2026/2027', 'winter');

        return view('schedules.show', compact('group', 'entries'));
    }

    public function exportPdf(Request $request)
    {
        $group = StudentGroup::with([
            'specialty',
            'timetableEntries.subject',
            'timetableEntries.teacher',
            'timetableEntries.room',
        ])->findOrFail($request->group_id);

        $entries = $group->timetableEntries()->with(['subject', 'teacher', 'room'])->get();

        $pdf = Pdf::loadView('schedules.pdf', [
            'group' => $group,
            'entries' => $entries,
        ]);

        return $pdf->download('raspisanie-' . $group->group_name . '.pdf');
    }
}