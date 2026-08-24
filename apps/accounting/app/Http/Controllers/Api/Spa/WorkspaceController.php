<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use Akunta\Core\Contracts\AuditLogger as AuditLoggerContract;
use Akunta\Rbac\Models\Entity;
use App\Http\Controllers\Controller;
use App\Jobs\PurgeArchivedWorkspace;
use App\Models\FiscalAdjustment;
use App\Models\Journal;
use App\Services\RequiredAccountService;
use App\Services\WorkspaceActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WorkspaceController extends Controller
{
    public function __construct(
        private readonly RequiredAccountService $requiredAccounts,
        private readonly AuditLoggerContract $auditLogger,
        private readonly WorkspaceActivityService $workspaceActivity,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $items = Entity::query()
            ->where('tenant_id', $this->managedTenantId($request))
            ->orderBy('name')
            ->get();
        $lastActivities = $this->workspaceActivity->latestByEntity($items);

        return response()->json([
            'data' => $items
                ->map(fn (Entity $entity): array => $this->payload(
                    $entity,
                    $lastActivities->get($entity->id),
                ))
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'tenant_id' => ['required', 'string', 'size:26', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:255'],
            'workspace_code' => ['nullable', 'string', 'max:64'],
            'is_active' => ['sometimes', 'boolean'],
            'theme_color' => ['sometimes', 'string', 'max:32', 'regex:/^(blue|emerald|violet|orange|rose|cyan|indigo|teal|amber|#[0-9a-fA-F]{6})$/'],
            'logo_size' => ['sometimes', 'integer', 'min:24', 'max:256'],
            'legal_form' => ['nullable', 'string', 'max:16'],
            'npwp' => ['nullable', 'string', 'max:32'],
            'nib' => ['nullable', 'string', 'max:32'],
            'director_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'journal_number_format' => ['nullable', 'string', 'max:120', 'regex:/^(?=.*\{(?:numbering|incremented_number)\})[A-Za-z0-9._\/{}-]+$/'],
            'transaction_number_format' => ['nullable', 'string', 'max:120', 'regex:/^(?=.*\{(?:numbering|incremented_number)\})[A-Za-z0-9._\/{}-]+$/'],
            'journal_number_formats' => ['nullable', 'array'],
            'journal_number_formats.*' => ['string', 'max:120', 'regex:/^(?=.*\{(?:numbering|incremented_number)\})[A-Za-z0-9._\/{}-]+$/'],
            'journal_number_starts' => ['nullable', 'array'],
            'journal_number_starts.*' => ['integer', 'min:1', 'max:2147483647'],
            'transaction_number_start' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'bookkeeping_mode' => ['sometimes', 'in:independent_books,internal_only'],
            'date_format' => ['sometimes', 'in:DD MMM YYYY,DD/MM/YYYY,MM/DD/YYYY,YYYY-MM-DD,d F Y'],
            'issue_report_url' => ['nullable', 'url:http,https', 'max:2048'],
        ]);
        $data = $this->normalizeAddress($data);
        $data = $this->normalizeNumberFormats($data);
        abort_unless(
            hash_equals($this->managedTenantId($request), $data['tenant_id']),
            422,
            'Tenant workspace tidak sesuai dengan workspace aktif.',
        );
        $data['id'] = (string) Str::ulid();

        $workspace = DB::transaction(function () use ($data): Entity {
            $workspace = Entity::query()->create($data);
            $this->requiredAccounts->ensure($workspace);

            return $workspace;
        });

        return response()->json(['data' => $this->payload($workspace)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'workspace_code' => ['nullable', 'string', 'max:64'],
            'is_active' => ['sometimes', 'boolean'],
            'theme_color' => ['sometimes', 'string', 'max:32', 'regex:/^(blue|emerald|violet|orange|rose|cyan|indigo|teal|amber|#[0-9a-fA-F]{6})$/'],
            'logo_size' => ['sometimes', 'integer', 'min:24', 'max:256'],
            'legal_form' => ['nullable', 'string', 'max:16'],
            'npwp' => ['nullable', 'string', 'max:32'],
            'nib' => ['nullable', 'string', 'max:32'],
            'director_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'journal_number_format' => ['nullable', 'string', 'max:120', 'regex:/^(?=.*\{(?:numbering|incremented_number)\})[A-Za-z0-9._\/{}-]+$/'],
            'transaction_number_format' => ['nullable', 'string', 'max:120', 'regex:/^(?=.*\{(?:numbering|incremented_number)\})[A-Za-z0-9._\/{}-]+$/'],
            'journal_number_formats' => ['nullable', 'array'],
            'journal_number_formats.*' => ['string', 'max:120', 'regex:/^(?=.*\{(?:numbering|incremented_number)\})[A-Za-z0-9._\/{}-]+$/'],
            'journal_number_starts' => ['nullable', 'array'],
            'journal_number_starts.*' => ['integer', 'min:1', 'max:2147483647'],
            'transaction_number_start' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'bookkeeping_mode' => ['sometimes', 'in:independent_books,internal_only'],
            'date_format' => ['sometimes', 'in:DD MMM YYYY,DD/MM/YYYY,MM/DD/YYYY,YYYY-MM-DD,d F Y'],
            'issue_report_url' => ['nullable', 'url:http,https', 'max:2048'],
        ]);
        $data = $this->normalizeAddress($data);
        $data = $this->normalizeNumberFormats($data);
        $workspace = $this->findManagedWorkspace($request, $id);
        abort_if($workspace->archived_at !== null, 422, 'Restore workspace sebelum mengubahnya.');
        if (array_key_exists('is_active', $data) && $data['is_active'] === false) {
            $hasOtherActiveWorkspace = Entity::query()
                ->where('tenant_id', $workspace->tenant_id)
                ->whereKeyNot($workspace->id)
                ->where('is_active', true)
                ->exists();
            abort_unless($hasOtherActiveWorkspace, 422, 'Minimal satu workspace harus tetap aktif.');
        }
        if (($data['bookkeeping_mode'] ?? null) === 'internal_only') {
            $hasFiscalData = Journal::query()
                ->where('entity_id', $workspace->id)
                ->where('journal_mode', Journal::MODE_FISCAL)
                ->exists()
                || FiscalAdjustment::query()->where('entity_id', $workspace->id)->exists();
            if ($hasFiscalData) {
                abort(422, 'Mode Fiskal tidak dapat dinonaktifkan karena jurnal atau koreksi Fiskal sudah ada.');
            }
        }
        if (isset($data['workspace_settings'])) {
            $data['workspace_settings'] = array_merge(
                is_array($workspace->workspace_settings) ? $workspace->workspace_settings : [],
                $data['workspace_settings'],
            );
        }
        $workspace->fill($data)->save();
        $this->requiredAccounts->ensure($workspace->refresh());

        return response()->json(['data' => $this->payload($workspace->refresh())]);
    }

    public function logo(Request $request, string $id): JsonResponse
    {
        $this->authorizeAdmin($request);
        $request->validate(['logo' => ['required', 'image', 'max:5120']]);
        $workspace = $this->findManagedWorkspace($request, $id);
        abort_if($workspace->archived_at !== null, 422, 'Restore workspace sebelum mengubahnya.');
        /** @var UploadedFile $file */
        $file = $request->file('logo');
        $path = $file->store('workspace-logos', 'public');
        $workspace->forceFill(['logo_path' => $path])->save();

        return response()->json(['data' => $this->payload($workspace->refresh())]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'confirmation_name' => ['required', 'string', 'max:255'],
        ]);
        $workspace = $this->findManagedWorkspace($request, $id);

        abort_if($workspace->is_active, 422, 'Nonaktifkan workspace sebelum menghapusnya.');
        abort_if($workspace->archived_at !== null, 422, 'Workspace ini sudah diarsipkan.');
        abort_if($workspace->is_fake_data, 422, 'Workspace PT. Fake Data bawaan tidak dapat dihapus.');
        abort_unless(
            hash_equals($workspace->name, $data['confirmation_name']),
            422,
            'Nama workspace tidak sesuai.',
        );

        DB::transaction(function () use ($request, $workspace): void {
            $this->auditLogger->record(
                action: 'workspace.archive',
                resourceType: Entity::class,
                resourceId: $workspace->id,
                metadata: [
                    'name' => $workspace->name,
                    'tenant_id' => $workspace->tenant_id,
                    'deleted_by' => $request->user()?->id,
                ],
                entityId: $workspace->id,
            );
            $workspace->forceFill([
                'is_active' => false,
                'archived_at' => now(),
            ])->save();
        });

        return response()->json([
            'message' => 'Workspace berhasil diarsipkan.',
            'data' => $this->payload($workspace->refresh()),
        ]);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $this->authorizeAdmin($request);
        $workspace = $this->findManagedWorkspace($request, $id);
        abort_if($workspace->archived_at === null, 422, 'Workspace ini tidak sedang diarsipkan.');

        DB::transaction(function () use ($workspace): void {
            $this->auditLogger->record(
                action: 'workspace.restore',
                resourceType: Entity::class,
                resourceId: $workspace->id,
                entityId: $workspace->id,
                metadata: [
                    'name' => $workspace->name,
                    'tenant_id' => $workspace->tenant_id,
                ],
            );
            $workspace->forceFill([
                'is_active' => false,
                'archived_at' => null,
            ])->save();
        });

        return response()->json([
            'message' => 'Workspace berhasil di-restore dalam status nonaktif.',
            'data' => $this->payload($workspace->refresh()),
        ]);
    }

    public function purge(Request $request, string $id): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'confirmation_name' => ['required', 'string', 'max:255'],
        ]);
        $workspace = $this->findManagedWorkspace($request, $id);

        abort_if($workspace->archived_at === null, 422, 'Arsipkan workspace sebelum menghapusnya permanen.');
        abort_if($workspace->is_fake_data, 422, 'Workspace PT. Fake Data bawaan tidak dapat dihapus permanen.');
        abort_unless(
            hash_equals($workspace->name, $data['confirmation_name']),
            422,
            'Nama workspace tidak sesuai.',
        );

        $this->auditLogger->record(
            action: 'workspace.purge_requested',
            resourceType: Entity::class,
            resourceId: $workspace->id,
            entityId: $workspace->id,
            metadata: [
                'name' => $workspace->name,
                'tenant_id' => $workspace->tenant_id,
                'archived_at' => $workspace->archived_at?->toIso8601String(),
            ],
        );

        PurgeArchivedWorkspace::dispatch($workspace->id, ignoreRetention: true);

        return response()->json([
            'message' => 'Penghapusan permanen workspace telah masuk antrean background.',
        ], 202);
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        abort_unless(
            $user !== null && (
                (method_exists($user, 'isSsoAdmin') && $user->isSsoAdmin())
                || $user->hasPermission('workspace.manage')
            ),
            403,
            'Workspace management requires admin permission.',
        );
    }

    private function managedTenantId(Request $request): string
    {
        $selectedEntityId = trim((string) $request->header('X-Tenant-Slug'));
        abort_if($selectedEntityId === '', 400, 'Workspace aktif belum dipilih.');

        return (string) Entity::query()->findOrFail($selectedEntityId)->tenant_id;
    }

    private function findManagedWorkspace(Request $request, string $id): Entity
    {
        return Entity::query()
            ->where('tenant_id', $this->managedTenantId($request))
            ->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function payload(Entity $entity, mixed $auditedActivity = null): array
    {
        return [
            'id' => $entity->id,
            'tenant_id' => $entity->tenant_id,
            'name' => $entity->name,
            'workspace_code' => $entity->workspace_code,
            'is_active' => $entity->is_active,
            'archived_at' => $entity->archived_at?->toIso8601String(),
            'scheduled_deletion_at' => $entity->archived_at?->copy()->addYear()->toIso8601String(),
            'is_fake_data' => (bool) $entity->is_fake_data,
            'demo_dataset_version' => $entity->is_fake_data
                ? data_get($entity->workspace_settings, 'native_fake_data_version', 'legacy')
                : null,
            'theme_color' => $entity->theme_color,
            'logo_url' => $entity->logo_path ? Storage::disk('public')->url($entity->logo_path) : null,
            'logo_size' => (int) data_get($entity->workspace_settings, 'logo_size', 96),
            'legal_form' => $entity->legal_form,
            'npwp' => $entity->npwp,
            'nib' => $entity->nib,
            'director_name' => $entity->director_name,
            'phone' => $entity->phone,
            'email' => $entity->email,
            'address' => is_array($entity->address) ? ($entity->address['text'] ?? '') : (string) ($entity->address ?? ''),
            'journal_number_format' => data_get($entity->workspace_settings, 'journal_number_format'),
            'transaction_number_format' => data_get($entity->workspace_settings, 'transaction_number_format'),
            'journal_number_formats' => data_get($entity->workspace_settings, 'journal_number_formats', []),
            'journal_number_starts' => data_get($entity->workspace_settings, 'journal_number_starts', []),
            'transaction_number_start' => (int) data_get($entity->workspace_settings, 'transaction_number_start', 1),
            'bookkeeping_mode' => data_get($entity->workspace_settings, 'bookkeeping_mode', 'independent_books'),
            'date_format' => data_get($entity->workspace_settings, 'date_format', 'DD MMM YYYY'),
            'issue_report_url' => data_get($entity->workspace_settings, 'issue_report_url'),
            'last_activity_at' => is_string($auditedActivity)
                ? $auditedActivity
                : $entity->updated_at?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $data */
    private function normalizeAddress(array $data): array
    {
        if (array_key_exists('address', $data)) {
            $data['address'] = $data['address'] === null ? null : ['text' => $data['address']];
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function normalizeNumberFormats(array $data): array
    {
        $formats = is_array($data['workspace_settings'] ?? null) ? $data['workspace_settings'] : [];
        foreach (['journal_number_format', 'transaction_number_format', 'transaction_number_start', 'bookkeeping_mode', 'date_format', 'issue_report_url'] as $key) {
            if (array_key_exists($key, $data)) {
                $formats[$key] = $data[$key] ?: null;
                unset($data[$key]);
            }
        }
        if (array_key_exists('logo_size', $data)) {
            $formats['logo_size'] = (int) $data['logo_size'];
            unset($data['logo_size']);
        }
        if (isset($data['journal_number_formats'])) {
            $formats['journal_number_formats'] = array_merge(
                is_array($formats['journal_number_formats'] ?? null) ? $formats['journal_number_formats'] : [],
                $data['journal_number_formats'],
            );
            unset($data['journal_number_formats']);
        }
        if (isset($data['journal_number_starts'])) {
            $formats['journal_number_starts'] = array_merge(
                is_array($formats['journal_number_starts'] ?? null) ? $formats['journal_number_starts'] : [],
                $data['journal_number_starts'],
            );
            unset($data['journal_number_starts']);
        }
        if ($formats !== []) {
            $data['workspace_settings'] = $formats;
        }

        return $data;
    }
}
