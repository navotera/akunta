<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Models\TaxCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Record a sales transaction as a balanced general journal.
 *
 * Builds in a single DB transaction:
 *   D  Akun Penerima (Kas/Bank/Piutang)   = subtotal + ppn
 *   C    Akun Pendapatan                  = subtotal
 *   C    Tax Account (PPN Keluaran)       = ppn       — only if tax_code chosen
 *
 * Returns the saved Journal (status = draft). Caller redirects to journal
 * edit/post screen. PPN computation uses TaxCode->computeOn() — same path as
 * the rest of the codebase, no rounding drift.
 */
class RecordSalesAction
{
    /**
     * @param  array{
     *   entity_id: string,
     *   date: string,
     *   target_account_id: string,
     *   revenue_account_id: string,
     *   subtotal: numeric,
     *   tax_code_id?: ?string,
     *   reference?: ?string,
     *   memo?: ?string,
     *   created_by?: ?string,
     * }  $input
     */
    public function execute(array $input): Journal
    {
        $entityId = $input['entity_id'];
        $date = $input['date'];
        $subtotal = (string) $input['subtotal'];

        if (bccomp($subtotal, '0', 2) <= 0) {
            throw new RuntimeException('Subtotal harus lebih besar dari 0.');
        }

        $target = Account::query()->where('entity_id', $entityId)->where('id', $input['target_account_id'])->firstOrFail();
        $revenue = Account::query()->where('entity_id', $entityId)->where('id', $input['revenue_account_id'])->firstOrFail();

        $taxCode = ! empty($input['tax_code_id'])
            ? TaxCode::query()->where('entity_id', $entityId)->where('id', $input['tax_code_id'])->where('is_active', true)->first()
            : null;
        $taxAmount = $taxCode ? $taxCode->computeOn($subtotal) : '0.00';
        $total = bcadd($subtotal, $taxAmount, 2);

        $period = Period::query()
            ->where('entity_id', $entityId)
            ->where('status', Period::STATUS_OPEN)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->orderByDesc('start_date')
            ->first();

        if ($period === null) {
            throw new RuntimeException('Tidak ada periode terbuka yang mencakup tanggal ini. Buka periode dulu.');
        }

        return DB::transaction(function () use ($entityId, $date, $subtotal, $taxAmount, $total, $target, $revenue, $taxCode, $period, $input) {
            $journal = Journal::create([
                'entity_id' => $entityId,
                'period_id' => $period->id,
                'type' => Journal::TYPE_GENERAL,
                'journal_mode' => $input['journal_mode'] ?? Journal::MODE_INTERNAL,
                'number' => $this->nextNumber($entityId, $date),
                'date' => $date,
                'reference' => $input['reference'] ?? null,
                'memo' => $input['memo'] ?? null,
                'source_app' => 'accounting',
                'status' => Journal::STATUS_DRAFT,
                'created_by' => $input['created_by'] ?? null,
            ]);

            $lineNo = 1;

            JournalEntry::create([
                'journal_id' => $journal->id,
                'line_no' => $lineNo++,
                'account_id' => $target->id,
                'debit' => $total,
                'credit' => '0',
                'memo' => $input['memo'] ?? 'Penerimaan penjualan',
            ]);

            JournalEntry::create([
                'journal_id' => $journal->id,
                'line_no' => $lineNo++,
                'account_id' => $revenue->id,
                'debit' => '0',
                'credit' => $subtotal,
                'memo' => $input['memo'] ?? 'Pendapatan penjualan',
            ]);

            if ($taxCode && bccomp($taxAmount, '0', 2) > 0 && $taxCode->tax_account_id) {
                JournalEntry::create([
                    'journal_id' => $journal->id,
                    'line_no' => $lineNo++,
                    'account_id' => $taxCode->tax_account_id,
                    'tax_code_id' => $taxCode->id,
                    'tax_base' => $subtotal,
                    'debit' => '0',
                    'credit' => $taxAmount,
                    'memo' => 'PPN Keluaran '.$taxCode->code,
                ]);
            }

            $this->attachFiles($journal, $input['attachments'] ?? []);

            return $journal->refresh();
        });
    }

    /**
     * Persist FileUpload-saved paths as Attachment records on the journal.
     *
     * @param  array<int, string>  $paths  storage paths previously persisted by the upload pipeline
     */
    private function attachFiles(Journal $journal, array $paths): void
    {
        if (empty($paths)) {
            return;
        }

        $disk = config('filesystems.default');
        foreach ($paths as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }
            $size = 0;
            $mime = null;
            try {
                $size = Storage::disk($disk)->size($path);
                $mime = Storage::disk($disk)->mimeType($path);
            } catch (\Throwable) {
                // disk may not support introspection — leave defaults
            }

            $journal->attachments()->create([
                'entity_id' => $journal->entity_id,
                'filename' => basename($path),
                'mime_type' => $mime,
                'size_bytes' => $size,
                'disk' => $disk,
                'path' => $path,
                'description' => null,
                'uploaded_by' => Auth::id(),
            ]);
        }
    }

    private function nextNumber(string $entityId, string $date): string
    {
        $prefix = 'JS-'.Carbon::parse($date)->format('Ym');
        $last = Journal::query()
            ->where('entity_id', $entityId)
            ->where('number', 'like', $prefix.'-%')
            ->orderByDesc('number')
            ->value('number');

        $next = 1;
        if ($last && preg_match('/-(\d+)$/', $last, $m)) {
            $next = (int) $m[1] + 1;
        }

        return $prefix.'-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
