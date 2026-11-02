<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Teacher extends Model
{
    protected $fillable = [
        'first_name',
        'last_name',
        'title',
        'max_daily_hours',
        'weekly_workload_hours',
        'active',
        'unavailable_weekdays',
        'preferred_weekdays',
        'unavailable_periods',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'unavailable_weekdays' => 'array',
            'preferred_weekdays' => 'array',
            'unavailable_periods' => 'array',
        ];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TeacherAssignment::class);
    }
}
