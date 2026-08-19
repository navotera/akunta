<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use App\Http\Controllers\Api\Spa\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\FiscalAdjustment;
use App\Models\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AttachmentController extends Controller
{
    use ResolvesTenant;

    public const MAX_UPLOAD_BYTES = 5 * 1024 * 1024; // 5 MB

    public function index(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $data = $request->validate([
            'attachable_type' => 'required|string|max:120',
            'attachable_id' => 'required|string|size:26',
        ]);

        $items = Attachment::where('entity_id', $entity->id)
            ->where('attachable_type', $data['attachable_type'])
            ->where('attachable_id', $data['attachable_id'])
            ->orderByDesc('created_at')
            ->get();

        $this->authorizeParent($request, $entity->id, $data['attachable_type'], $data['attachable_id'], false);

        return response()->json([
            'data' => $items->map(fn (Attachment $a) => $this->serialize($a))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);

        $data = $request->validate([
            'attachable_type' => 'required|string|in:'.Journal::class.','.FiscalAdjustment::class,
            'attachable_id' => 'required|string|size:26',
            'file' => 'required|file|max:5120', // KB
            'description' => 'nullable|string|max:255',
        ]);

        $this->authorizeParent($request, $entity->id, $data['attachable_type'], $data['attachable_id'], true);

        $file = $request->file('file');
        $disk = config('filesystems.default');
        $directory = "attachments/{$entity->id}/".date('Y/m');

        $path = Storage::disk($disk)->putFile($directory, $file);

        $checksum = hash_file('sha256', $file->getRealPath()) ?: null;

        $attachment = Attachment::create([
            'attachable_type' => $data['attachable_type'],
            'attachable_id' => $data['attachable_id'],
            'entity_id' => $entity->id,
            'filename' => $file->getClientOriginalName() ?: basename($path),
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'disk' => $disk,
            'path' => $path,
            'checksum_sha256' => $checksum,
            'description' => $data['description'] ?? null,
            'uploaded_by' => Auth::id(),
        ]);

        return response()->json(['data' => $this->serialize($attachment)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $attachment = Attachment::where('entity_id', $entity->id)->findOrFail($id);
        $this->authorizeParent($request, $entity->id, $attachment->attachable_type, $attachment->attachable_id, false);

        return response()->json([
            'data' => array_merge($this->serialize($attachment), [
                'url' => Storage::disk($attachment->disk)->temporaryUrl(
                    $attachment->path,
                    now()->addMinutes(5),
                ),
            ]),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $attachment = Attachment::where('entity_id', $entity->id)->findOrFail($id);
        $this->authorizeParent($request, $entity->id, $attachment->attachable_type, $attachment->attachable_id, true);

        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();

        return response()->json(null, 204);
    }

    private function serialize(Attachment $a): array
    {
        return [
            'id' => $a->id,
            'attachable_type' => $a->attachable_type,
            'attachable_id' => $a->attachable_id,
            'filename' => $a->filename,
            'mime_type' => $a->mime_type,
            'size_bytes' => (int) $a->size_bytes,
            'description' => $a->description,
            'created_at' => optional($a->created_at)?->toIso8601String(),
            'uploaded_by' => $a->uploaded_by,
        ];
    }

    private function authorizeParent(Request $request, string $entityId, string $type, string $id, bool $write): void
    {
        $user = $request->user();
        if ($type === Journal::class) {
            $journal = Journal::query()->where('entity_id', $entityId)->find($id);
            if (! $journal) {
                throw ValidationException::withMessages(['attachable_id' => 'Parent journal not found in this tenant.']);
            }
            $isInspector = $user?->assignments()->whereNull('revoked_at')
                ->whereHas('role', fn ($query) => $query->where('code', 'inspector'))->exists() ?? false;
            abort_if($isInspector && $journal->journal_mode !== Journal::MODE_FISCAL, 403);
            abort_unless($user?->hasPermission($write ? 'journal.update' : 'journal.read', $entityId), 403);

            return;
        }

        if ($type === FiscalAdjustment::class) {
            $adjustment = FiscalAdjustment::query()->where('entity_id', $entityId)->find($id);
            if (! $adjustment) {
                throw ValidationException::withMessages(['attachable_id' => 'Koreksi Fiskal tidak ditemukan pada entitas ini.']);
            }
            abort_unless(
                $user?->hasPermission($write ? 'fiscal.adjustment.manage' : 'fiscal.adjustment.read', $entityId),
                403,
            );
            if ($write && $adjustment->status !== FiscalAdjustment::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => 'Bukti koreksi yang sudah disetujui tidak dapat diubah.',
                ]);
            }

            return;
        }

        abort(422, 'Unsupported attachment parent.');
    }
}
