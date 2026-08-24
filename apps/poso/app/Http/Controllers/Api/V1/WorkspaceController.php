<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\IntegrationEvent;
use App\Models\JournalTemplateMapping;
use App\Models\Product;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Support\AkuntaReferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WorkspaceController extends Controller
{
    use ResolvesTenant;

    public function __construct(private readonly AkuntaReferences $akuntaReferences) {}

    public function dashboard(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        $salesTotal = (float) SalesInvoice::query()->where('tenant_id', $tenantId)->sum('grand_total');
        $purchaseTotal = (float) PurchaseBill::query()->where('tenant_id', $tenantId)->sum('grand_total');
        $pendingEvents = IntegrationEvent::query()
            ->where('tenant_id', $tenantId)
            ->where('destination_app', 'akunta')
            ->where('status', IntegrationEvent::STATUS_PENDING)
            ->count();

        return response()->json([
            'data' => [
                'cards' => [
                    ['label' => 'Penjualan', 'value' => $salesTotal, 'format' => 'money'],
                    ['label' => 'Pembelian', 'value' => $purchaseTotal, 'format' => 'money'],
                    ['label' => 'Pelanggan', 'value' => Customer::query()->where('tenant_id', $tenantId)->count(), 'format' => 'number'],
                    ['label' => 'Webhook Pending', 'value' => $pendingEvents, 'format' => 'number'],
                ],
                'recent_transactions' => $this->recentTransactions($tenantId),
                'sync' => [
                    'pending' => $pendingEvents,
                    'sent' => IntegrationEvent::query()->where('tenant_id', $tenantId)->where('status', IntegrationEvent::STATUS_SENT)->count(),
                    'failed' => IntegrationEvent::query()->where('tenant_id', $tenantId)->where('status', IntegrationEvent::STATUS_FAILED)->count(),
                ],
            ],
        ]);
    }

    public function customers(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        $query = Customer::query()
            ->withCount('salesInvoices')
            ->withSum('salesInvoices as sales_total', 'grand_total')
            ->where('tenant_id', $tenantId)
            ->latest();

        $this->applySearch($query, $request, ['code', 'name', 'email', 'phone']);

        return response()->json([
            'data' => $query->limit(80)->get()->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'code' => $customer->code,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'address' => $customer->address,
                'transactions_count' => $customer->sales_invoices_count,
                'total' => (float) ($customer->sales_total ?? 0),
            ])->all(),
        ]);
    }

    public function storeCustomer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:1000'],
        ]);

        $customer = Customer::query()->create($data + ['tenant_id' => $this->tenantId($request)]);

        return response()->json(['data' => $customer], 201);
    }

    public function suppliers(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        $query = Supplier::query()
            ->withCount('purchaseBills')
            ->withSum('purchaseBills as purchase_total', 'grand_total')
            ->where('tenant_id', $tenantId)
            ->latest();

        $this->applySearch($query, $request, ['code', 'name', 'email', 'phone']);

        return response()->json([
            'data' => $query->limit(80)->get()->map(fn (Supplier $supplier) => [
                'id' => $supplier->id,
                'code' => $supplier->code,
                'name' => $supplier->name,
                'email' => $supplier->email,
                'phone' => $supplier->phone,
                'address' => $supplier->address,
                'transactions_count' => $supplier->purchase_bills_count,
                'total' => (float) ($supplier->purchase_total ?? 0),
            ])->all(),
        ]);
    }

    public function storeSupplier(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:1000'],
        ]);

        $supplier = Supplier::query()->create($data + ['tenant_id' => $this->tenantId($request)]);

        return response()->json(['data' => $supplier], 201);
    }

    public function products(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        $query = Product::query()->where('tenant_id', $tenantId)->latest();
        $this->applySearch($query, $request, ['sku', 'name', 'type', 'unit']);

        return response()->json([
            'data' => $query->limit(100)->get()->map(fn (Product $product) => $this->serializeProduct($product))->all(),
        ]);
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sku' => ['nullable', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:180'],
            'type' => ['required', 'in:goods,service'],
            'unit' => ['nullable', 'string', 'max:30'],
            'sales_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $product = Product::query()->create($data + ['tenant_id' => $this->tenantId($request)]);

        return response()->json(['data' => $this->serializeProduct($product)], 201);
    }

    public function priceLists(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Product::query()
                ->where('tenant_id', $this->tenantId($request))
                ->orderBy('name')
                ->limit(100)
                ->get()
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'sales_price' => (float) $product->sales_price,
                    'purchase_price' => (float) $product->purchase_price,
                    'margin' => (float) $product->sales_price - (float) $product->purchase_price,
                    'tax_rate' => (float) $product->tax_rate,
                ])
                ->all(),
        ]);
    }

    public function inventory(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Product::query()
                ->where('tenant_id', $this->tenantId($request))
                ->where('type', 'goods')
                ->orderBy('name')
                ->limit(100)
                ->get()
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'unit' => $product->unit,
                    'stock_on_hand' => (float) ($product->metadata['stock_on_hand'] ?? 0),
                    'reorder_point' => (float) ($product->metadata['reorder_point'] ?? 0),
                    'warehouse' => $product->metadata['warehouse'] ?? 'Gudang Utama',
                ])
                ->all(),
        ]);
    }

    public function payments(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        $sales = SalesInvoice::query()
            ->with('customer')
            ->where('tenant_id', $tenantId)
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->latest('issued_at')
            ->limit(30)
            ->get()
            ->map(fn (SalesInvoice $invoice) => [
                'id' => $invoice->id,
                'type' => 'Masuk',
                'number' => $invoice->number,
                'party' => $invoice->customer?->name,
                'due_at' => optional($invoice->due_at)->toDateString(),
                'amount' => (float) $invoice->grand_total,
                'status' => $invoice->payment_status,
            ]);

        $purchases = PurchaseBill::query()
            ->with('supplier')
            ->where('tenant_id', $tenantId)
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->latest('issued_at')
            ->limit(30)
            ->get()
            ->map(fn (PurchaseBill $bill) => [
                'id' => $bill->id,
                'type' => 'Keluar',
                'number' => $bill->number,
                'party' => $bill->supplier?->name,
                'due_at' => optional($bill->due_at)->toDateString(),
                'amount' => (float) $bill->grand_total,
                'status' => $bill->payment_status,
            ]);

        return response()->json(['data' => $sales->merge($purchases)->values()->all()]);
    }

    public function reports(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        return response()->json([
            'data' => [
                'sales_total' => (float) SalesInvoice::query()->where('tenant_id', $tenantId)->sum('grand_total'),
                'purchase_total' => (float) PurchaseBill::query()->where('tenant_id', $tenantId)->sum('grand_total'),
                'unpaid_sales' => (float) SalesInvoice::query()->where('tenant_id', $tenantId)->where('payment_status', 'unpaid')->sum('grand_total'),
                'unpaid_purchases' => (float) PurchaseBill::query()->where('tenant_id', $tenantId)->where('payment_status', 'unpaid')->sum('grand_total'),
            ],
        ]);
    }

    public function integrationEvents(Request $request): JsonResponse
    {
        return response()->json([
            'data' => IntegrationEvent::query()
                ->where('tenant_id', $this->tenantId($request))
                ->where('destination_app', 'akunta')
                ->latest()
                ->limit(80)
                ->get()
                ->map(fn (IntegrationEvent $event) => [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'aggregate_id' => $event->aggregate_id,
                    'status' => $event->status,
                    'attempts' => $event->attempts,
                    'available_at' => optional($event->available_at)->toISOString(),
                    'sent_at' => optional($event->sent_at)->toISOString(),
                    'last_error' => $event->last_error,
                    'template' => $event->payload['journal_request']['journal_template']['code'] ?? null,
                    'created_at' => optional($event->created_at)->toISOString(),
                ])
                ->all(),
        ]);
    }

    public function users(): JsonResponse
    {
        return response()->json(['data' => $this->tableRows('users', ['id', 'name', 'email', 'status', 'created_at'], 80)]);
    }

    public function roles(): JsonResponse
    {
        return response()->json(['data' => $this->tableRows('roles', ['id', 'code', 'name', 'is_preset', 'created_at'], 80)]);
    }

    public function auditLog(): JsonResponse
    {
        return response()->json(['data' => $this->tableRows('audit_log', ['id', 'event', 'auditable_type', 'auditable_id', 'user_id', 'created_at'], 80)]);
    }

    public function settings(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $entityId = $this->accountingEntityId($request);

        return response()->json([
            'data' => [
                'entity_id' => $entityId,
                'accounting_templates' => $this->akuntaReferences->journalTemplates($entityId),
                'journal_template_mappings' => $this->journalTemplateMappings($tenantId, $entityId),
                'transaction_types' => collect(JournalTemplateMapping::transactionTypeLabels())
                    ->map(fn (string $label, string $key) => [
                        'key' => $key,
                        'label' => $label,
                        'description' => $this->transactionTypeDescription($key),
                    ])
                    ->values()
                    ->all(),
                'document_numbering' => [
                    ['type' => 'Invoice Penjualan', 'prefix' => 'INV', 'sample' => 'INV/2026/05/0001'],
                    ['type' => 'Tagihan Pembelian', 'prefix' => 'BILL', 'sample' => 'BILL/2026/05/0001'],
                ],
                'taxes' => [
                    ['name' => 'Non PPN', 'rate' => 0],
                    ['name' => 'PPN', 'rate' => 11],
                    ['name' => 'PPN', 'rate' => 12],
                ],
            ],
        ]);
    }

    public function saveJournalTemplateMapping(Request $request): JsonResponse
    {
        $data = $request->validate([
            'accounting_entity_id' => ['nullable', 'string', 'max:80'],
            'transaction_type' => ['required', 'in:'.implode(',', JournalTemplateMapping::transactionTypes())],
            'journal_template_id' => ['required', 'string', 'max:80'],
            'is_required' => ['nullable', 'boolean'],
            'auto_queue_webhook' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $tenantId = $this->tenantId($request);
        $entityId = $data['accounting_entity_id'] ?? $this->accountingEntityId($request);
        if ($entityId === null || $entityId === '') {
            return response()->json([
                'errors' => [[
                    'code' => 'accounting_entity_required',
                    'message' => 'Pemetaan template jurnal wajib menggunakan entitas Akunta aktif.',
                ]],
            ], 422);
        }
        $template = $this->akuntaReferences->findJournalTemplate(
            id: $data['journal_template_id'],
            entityId: $entityId,
            documentType: $this->documentTypeForMapping($data['transaction_type']),
        );

        if ($template === null) {
            return response()->json([
                'errors' => [[
                    'code' => 'journal_template_not_found',
                    'message' => 'Template jurnal tidak ditemukan pada entitas Akunta aktif.',
                ]],
            ], 422);
        }

        $mapping = JournalTemplateMapping::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'accounting_entity_id' => $entityId,
                'transaction_type' => $data['transaction_type'],
            ],
            [
                'journal_template_id' => $template['id'],
                'journal_template_code' => $template['code'],
                'journal_template_name' => $template['name'],
                'journal_template_snapshot' => $template,
                'is_required' => $data['is_required'] ?? true,
                'auto_queue_webhook' => $data['auto_queue_webhook'] ?? true,
                'is_active' => $data['is_active'] ?? true,
            ],
        );

        return response()->json(['data' => $this->serializeMapping($mapping)]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function journalTemplateMappings(string $tenantId, ?string $entityId): array
    {
        if ($entityId === null || $entityId === '') {
            return [];
        }

        $mappings = JournalTemplateMapping::query()
            ->where('tenant_id', $tenantId)
            ->where('accounting_entity_id', $entityId)
            ->get()
            ->keyBy('transaction_type');

        return collect(JournalTemplateMapping::transactionTypeLabels())
            ->map(function (string $label, string $type) use ($mappings) {
                $mapping = $mappings->get($type);

                if (! $mapping instanceof JournalTemplateMapping) {
                    return [
                        'transaction_type' => $type,
                        'label' => $label,
                        'description' => $this->transactionTypeDescription($type),
                        'journal_template' => null,
                        'is_required' => true,
                        'auto_queue_webhook' => true,
                        'is_active' => false,
                    ];
                }

                return $this->serializeMapping($mapping) + [
                    'label' => $label,
                    'description' => $this->transactionTypeDescription($type),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMapping(JournalTemplateMapping $mapping): array
    {
        return [
            'id' => $mapping->id,
            'tenant_id' => $mapping->tenant_id,
            'accounting_entity_id' => $mapping->accounting_entity_id,
            'transaction_type' => $mapping->transaction_type,
            'journal_template' => [
                'id' => $mapping->journal_template_id,
                'code' => $mapping->journal_template_code,
                'name' => $mapping->journal_template_name,
                'snapshot' => $mapping->journal_template_snapshot,
            ],
            'is_required' => $mapping->is_required,
            'auto_queue_webhook' => $mapping->auto_queue_webhook,
            'is_active' => $mapping->is_active,
        ];
    }

    private function documentTypeForMapping(string $transactionType): ?string
    {
        return match ($transactionType) {
            JournalTemplateMapping::TYPE_SALES_INVOICE => 'sales_invoice',
            JournalTemplateMapping::TYPE_PURCHASE_BILL => 'purchase_bill',
            default => null,
        };
    }

    private function transactionTypeDescription(string $transactionType): string
    {
        return match ($transactionType) {
            JournalTemplateMapping::TYPE_SALES_INVOICE => 'Jurnal otomatis saat invoice penjualan diterbitkan.',
            JournalTemplateMapping::TYPE_PURCHASE_BILL => 'Jurnal otomatis saat tagihan pembelian diterbitkan.',
            JournalTemplateMapping::TYPE_SALES_RETURN => 'Mapping untuk retur dan koreksi penjualan.',
            JournalTemplateMapping::TYPE_PURCHASE_RETURN => 'Mapping untuk retur dan koreksi pembelian.',
            JournalTemplateMapping::TYPE_CUSTOMER_PAYMENT => 'Mapping pelunasan/piutang pelanggan.',
            JournalTemplateMapping::TYPE_SUPPLIER_PAYMENT => 'Mapping pembayaran hutang ke pemasok.',
            default => 'Mapping jurnal operasional POSO.',
        };
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<int, string>  $columns
     */
    private function applySearch($query, Request $request, array $columns): void
    {
        $search = $request->query('search');
        if (! is_string($search) || $search === '') {
            return;
        }

        $query->where(function ($builder) use ($columns, $search) {
            foreach ($columns as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $builder->{$method}($column, 'like', '%'.$search.'%');
            }
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentTransactions(string $tenantId): array
    {
        $sales = SalesInvoice::query()
            ->with('customer')
            ->where('tenant_id', $tenantId)
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (SalesInvoice $invoice) => [
                'type' => 'Penjualan',
                'number' => $invoice->number,
                'party' => $invoice->customer?->name,
                'amount' => (float) $invoice->grand_total,
                'status' => $invoice->payment_status,
                'date' => optional($invoice->issued_at)->toDateString(),
            ]);

        $purchases = PurchaseBill::query()
            ->with('supplier')
            ->where('tenant_id', $tenantId)
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (PurchaseBill $bill) => [
                'type' => 'Pembelian',
                'number' => $bill->number,
                'party' => $bill->supplier?->name,
                'amount' => (float) $bill->grand_total,
                'status' => $bill->payment_status,
                'date' => optional($bill->issued_at)->toDateString(),
            ]);

        return $sales->merge($purchases)->take(8)->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tableRows(string $table, array $preferredColumns, int $limit): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $available = Schema::getColumnListing($table);
        $columns = array_values(array_intersect($preferredColumns, $available));

        if ($columns === []) {
            $columns = array_slice($available, 0, 5);
        }

        return DB::table($table)
            ->select($columns)
            ->latest(in_array('created_at', $available, true) ? 'created_at' : $columns[0])
            ->limit($limit)
            ->get()
            ->map(fn (object $row) => (array) $row)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProduct(Product $product): array
    {
        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'type' => $product->type,
            'unit' => $product->unit,
            'sales_price' => (float) $product->sales_price,
            'purchase_price' => (float) $product->purchase_price,
            'tax_rate' => (float) $product->tax_rate,
            'is_active' => $product->is_active,
        ];
    }
}
