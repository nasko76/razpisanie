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
        $groupName = $this->group_name;
        if ($groupName === '1') {
            $groupCount = static::query()
                ->where('specialty_id', $this->specialty_id)
                ->where('curriculum_version_id', $this->curriculum_version_id)
                ->where('course_year', $this->course_year)
                ->count();

            if ($groupCount === 1) {
                $groupName = null;
            }
        }

        return $this->course_year.' курс'.($groupName ? ' · група '.$groupName : '');
    }
}
