<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    protected $fillable = [
        'name',
        'room_type',
        'capacity',
        'is_active',
        'equipment',
        'unavailable_periods',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'equipment' => 'array',
            'unavailable_periods' => 'array',
        ];
    }
}
