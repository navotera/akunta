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
 * Record a purchase transaction as a balanced general journal.
 *
 * Builds in a single DB transaction:
 *   D  Akun Pembelian (Persediaan/Beban)    = subtotal
 *   D  PPN Masukan (input_vat tax account)  = ppn        — only if tax_code chosen
 *   C    Akun Pembayaran (Kas/Bank/Hutang)  = subtotal + ppn
 */
class RecordPurchaseAction
{
    /**
     * @param  array{
     *   entity_id: string,
     *   date: string,
     *   purchase_account_id: string,
     *   payment_account_id: string,
     *   subtotal: numeric,
     *   tax_code_id?: ?string,
     *   reference?: ?string,
     *   memo?: ?string,
     *   created_by?: ?string,
     *   attachments?: array<int, string>,
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

        $purchase = Account::query()->where('entity_id', $entityId)->where('id', $input['purchase_account_id'])->firstOrFail();
        $payment = Account::query()->where('entity_id', $entityId)->where('id', $input['payment_account_id'])->firstOrFail();

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

        return DB::transaction(function () use ($entityId, $date, $subtotal, $taxAmount, $total, $purchase, $payment, $taxCode, $period, $input) {
            $journal = Journal::create([
                'entity_id' => $entityId,
                'period_id' => $period->id,
                'type' => Journal::TYPE_GENERAL,
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
                'account_id' => $purchase->id,
                'debit' => $subtotal,
                'credit' => '0',
                'memo' => $input['memo'] ?? 'Pembelian',
            ]);

            if ($taxCode && bccomp($taxAmount, '0', 2) > 0 && $taxCode->tax_account_id) {
                JournalEntry::create([
                    'journal_id' => $journal->id,
                    'line_no' => $lineNo++,
                    'account_id' => $taxCode->tax_account_id,
                    'tax_code_id' => $taxCode->id,
                    'tax_base' => $subtotal,
                    'debit' => $taxAmount,
                    'credit' => '0',
                    'memo' => 'PPN Masukan '.$taxCode->code,
                ]);
            }

            JournalEntry::create([
                'journal_id' => $journal->id,
                'line_no' => $lineNo++,
                'account_id' => $payment->id,
                'debit' => '0',
                'credit' => $total,
                'memo' => $input['memo'] ?? 'Pembayaran pembelian',
            ]);

            $this->attachFiles($journal, $input['attachments'] ?? []);

            return $journal->refresh();
        });
    }

    /**
     * @param  array<int, string>  $paths
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
        $prefix = 'JP-'.Carbon::parse($date)->format('Ym');
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
