<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherAssignment extends Model
{
    protected $fillable = [
        'teacher_id',
        'subject_id',
        'curriculum_version_id',
        'course_year',
        'semester',
        'coverage_type',
        'source_data',
    ];

    protected function casts(): array
    {
        return [
            'source_data' => 'array',
        ];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class);
    }
}
