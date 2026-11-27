<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key/value settings of the whole installation (not of an organisation). Global
 * table by decision; only the Platform Owner writes them.
 */
class InstallationSetting extends Model
{
    public const APP_NAME = 'branding.app_name';

    public const LOGO_PATH = 'branding.logo_path';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'updated_by'];

    public static function read(string $key): ?string
    {
        $value = static::query()->whereKey($key)->value('value');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function write(string $key, ?string $value, ?string $userId = null): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId]);
    }
}
