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
        'source_data',
        'subject_category',
        'is_selected',
    ];

    protected function casts(): array
    {
        return [
            'source_data' => 'array',
            'is_selected' => 'boolean',
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
