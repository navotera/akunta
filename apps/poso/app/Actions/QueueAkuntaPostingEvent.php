<?php

namespace App\Actions;

use App\Models\IntegrationEvent;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class QueueAkuntaPostingEvent
{
    public function forSalesInvoice(SalesInvoice $invoice): IntegrationEvent
    {
        $invoice->loadMissing(['customer', 'items']);

        return IntegrationEvent::query()->firstOrCreate(
            [
                'idempotency_key' => 'poso:sales_invoice:'.$invoice->id.':published',
            ],
            [
                'tenant_id' => $invoice->tenant_id,
                'destination_app' => 'akunta',
                'event_type' => config('poso.webhook.events.sales_invoice_published'),
                'aggregate_type' => SalesInvoice::class,
                'aggregate_id' => $invoice->id,
                'payload' => [
                    'event_id' => (string) Str::ulid(),
                    'event_type' => config('poso.webhook.events.sales_invoice_published'),
                    'occurred_at' => Carbon::now()->toISOString(),
                    'source_app' => 'poso',
                    'source_id' => $invoice->id,
                    'tenant_id' => $invoice->tenant_id,
                    'journal_request' => $this->journalRequest(
                        document: $invoice,
                        documentType: 'sales_invoice',
                        partyType: 'customer',
                        party: [
                            'id' => $invoice->customer?->id,
                            'name' => $invoice->customer?->name,
                        ],
                    ),
                    'document' => [
                        'type' => 'sales_invoice',
                        'number' => $invoice->number,
                        'date' => optional($invoice->issued_at)->toDateString(),
                        'customer' => [
                            'id' => $invoice->customer?->id,
                            'name' => $invoice->customer?->name,
                        ],
                        'totals' => [
                            'subtotal' => (string) $invoice->subtotal,
                            'discount_total' => (string) $invoice->discount_total,
                            'tax_total' => (string) $invoice->tax_total,
                            'grand_total' => (string) $invoice->grand_total,
                        ],
                        'lines' => $invoice->items->map(fn ($item) => [
                            'name' => $item->name,
                            'quantity' => (string) $item->quantity,
                            'unit' => $item->unit,
                            'unit_price' => (string) $item->unit_price,
                            'tax_rate' => (string) $item->tax_rate,
                            'line_total' => (string) $item->line_total,
                        ])->values()->all(),
                    ],
                ],
                'status' => IntegrationEvent::STATUS_PENDING,
                'attempts' => 0,
                'available_at' => Carbon::now(),
            ]
        );
    }

    public function forPurchaseBill(PurchaseBill $bill): IntegrationEvent
    {
        $bill->loadMissing(['supplier', 'items']);

        return IntegrationEvent::query()->firstOrCreate(
            [
                'idempotency_key' => 'poso:purchase_bill:'.$bill->id.':published',
            ],
            [
                'tenant_id' => $bill->tenant_id,
                'destination_app' => 'akunta',
                'event_type' => config('poso.webhook.events.purchase_bill_published'),
                'aggregate_type' => PurchaseBill::class,
                'aggregate_id' => $bill->id,
                'payload' => [
                    'event_id' => (string) Str::ulid(),
                    'event_type' => config('poso.webhook.events.purchase_bill_published'),
                    'occurred_at' => Carbon::now()->toISOString(),
                    'source_app' => 'poso',
                    'source_id' => $bill->id,
                    'tenant_id' => $bill->tenant_id,
                    'journal_request' => $this->journalRequest(
                        document: $bill,
                        documentType: 'purchase_bill',
                        partyType: 'supplier',
                        party: [
                            'id' => $bill->supplier?->id,
                            'name' => $bill->supplier?->name,
                        ],
                    ),
                    'document' => [
                        'type' => 'purchase_bill',
                        'number' => $bill->number,
                        'date' => optional($bill->issued_at)->toDateString(),
                        'supplier' => [
                            'id' => $bill->supplier?->id,
                            'name' => $bill->supplier?->name,
                        ],
                        'totals' => [
                            'subtotal' => (string) $bill->subtotal,
                            'discount_total' => (string) $bill->discount_total,
                            'tax_total' => (string) $bill->tax_total,
                            'grand_total' => (string) $bill->grand_total,
                        ],
                        'lines' => $bill->items->map(fn ($item) => [
                            'name' => $item->name,
                            'quantity' => (string) $item->quantity,
                            'unit' => $item->unit,
                            'unit_price' => (string) $item->unit_price,
                            'tax_rate' => (string) $item->tax_rate,
                            'line_total' => (string) $item->line_total,
                        ])->values()->all(),
                    ],
                ],
                'status' => IntegrationEvent::STATUS_PENDING,
                'attempts' => 0,
                'available_at' => Carbon::now(),
            ]
        );
    }

    /**
     * @param  array{id: string|null, name: string|null}  $party
     * @return array<string, mixed>
     */
    private function journalRequest(SalesInvoice|PurchaseBill $document, string $documentType, string $partyType, array $party): array
    {
        return [
            'mode' => 'akunta_journal_template',
            'target_app' => 'akunta',
            'entity_id' => $document->accounting_entity_id,
            'document_type' => $documentType,
            'source_document' => [
                'id' => $document->id,
                'number' => $document->number,
                'date' => optional($document->issued_at)->toDateString(),
                'party_type' => $partyType,
                'party' => $party,
            ],
            'journal_template' => [
                'id' => $document->journal_template_id,
                'code' => $document->journal_template_code,
                'name' => $document->journal_template_name,
                'source' => 'akunta',
                'snapshot' => $document->journal_template_snapshot,
            ],
            'instantiate' => [
                'date' => optional($document->issued_at)->toDateString(),
                'reference' => $document->number,
                'memo' => $document->notes,
                'idempotency_key' => 'poso:'.$documentType.':'.$document->id.':journal',
                'amounts' => [
                    'subtotal' => (string) $document->subtotal,
                    'discount_total' => (string) $document->discount_total,
                    'tax_total' => (string) $document->tax_total,
                    'grand_total' => (string) $document->grand_total,
                ],
            ],
        ];
    }
}
