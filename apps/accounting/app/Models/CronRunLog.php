<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class CronRunLog extends Model
{
    use HasUlids;

    public const OUTPUT_LIMIT_BYTES = 4096;

    protected $fillable = [
        'command',
        'mutex_name',
        'started_at',
        'finished_at',
        'duration_ms',
        'exit_code',
        'failed',
        'output',
        'exception',
    ];

    protected $casts = [
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
        'failed'      => 'bool',
        'duration_ms' => 'int',
        'exit_code'   => 'int',
    ];

    public static function truncateOutput(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (strlen($value) <= self::OUTPUT_LIMIT_BYTES) {
            return $value;
        }

        return substr($value, 0, self::OUTPUT_LIMIT_BYTES - 32)."\n…[truncated]";
    }
}
