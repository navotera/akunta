<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use Akunta\Core\Contracts\AuditLogger as AuditLoggerContract;
use Akunta\Rbac\Models\Entity;
use App\Http\Controllers\Api\Spa\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CoaTemplate;
use App\Models\CoaTemplateAccount;
use App\Services\Onboarding\CoaTemplateRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Chart-of-Accounts templates owned by the organisation (stage 6). The Chart of
 * Accounts is the Organization Owner's domain: a template is created by copying
 * a built-in or own template, or by snapshotting a workspace's Chart of Accounts
 * (edited in the existing Bagan Akun editor), and is applied through the same
 * apply action as the built-ins. CoaTemplate is tenant-scoped, so another
 * organisation can neither see, edit, nor delete these. Every action requires
 * `settings.coa_template.manage` (EnforceSpaPermissions).
 */
class CoaTemplateController extends Controller
{
    use ResolvesTenant;

    public function __construct(
        private readonly CoaTemplateRegistry $registry,
        private readonly AuditLoggerContract $auditLogger,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => CoaTemplate::query()->withCount('accounts')->orderBy('name')->get()
                ->map(fn (CoaTemplate $template): array => $this->payload($template))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('coa_templates', 'name')->where('tenant_id', $entity->tenant_id)],
            'description' => ['nullable', 'string', 'max:500'],
            'source' => ['required', Rule::in(['entity', 'template'])],
            'template_key' => ['required_if:source,template', 'nullable', 'string', 'max:40'],
        ]);

        [$rows, $sourceType, $sourceLabel] = $data['source'] === 'entity'
            ? [$this->snapshotOf($entity), CoaTemplate::SOURCE_ENTITY, $entity->name]
            : $this->copyOf($data['template_key'], $entity);

        // A code can appear only once in a template. Applying a template keeps the first
        // occurrence of a code and skips later ones, so the copy does the same.
        $rows = array_values(collect($rows)->unique(fn (array $row): string => (string) $row[0])->all());
        if ($rows === []) {
            throw ValidationException::withMessages(['source' => 'Sumber tidak memiliki akun untuk dijadikan template.']);
        }

        $template = DB::transaction(function () use ($data, $entity, $rows, $sourceType, $sourceLabel, $request): CoaTemplate {
            $template = CoaTemplate::query()->create([
                'tenant_id' => $entity->tenant_id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'source_type' => $sourceType,
                'source_label' => $sourceLabel,
                'created_by' => $request->user()?->getAuthIdentifier(),
            ]);

            foreach (array_chunk($rows, 200, true) as $chunk) {
                CoaTemplateAccount::query()->insert(array_map(fn (array $row, int $index): array => [
                    'id' => (string) Str::ulid(),
                    'tenant_id' => $entity->tenant_id,
                    'template_id' => $template->id,
                    'code' => $row[0], 'name' => $row[1], 'type' => $row[2], 'normal_balance' => $row[3],
                    'parent_code' => $row[4] ?? null, 'is_postable' => (bool) $row[5],
                    'availability' => $row[6] ?? null, 'description' => $row[7] ?? null,
                    'sort_order' => $index,
                ], $chunk, array_keys($chunk)));
            }

            $this->auditLogger->record(
                action: 'coa_template.created',
                resourceType: CoaTemplate::class,
                resourceId: $template->id,
                entityId: $entity->id,
                metadata: ['name' => $template->name, 'source_type' => $sourceType, 'accounts' => count($rows)],
            );

            return $template;
        });

        return response()->json(['data' => $this->payload($template->loadCount('accounts'))], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $template = CoaTemplate::query()->findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120', Rule::unique('coa_templates', 'name')->where('tenant_id', $template->tenant_id)->ignore($template->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $template->forceFill($data)->save();
        $this->auditLogger->record(
            action: 'coa_template.updated',
            resourceType: CoaTemplate::class,
            resourceId: $template->id,
            entityId: $entity->id,
            metadata: ['name' => $template->name],
        );

        return response()->json(['data' => $this->payload($template->loadCount('accounts'))]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        // Scoped lookup: another organisation's template is simply "not found".
        $template = CoaTemplate::query()->findOrFail($id);

        DB::transaction(function () use ($template, $entity): void {
            $name = $template->name;
            CoaTemplateAccount::query()->where('template_id', $template->id)->delete();
            $template->delete();
            $this->auditLogger->record(
                action: 'coa_template.deleted',
                resourceType: CoaTemplate::class,
                resourceId: $template->id,
                entityId: $entity->id,
                metadata: ['name' => $name],
            );
        });

        return response()->json(null, 204);
    }

    /**
     * The workspace's Chart of Accounts in template-row form.
     *
     * @return list<array<int, mixed>>
     */
    private function snapshotOf(Entity $entity): array
    {
        $accounts = Account::query()->where('entity_id', $entity->id)->orderBy('code')->get();
        $codeById = $accounts->pluck('code', 'id');

        return $accounts->map(fn (Account $account): array => [
            $account->code, $account->name, $account->type, $account->normal_balance,
            $account->parent_account_id === null ? null : $codeById->get($account->parent_account_id),
            (bool) $account->is_postable, $account->availability, $account->description,
        ])->values()->all();
    }

    /**
     * Rows of a built-in or own template. The registry refuses a template of
     * another organisation (not found).
     *
     * @return array{0: list<array<int, mixed>>, 1: string, 2: string}
     */
    private function copyOf(string $key, Entity $entity): array
    {
        if (str_starts_with($key, CoaTemplateRegistry::ORGANIZATION_PREFIX)) {
            $source = CoaTemplate::query()->findOrFail(substr($key, strlen(CoaTemplateRegistry::ORGANIZATION_PREFIX)));

            return [$this->registry->load($key, $entity->tenant_id), CoaTemplate::SOURCE_ORGANIZATION, $source->name];
        }

        $builtIn = $this->registry->builtIn();
        if (! array_key_exists($key, $builtIn)) {
            throw ValidationException::withMessages(['template_key' => 'Template bawaan tidak dikenal.']);
        }

        return [$this->registry->load($key, $entity->tenant_id), CoaTemplate::SOURCE_BUILT_IN, $builtIn[$key]['label']];
    }

    /** @return array<string, mixed> */
    private function payload(CoaTemplate $template): array
    {
        return [
            'id' => $template->id,
            'key' => CoaTemplateRegistry::ORGANIZATION_PREFIX.$template->id,
            'name' => $template->name,
            'description' => $template->description,
            'source_type' => $template->source_type,
            'source_label' => $template->source_label,
            'accounts_count' => (int) ($template->accounts_count ?? 0),
            'created_at' => $template->created_at?->toIso8601String(),
        ];
    }
}
