<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Teacher extends Model
{
    protected static function booted(): void
    {
        static::created(function (Teacher $teacher): void {
            $teacher->dayPreferences()->createMany(array_map(
                fn (array $preference): array => $preference,
                collect([1, 2, 3])
                    ->flatMap(fn (int $cycleWeek): array => array_map(
                        fn (int $weekday): array => [
                            'cycle_week' => $cycleWeek,
                            'weekday_no' => $weekday,
                            'preference' => $weekday >= 6 ? 'unavailable' : 'neutral',
                        ],
                        range(1, 7),
                    ))
                    ->all(),
            ));
        });
    }

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

    public function dayPreferences(): HasMany
    {
        return $this->hasMany(TeacherDayPreference::class)->orderBy('cycle_week')->orderBy('weekday_no');
    }

    public function absences(): HasMany
    {
        return $this->hasMany(TeacherAbsence::class);
    }
}
