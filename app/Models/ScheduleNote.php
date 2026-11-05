<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleNote extends Model
{
    protected $fillable = [
        'group_id',
        'academic_year',
        'semester_type',
        'note',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(StudentGroup::class, 'group_id');
    }
}
