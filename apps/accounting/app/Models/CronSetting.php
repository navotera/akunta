<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CronSetting extends Model
{
    public const SINGLETON_ID = 1;
    public const RETENTION_MIN = 30;
    public const RETENTION_MAX = 120;

    protected $fillable = [
        'retention_days',
    ];

    protected $casts = [
        'retention_days' => 'int',
    ];

    public $incrementing = false;

    protected $keyType = 'int';

    public static function instance(): self
    {
        return self::firstOrCreate(
            ['id' => self::SINGLETON_ID],
            ['retention_days' => 30],
        );
    }
}
