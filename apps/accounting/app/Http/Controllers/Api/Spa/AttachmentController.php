<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use Akunta\Core\Contracts\AuditLogger as AuditLoggerContract;
use App\Http\Controllers\Api\Spa\Concerns\ProtectsNativeFakeData;
use App\Http\Controllers\Api\Spa\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\FiscalAdjustment;
use App\Models\Journal;
use App\Services\AttachmentImageProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AttachmentController extends Controller
{
    use ProtectsNativeFakeData;
    use ResolvesTenant;

    public const MAX_UPLOAD_BYTES = 5 * 1024 * 1024; // 5 MB

    public function __construct(
        private readonly AuditLoggerContract $auditLogger,
        private readonly AttachmentImageProcessor $imageProcessor,
    ) {}

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
        if ($data['attachable_type'] === Journal::class) {
            $journal = Journal::query()->where('entity_id', $entity->id)->findOrFail($data['attachable_id']);
            $this->assertNativeFakeRecordedJournalMutable($entity, $journal);
        }

        $file = $request->file('file');
        $disk = config('filesystems.default');
        $directory = "attachments/{$entity->id}/".date('Y/m');
        $storedPaths = [];

        try {
            $processed = $this->imageProcessor->process($file);
            if ($processed !== null) {
                $basename = Str::random(40);
                $path = "{$directory}/{$basename}.{$processed['extension']}";
                $thumbnailPath = "{$directory}/thumbnails/{$basename}.{$processed['extension']}";
                $this->storeContent($disk, $path, $processed['content']);
                $storedPaths[] = $path;
                $this->storeContent($disk, $thumbnailPath, $processed['thumbnail_content']);
                $storedPaths[] = $thumbnailPath;

                $originalFilename = $file->getClientOriginalName() ?: 'image';
                $filename = pathinfo($originalFilename, PATHINFO_FILENAME).'.'.$processed['extension'];
                $mimeType = $processed['mime_type'];
                $size = strlen($processed['content']);
                $checksum = hash('sha256', $processed['content']);
                $metadata = array_merge($processed['metadata'], [
                    'original_filename' => $originalFilename,
                    'thumbnail' => array_merge($processed['metadata']['thumbnail'], [
                        'path' => $thumbnailPath,
                    ]),
                ]);
            } else {
                $path = Storage::disk($disk)->putFile($directory, $file);
                if (! is_string($path)) {
                    throw new RuntimeException('Lampiran gagal disimpan.');
                }
                $storedPaths[] = $path;
                $filename = $file->getClientOriginalName() ?: basename($path);
                $mimeType = $file->getClientMimeType();
                $size = (int) $file->getSize();
                $checksum = hash_file('sha256', $file->getRealPath()) ?: null;
                $metadata = null;
            }

            $attachment = Attachment::create([
                'attachable_type' => $data['attachable_type'],
                'attachable_id' => $data['attachable_id'],
                'entity_id' => $entity->id,
                'filename' => $filename,
                'mime_type' => $mimeType,
                'size_bytes' => $size,
                'disk' => $disk,
                'path' => $path,
                'checksum_sha256' => $checksum,
                'description' => $data['description'] ?? null,
                'uploaded_by' => Auth::id(),
                'metadata' => $metadata,
            ]);
        } catch (Throwable $exception) {
            if ($storedPaths !== []) {
                Storage::disk($disk)->delete($storedPaths);
            }

            throw $exception;
        }

        if ($attachment->attachable_type === Journal::class) {
            $this->auditAttachment($attachment->attachable_id, $entity->id, 'Lampiran changed/deleted');
        }

        return response()->json(['data' => $this->serialize($attachment)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $attachment = Attachment::where('entity_id', $entity->id)->findOrFail($id);
        $this->authorizeParent($request, $entity->id, $attachment->attachable_type, $attachment->attachable_id, false);

        return response()->json([
            'data' => array_merge($this->serialize($attachment), [
                'url' => $this->temporaryUrl($attachment->disk, $attachment->path),
            ]),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        $attachment = Attachment::where('entity_id', $entity->id)->findOrFail($id);
        $this->authorizeParent($request, $entity->id, $attachment->attachable_type, $attachment->attachable_id, true);
        if ($attachment->attachable_type === Journal::class) {
            $journal = Journal::query()->where('entity_id', $entity->id)->findOrFail($attachment->attachable_id);
            $this->assertNativeFakeRecordedJournalMutable($entity, $journal);
        }

        $attachment->delete();

        if ($attachment->attachable_type === Journal::class) {
            $this->auditAttachment($attachment->attachable_id, $entity->id, 'Lampiran changed/deleted');
        }

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
            'thumbnail_url' => $this->temporaryUrl(
                $a->disk,
                (string) data_get($a->metadata, 'thumbnail.path'),
            ),
            'created_at' => optional($a->created_at)?->toIso8601String(),
            'uploaded_by' => $a->uploaded_by,
            'deleted_at' => optional($a->deleted_at)?->toIso8601String(),
        ];
    }

    private function storeContent(string $disk, string $path, string $content): void
    {
        if (! Storage::disk($disk)->put($path, $content)) {
            throw new RuntimeException('Lampiran gambar gagal disimpan.');
        }
    }

    private function temporaryUrl(string $disk, string $path): ?string
    {
        if ($path === '' || ! Storage::disk($disk)->exists($path)) {
            return null;
        }

        return Storage::disk($disk)->temporaryUrl($path, now()->addMinutes(5));
    }

    private function auditAttachment(string $journalId, string $entityId, string $change): void
    {
        $journal = Journal::with('entries.account')->where('entity_id', $entityId)->find($journalId);
        if (! $journal) {
            return;
        }

        $this->auditLogger->record('journal.attachment_changed', Journal::class, $journal->id, $entityId, [
            'snapshot' => [
                'id' => $journal->id, 'number' => $journal->number, 'transaction_code' => $journal->transaction_code,
                'journal_mode' => $journal->journal_mode, 'date' => optional($journal->date)?->toDateString(),
                'type' => $journal->type, 'memo' => $journal->memo, 'reference' => $journal->reference,
                'period_id' => $journal->period_id,
                'entries_debit' => $journal->entries->filter(fn ($e) => (float) $e->debit > 0)->map(fn ($e) => [
                    'account_id' => $e->account_id, 'amount' => (string) $e->debit, 'memo' => $e->memo,
                ])->values()->all(),
                'entries_credit' => $journal->entries->filter(fn ($e) => (float) $e->credit > 0)->map(fn ($e) => [
                    'account_id' => $e->account_id, 'amount' => (string) $e->credit, 'memo' => $e->memo,
                ])->values()->all(),
            ],
            'attachment_change' => $change,
        ], Auth::id());
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
