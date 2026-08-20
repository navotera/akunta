<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use Akunta\Rbac\Models\Entity;
use App\Http\Controllers\Controller;
use App\Models\FiscalAdjustment;
use App\Models\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WorkspaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $items = Entity::query()->orderBy('name')->get();

        return response()->json(['data' => $items->map(fn (Entity $entity): array => $this->payload($entity))->values()]);
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
            'bookkeeping_mode' => ['sometimes', 'in:independent_books,internal_only'],
        ]);
        $data = $this->normalizeAddress($data);
        $data = $this->normalizeNumberFormats($data);
        $data['id'] = (string) Str::ulid();

        $workspace = Entity::query()->create($data);

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
            'bookkeeping_mode' => ['sometimes', 'in:independent_books,internal_only'],
        ]);
        $data = $this->normalizeAddress($data);
        $data = $this->normalizeNumberFormats($data);
        $workspace = Entity::query()->findOrFail($id);
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

        return response()->json(['data' => $this->payload($workspace->refresh())]);
    }

    public function logo(Request $request, string $id): JsonResponse
    {
        $this->authorizeAdmin($request);
        $request->validate(['logo' => ['required', 'image', 'max:5120']]);
        $workspace = Entity::query()->findOrFail($id);
        /** @var UploadedFile $file */
        $file = $request->file('logo');
        $path = $file->store('workspace-logos', 'public');
        $workspace->forceFill(['logo_path' => $path])->save();

        return response()->json(['data' => $this->payload($workspace->refresh())]);
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

    /** @return array<string, mixed> */
    private function payload(Entity $entity): array
    {
        return [
            'id' => $entity->id,
            'tenant_id' => $entity->tenant_id,
            'name' => $entity->name,
            'workspace_code' => $entity->workspace_code,
            'is_active' => $entity->is_active,
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
            'bookkeeping_mode' => data_get($entity->workspace_settings, 'bookkeeping_mode', 'independent_books'),
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
        foreach (['journal_number_format', 'transaction_number_format', 'bookkeeping_mode'] as $key) {
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
        if ($formats !== []) {
            $data['workspace_settings'] = $formats;
        }

        return $data;
    }
}
