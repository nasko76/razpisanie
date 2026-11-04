<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

class AdminController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('schedules.index');
    }
}
