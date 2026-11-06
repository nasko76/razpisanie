<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentGroup extends Model
{
    protected $table = 'student_groups';

    protected $fillable = [
        'specialty_id',
        'curriculum_version_id',
        'course_year',
        'group_name',
        'student_count',
    ];

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }

    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class);
    }

    public function timetableEntries(): HasMany
    {
        return $this->hasMany(TimetableEntry::class, 'group_id');
    }

    public function teacherAssignments(): HasMany
    {
        return $this->hasMany(TeacherAssignment::class, 'student_group_id');
    }

    public function studentFlows(): BelongsToMany
    {
        return $this->belongsToMany(StudentFlow::class, 'student_flow_group', 'student_group_id', 'student_flow_id')
            ->withTimestamps();
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->course_year.' курс'.($this->group_name ? ' · група '.$this->group_name : '');
    }
}
