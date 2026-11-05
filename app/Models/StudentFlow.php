<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentFlow extends Model
{
    protected $fillable = ['name'];

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(StudentGroup::class, 'student_flow_group', 'student_flow_id', 'student_group_id')
            ->withTimestamps();
    }

    public function timetableEntries(): HasMany
    {
        return $this->hasMany(TimetableEntry::class);
    }

    public function teacherAssignments(): HasMany
    {
        return $this->hasMany(TeacherAssignment::class);
    }
}
