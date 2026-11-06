<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    protected $fillable = [
        'code',
        'name',
        'auditorium_hours',
        'lecture_hours',
        'exercise_hours',
        'self_study_hours',
        'control_type',
    ];

    public function curriculumSubjects(): HasMany
    {
        return $this->hasMany(CurriculumSubject::class);
    }

    public function timetableEntries(): HasMany
    {
        return $this->hasMany(TimetableEntry::class);
    }

    public function studentFlows(): HasMany
    {
        return $this->hasMany(StudentFlow::class);
    }
}
