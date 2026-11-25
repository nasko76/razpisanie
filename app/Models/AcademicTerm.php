<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcademicTerm extends Model
{
    public const CURRENT = 'current';

    public const PLANNING = 'planning';

    public const ARCHIVED = 'archived';

    protected $fillable = [
        'academic_year',
        'semester_type',
        'status',
    ];

    public function label(): string
    {
        return ($this->semester_type === 'winter' ? 'Зима ' : 'Лято ').$this->academic_year;
    }
}
