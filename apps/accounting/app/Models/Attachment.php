<?php

namespace App\Models;

use Akunta\Rbac\Models\Entity;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class Attachment extends Model
{
    use HasUlids;

    protected $fillable = [
        'attachable_type',
        'attachable_id',
        'entity_id',
        'filename',
        'mime_type',
        'size_bytes',
        'disk',
        'path',
        'checksum_sha256',
        'description',
        'uploaded_by',
        'metadata',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'metadata'   => 'array',
    ];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(\Akunta\Rbac\Models\User::class, 'uploaded_by');
    }

    public function url(): ?string
    {
        $disk = Storage::disk($this->disk);
        if (! $disk->exists($this->path)) {
            return null;
        }
        if (method_exists($disk, 'temporaryUrl')) {
            try {
                return $disk->temporaryUrl($this->path, now()->addMinutes(5));
            } catch (\Throwable) {
                // not a presignable driver — fall through
            }
        }

        return $disk->url($this->path);
    }

    public function humanSize(): string
    {
        $b = $this->size_bytes;
        if ($b >= 1_048_576) {
            return number_format($b / 1_048_576, 1).' MB';
        }
        if ($b >= 1024) {
            return number_format($b / 1024, 1).' KB';
        }

        return $b.' B';
    }
}
