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
        'active',
    ];

    public function assignments(): HasMany
    {
        return $this->hasMany(TeacherAssignment::class);
    }
}