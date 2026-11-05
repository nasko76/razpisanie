<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherDayPreference extends Model
{
    protected $fillable = [
        'teacher_id',
        'cycle_week',
        'weekday_no',
        'preference',
    ];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
