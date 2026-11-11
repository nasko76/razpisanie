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
        'auditorium_hours',
        'lecture_hours',
        'exercise_hours',
        'self_study_hours',
        'control_type',
    ];

    protected function casts(): array
    {
        return [
            'source_data' => 'array',
            'is_selected' => 'boolean',
            'auditorium_hours' => 'integer',
            'lecture_hours' => 'integer',
            'exercise_hours' => 'integer',
            'self_study_hours' => 'integer',
        ];
    }

    /**
     * @return array{auditorium_hours:int, lecture_hours:int, exercise_hours:int, self_study_hours:int, control_type:string|null}
     */
    public function hourValues(): array
    {
        return [
            'auditorium_hours' => (int) ($this->auditorium_hours ?? 0),
            'lecture_hours' => (int) ($this->lecture_hours ?? 0),
            'exercise_hours' => (int) ($this->exercise_hours ?? 0),
            'self_study_hours' => (int) ($this->self_study_hours ?? 0),
            'control_type' => $this->control_type,
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
