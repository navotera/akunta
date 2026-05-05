<?php

declare(strict_types=1);

namespace App\Models;

use Akunta\Rbac\Models\Entity;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Latest-seen state per (entity, source_app, ref_type, ref_id).
 *
 * Drives filter dropdown UI ("list all customers POSO has ever sent
 * us") without scanning journal_entries. Upserted from webhook
 * payload on every journal posting that includes a per-line source.
 *
 * Per-entry historical snapshot lives in journal_entries.metadata
 * — registry holds latest, JSON snapshot holds at-time.
 */
class SourceRefRegistry extends Model
{
    use HasUlids;

    protected $table = 'source_ref_registry';

    protected $fillable = [
        'entity_id',
        'source_app',
        'ref_type',
        'ref_id',
        'last_code',
        'last_label',
        'last_attrs',
        'first_seen_at',
        'last_seen_at',
        'entry_count',
    ];

    protected $casts = [
        'last_attrs'    => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at'  => 'datetime',
        'entry_count'   => 'integer',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    /**
     * Idempotent upsert: insert or update by unique key
     * (entity_id, source_app, ref_type, ref_id).
     *
     * @param  array{ref_type: string, ref_id: string, ref_code?: ?string, ref_label?: ?string, ref_attrs?: ?array}  $source
     */
    public static function ingest(string $entityId, string $sourceApp, array $source): self
    {
        $now = now();

        $row = self::firstOrNew([
            'entity_id'  => $entityId,
            'source_app' => $sourceApp,
            'ref_type'   => $source['ref_type'],
            'ref_id'     => $source['ref_id'],
        ]);

        if (! $row->exists) {
            $row->first_seen_at = $now;
            $row->entry_count = 0;
        }

        $row->last_code    = $source['ref_code']   ?? $row->last_code;
        $row->last_label   = $source['ref_label']  ?? $row->last_label;
        $row->last_attrs   = $source['ref_attrs']  ?? $row->last_attrs;
        $row->last_seen_at = $now;
        $row->entry_count  = ($row->entry_count ?? 0) + 1;
        $row->save();

        return $row;
    }
}
