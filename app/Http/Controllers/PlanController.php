<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PlanController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Plan::class);

        $plans = Plan::orderBy('day_of_week')->orderBy('start_time')->get();

        return view('plans.index', compact('plans'));
    }

    public function create()
    {
        $this->authorize('create', Plan::class);

        return view('plans.create');
    }

    public function store(Request $request)
    {
        $this->authorize('create', Plan::class);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'room' => ['nullable', 'string', 'max:255'],
            'teacher_name' => ['nullable', 'string', 'max:255'],
            'group_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,draft,archived'],
        ]);

        $validated['created_by'] = Auth::id();

        Plan::create($validated);

        return redirect()->route('plans.index')->with('success', 'Планът е създаден.');
    }

    public function edit(Plan $plan)
    {
        $this->authorize('update', $plan);

        return view('plans.edit', compact('plan'));
    }

    public function update(Request $request, Plan $plan)
    {
        $this->authorize('update', $plan);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'room' => ['nullable', 'string', 'max:255'],
            'teacher_name' => ['nullable', 'string', 'max:255'],
            'group_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,draft,archived'],
        ]);

        $plan->update($validated);

        return redirect()->route('plans.index')->with('success', 'Планът е обновен.');
    }

    public function destroy(Plan $plan)
    {
        $this->authorize('delete', $plan);

        $plan->delete();

        return redirect()->route('plans.index')->with('success', 'Планът е изтрит.');
    }
}