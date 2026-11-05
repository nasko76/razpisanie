<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SoftwareSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'value',
    ];

    public static function boolean(string $key, bool $default = false): bool
    {
        $value = static::query()->whereKey($key)->value('value');

        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function text(string $key, string $default = ''): string
    {
        return (string) (static::query()->whereKey($key)->value('value') ?? $default);
    }
}
