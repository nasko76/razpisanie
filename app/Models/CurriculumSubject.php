<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurriculumSubject extends Model
{
    protected $table = 'curriculum_subjects';

    protected $fillable = [
        'curriculum_version_id',
        'subject_id',
        'semester_no',
        'course_year',
        'contact_hours',
        'self_study_hours',
        'scheduled_hours',
        'cycle_weeks',
        'control_type',
    ];

    protected function casts(): array
    {
        return [
            'cycle_weeks' => 'array',
        ];
    }

    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
