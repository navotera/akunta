<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use Akunta\Rbac\Models\Entity;
use App\Http\Controllers\Api\Spa\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\DocumentationNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class DocumentationNoteController extends Controller
{
    use ResolvesTenant;

    public function index(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $notes = DocumentationNote::query()
            ->where('entity_id', $entity->id)
            ->whereNull('parent_id')
            ->with('children')
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();

        return response()->json([
            'data' => $notes->map(fn (DocumentationNote $note): array => $this->serialize($note))->all(),
            'meta' => ['can_manage' => $this->canManage($entity)],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $this->authorizeManagement($entity);
        $data = $this->validatedPayload($request);
        $parent = $this->resolveParent($entity, $data['parent_id'] ?? null);

        $sortOrder = ((int) DocumentationNote::query()
            ->where('entity_id', $entity->id)
            ->where('parent_id', $parent?->id)
            ->max('sort_order')) + 10;

        $note = DocumentationNote::create([
            'entity_id' => $entity->id,
            'parent_id' => $parent?->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'sort_order' => $sortOrder,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return response()->json(['data' => $this->serialize($note)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $this->authorizeManagement($entity);
        $note = DocumentationNote::query()->where('entity_id', $entity->id)->findOrFail($id);
        $data = $this->validatedPayload($request, false);

        $note->update([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'updated_by' => Auth::id(),
        ]);

        return response()->json(['data' => $this->serialize($note->fresh('children'))]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $this->authorizeManagement($entity);
        $note = DocumentationNote::query()->where('entity_id', $entity->id)->findOrFail($id);
        $note->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function validatedPayload(Request $request, bool $allowParent = true): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:10000'],
        ];

        if ($allowParent) {
            $rules['parent_id'] = ['nullable', 'string', 'size:26'];
        }

        return $request->validate($rules);
    }

    private function resolveParent(Entity $entity, ?string $parentId): ?DocumentationNote
    {
        if ($parentId === null) {
            return null;
        }

        $parent = DocumentationNote::query()
            ->where('entity_id', $entity->id)
            ->find($parentId);

        if (! $parent || $parent->parent_id !== null) {
            throw ValidationException::withMessages([
                'parent_id' => 'Submenu hanya dapat ditambahkan di bawah menu utama pada entitas aktif.',
            ]);
        }

        return $parent;
    }

    private function authorizeManagement(Entity $entity): void
    {
        abort_unless($this->canManage($entity), 403, 'Hanya admin yang dapat mengelola catatan panduan.');
    }

    private function canManage(Entity $entity): bool
    {
        $user = Auth::user();

        return $user !== null && (
            (method_exists($user, 'isSsoAdmin') && $user->isSsoAdmin())
            || session('ecopa.app_role') === 'admin'
            || $user->hasPermission('workspace.manage', $entity->id)
        );
    }

    /** @return array<string, mixed> */
    private function serialize(DocumentationNote $note): array
    {
        $contentTitle = $this->titleFromDescription($note->description);

        return [
            'id' => $note->id,
            'parent_id' => $note->parent_id,
            'title' => $contentTitle ?? $note->title,
            'description' => $note->description,
            'children' => $note->relationLoaded('children')
                ? $note->children->map(fn (DocumentationNote $child): array => $this->serialize($child))->all()
                : [],
            'updated_at' => $note->updated_at?->toIso8601String(),
        ];
    }

    private function titleFromDescription(?string $description): ?string
    {
        if ($description === null || ! preg_match('/<h1\b[^>]*>(.*?)<\/h1>/is', $description, $matches)) {
            return null;
        }

        $title = trim((string) preg_replace(
            '/\s+/u',
            ' ',
            strip_tags(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')),
        ));

        return $title !== '' ? mb_substr($title, 0, 160) : null;
    }
}
