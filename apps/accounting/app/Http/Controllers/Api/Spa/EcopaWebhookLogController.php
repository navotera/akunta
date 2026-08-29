<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use Akunta\Rbac\Models\Entity;
use App\Http\Controllers\Controller;
use App\Models\EcopaWebhookLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EcopaWebhookLogController extends Controller
{
    private const OUTCOMES = [
        'processed',
        'already_processed',
        'retryable',
        'rejected',
        'unauthorized',
        'error',
    ];

    private const EVENTS = [
        ['event' => 'app.registration.approved', 'purpose' => 'Aktifkan integrasi dan credential SSO Akunta.'],
        ['event' => 'app.registration.rejected', 'purpose' => 'Catat penolakan registrasi agar wizard dapat diulang.'],
        ['event' => 'user.assigned', 'purpose' => 'Buat/aktifkan shadow user dengan role Akunta yang masih kosong.'],
        ['event' => 'user.updated', 'purpose' => 'Perbarui nama dan email shadow user lokal.'],
        ['event' => 'user.revoked', 'purpose' => 'Cabut assignment, sesi, dan token user dari Akunta.'],
        ['event' => 'user.deleted', 'purpose' => 'Nonaktifkan user tanpa menghapus histori akuntansi.'],
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $filters = $request->validate([
            'event' => ['nullable', 'string', 'max:80'],
            'outcome' => ['nullable', Rule::in(self::OUTCOMES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $logs = EcopaWebhookLog::query()
            ->when($filters['event'] ?? null, fn ($query, string $event) => $query->where('event', $event))
            ->when($filters['outcome'] ?? null, fn ($query, string $outcome) => $query->where('outcome', $outcome))
            ->latest('received_at')
            ->paginate((int) ($filters['per_page'] ?? 50));

        return response()->json([
            'data' => collect($logs->items())->map(fn (EcopaWebhookLog $log): array => [
                'id' => $log->id,
                'event_id' => $log->event_id,
                'event' => $log->event,
                'subject_reference' => $log->subject_reference,
                'outcome' => $log->outcome,
                'result_code' => $log->result_code,
                'http_status' => $log->http_status,
                'signature_valid' => $log->signature_valid,
                'retryable' => $log->retryable,
                'message' => $log->message,
                'duration_ms' => $log->duration_ms,
                'received_at' => $log->received_at?->toIso8601String(),
                'completed_at' => $log->completed_at?->toIso8601String(),
            ])->all(),
            'events' => self::EVENTS,
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'retention_months' => 12,
            ],
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        /** @var User|null $user */
        $user = $request->user();
        $entityId = trim((string) $request->header('X-Tenant-Slug'));
        $entity = $entityId === '' ? null : Entity::query()->find($entityId);

        abort_unless(
            $user && ($user->isSsoAdmin() || ($entity && $user->hasPermission('workspace.manage', $entity->id))),
            403,
            'Hanya Admin Aplikasi Akunta yang dapat melihat log webhook Ecopa.',
        );
    }
}
