<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Specialty extends Model
{
    protected $fillable = [
        'name',
        'code',
        'degree',
    ];

    public function curriculumVersions(): HasMany
    {
        return $this->hasMany(CurriculumVersion::class);
    }
}
