<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\QueueAkuntaPostingEvent;
use App\Http\Controllers\Api\V1\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\JournalTemplateMapping;
use App\Models\SalesInvoice;
use App\Support\AkuntaReferences;
use App\Support\DocumentTotals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SalesInvoiceController extends Controller
{
    use ResolvesTenant;

    public function __construct(private readonly AkuntaReferences $akuntaReferences) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $perPage = min((int) $request->integer('per_page', 15), 50);

        $query = SalesInvoice::query()
            ->with('customer')
            ->where('tenant_id', $tenantId)
            ->latest('issued_at')
            ->latest('created_at');

        if ($status = $request->query('status')) {
            $query->where('payment_status', $status);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', '%'.$search.'%')
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', '%'.$search.'%'));
            });
        }

        $page = $query->paginate($perPage);

        return response()->json([
            'data' => $page->getCollection()->map(fn (SalesInvoice $invoice) => $this->serialize($invoice))->all(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function store(Request $request, QueueAkuntaPostingEvent $queueAkunta): JsonResponse
    {
        $data = $request->validate([
            'customer.name' => 'required|string|max:160',
            'customer.email' => 'nullable|email|max:160',
            'customer.phone' => 'nullable|string|max:60',
            'customer.address' => 'nullable|string|max:1000',
            'number' => 'nullable|string|max:80',
            'issued_at' => 'required|date_format:Y-m-d',
            'due_at' => 'nullable|date_format:Y-m-d|after_or_equal:issued_at',
            'status' => 'required|in:draft,published',
            'payment_status' => 'nullable|in:unpaid,partial,paid',
            'payment_terms' => 'nullable|string|max:80',
            'payment_method' => 'nullable|string|max:80',
            'notes' => 'nullable|string|max:1000',
            'terms' => 'nullable|string|max:1000',
            'source_channel' => 'nullable|string|max:80',
            'external_reference' => 'nullable|string|max:120',
            'journal_template_id' => 'nullable|string|max:80',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|string|max:26',
            'items.*.name' => 'required|string|max:180',
            'items.*.description' => 'nullable|string|max:400',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.unit' => 'nullable|string|max:30',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        $entity = $this->resolveEntity($request);
        $tenantId = (string) $entity->tenant_id;
        $accountingEntityId = (string) $entity->id;
        $data['journal_template_id'] ??= $this->defaultJournalTemplateId($tenantId, $accountingEntityId);
        $journalTemplate = $this->resolveJournalTemplate($data, $accountingEntityId);
        if ($journalTemplate instanceof JsonResponse) {
            return $journalTemplate;
        }

        $totals = DocumentTotals::calculate($data['items']);

        $invoice = DB::transaction(function () use ($data, $request, $tenantId, $accountingEntityId, $journalTemplate, $totals) {
            $customer = Customer::query()->firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'name' => $data['customer']['name'],
                ],
                [
                    'email' => $data['customer']['email'] ?? null,
                    'phone' => $data['customer']['phone'] ?? null,
                    'address' => $data['customer']['address'] ?? null,
                ]
            );

            $invoice = SalesInvoice::query()->create([
                'tenant_id' => $tenantId,
                'customer_id' => $customer->id,
                'number' => $data['number'] ?? $this->nextNumber($tenantId, 'INV', $data['issued_at']),
                'issued_at' => $data['issued_at'],
                'due_at' => $data['due_at'] ?? null,
                'status' => $data['status'],
                'payment_status' => $data['payment_status'] ?? 'unpaid',
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'grand_total' => $totals['grand_total'],
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? 'Net 14',
                'payment_method' => $data['payment_method'] ?? 'Transfer Bank',
                'source_channel' => $data['source_channel'] ?? 'poso-web',
                'external_reference' => $data['external_reference'] ?? null,
                'accounting_entity_id' => $accountingEntityId,
                'journal_template_id' => $journalTemplate['id'] ?? null,
                'journal_template_code' => $journalTemplate['code'] ?? null,
                'journal_template_name' => $journalTemplate['name'] ?? null,
                'journal_template_snapshot' => $journalTemplate,
                'published_at' => $data['status'] === SalesInvoice::STATUS_PUBLISHED ? Carbon::now() : null,
                'created_by' => $request->user()?->id,
            ]);

            $invoice->items()->createMany($totals['items']);

            return $invoice->load(['customer', 'items']);
        });

        $event = $invoice->status === SalesInvoice::STATUS_PUBLISHED
            ? $queueAkunta->forSalesInvoice($invoice)
            : null;

        return response()->json([
            'data' => $this->serialize($invoice),
            'meta' => [
                'akunta_event_id' => $event?->id,
                'akunta_event_status' => $event?->status,
            ],
        ], 201);
    }

    private function serialize(SalesInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'issued_at' => optional($invoice->issued_at)->toDateString(),
            'due_at' => optional($invoice->due_at)->toDateString(),
            'status' => $invoice->status,
            'payment_status' => $invoice->payment_status,
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
            'accounting' => [
                'entity_id' => $invoice->accounting_entity_id,
                'journal_template' => $invoice->journal_template_id ? [
                    'id' => $invoice->journal_template_id,
                    'code' => $invoice->journal_template_code,
                    'name' => $invoice->journal_template_name,
                ] : null,
            ],
        ];
    }

    private function resolveJournalTemplate(array $data, ?string $accountingEntityId): array|JsonResponse|null
    {
        $templateId = $data['journal_template_id'] ?? null;

        if (($data['status'] ?? null) === SalesInvoice::STATUS_PUBLISHED && ! $templateId) {
            return response()->json([
                'errors' => [[
                    'code' => 'journal_template_required',
                    'message' => 'Template jurnal Akunta wajib dipilih sebelum invoice diterbitkan.',
                ]],
            ], 422);
        }

        if (! $templateId) {
            return null;
        }

        $template = $this->akuntaReferences->findJournalTemplate($templateId, $accountingEntityId, 'sales_invoice');

        if ($template === null) {
            return response()->json([
                'errors' => [[
                    'code' => 'journal_template_not_found',
                    'message' => 'Template jurnal tidak ditemukan pada entitas Akunta aktif.',
                ]],
            ], 422);
        }

        return $template;
    }

    private function defaultJournalTemplateId(string $tenantId, ?string $accountingEntityId): ?string
    {
        if ($accountingEntityId === null || $accountingEntityId === '') {
            return null;
        }

        return JournalTemplateMapping::query()
            ->where('tenant_id', $tenantId)
            ->where('transaction_type', JournalTemplateMapping::TYPE_SALES_INVOICE)
            ->where('is_active', true)
            ->where('accounting_entity_id', $accountingEntityId)
            ->value('journal_template_id');
    }

    private function nextNumber(string $tenantId, string $prefix, string $date): string
    {
        $period = Carbon::parse($date)->format('Y/m');
        $count = SalesInvoice::query()
            ->where('tenant_id', $tenantId)
            ->where('number', 'like', $prefix.'/'.$period.'/%')
            ->count() + 1;

        return $prefix.'/'.$period.'/'.str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }
}
