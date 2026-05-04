<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use App\Http\Controllers\Api\Spa\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
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

        return response()->json([
            'data' => $items->map(fn (Attachment $a) => $this->serialize($a))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);

        $data = $request->validate([
            'attachable_type' => 'required|string|in:'.Journal::class,
            'attachable_id' => 'required|string|size:26',
            'file' => 'required|file|max:5120', // KB
            'description' => 'nullable|string|max:255',
        ]);

        // Verify ownership of the parent record (only Journal supported in MVP).
        if ($data['attachable_type'] === Journal::class) {
            $exists = Journal::where('entity_id', $entity->id)
                ->where('id', $data['attachable_id'])
                ->exists();
            if (! $exists) {
                throw ValidationException::withMessages([
                    'attachable_id' => 'Parent journal not found in this tenant.',
                ]);
            }
        }

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
}
