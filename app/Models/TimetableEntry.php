<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimetableEntry extends Model
{
    protected $fillable = [
        'group_id',
        'subject_id',
        'student_flow_id',
        'teacher_id',
        'room_id',
        'custom_subject_name',
        'note',
        'coverage_type',
        'academic_year',
        'semester_type',
        'cycle_week',
        'weekday_no',
        'start_time',
        'end_time',
        'duration_hours',
        'is_in_pool',
        'pool_reason',
        'auditorium_hours',
        'self_study_hours',
        'locked',
    ];

    protected function casts(): array
    {
        return [
            'is_in_pool' => 'boolean',
            'locked' => 'boolean',
            'auditorium_hours' => 'decimal:2',
            'self_study_hours' => 'decimal:2',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(StudentGroup::class, 'group_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function studentFlow(): BelongsTo
    {
        return $this->belongsTo(StudentFlow::class);
    }
}
