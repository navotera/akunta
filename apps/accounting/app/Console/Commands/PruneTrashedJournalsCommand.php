<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Models\Journal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PruneTrashedJournalsCommand extends Command
{
    protected $signature = 'accounting:prune-trashed-journals';

    protected $description = 'Permanently delete journals that have been trashed for more than 30 days.';

    public function handle(): int
    {
        $cutoff = now()->subDays(30);
        $deleted = 0;

        Journal::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($journals) use (&$deleted): void {
                foreach ($journals as $journal) {
                    $this->purge($journal);
                    $deleted++;
                }
            });

        $this->info("Permanently deleted {$deleted} trashed journal(s) older than 30 days.");

        return self::SUCCESS;
    }

    private function purge(Journal $journal): void
    {
        $attachments = Attachment::withTrashed()
            ->where('attachable_type', Journal::class)
            ->where('attachable_id', $journal->id)
            ->get();

        DB::transaction(function () use ($journal, $attachments): void {
            foreach ($attachments as $attachment) {
                $attachment->forceDelete();
            }
            $journal->forceDelete();
        });

        foreach ($attachments as $attachment) {
            $paths = array_filter([
                $attachment->path,
                data_get($attachment->metadata, 'thumbnail.path'),
            ], fn ($path): bool => is_string($path) && $path !== '');
            if ($paths !== []) {
                Storage::disk($attachment->disk)->delete($paths);
            }
        }
    }
}
