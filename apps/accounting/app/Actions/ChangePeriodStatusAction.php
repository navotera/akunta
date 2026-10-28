<?php

declare(strict_types=1);

namespace App\Actions;

use Akunta\Core\Actions\BaseAction;
use App\Models\Journal;
use App\Models\Period;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ChangePeriodStatusAction extends BaseAction
{
    public function execute(Period $period, string $status, ?string $reason = null): Period
    {
        $action = match ($status) {
            Period::STATUS_OPEN => 'reopen',
            Period::STATUS_INACTIVE => 'deactivate',
            Period::STATUS_CLOSED => 'close',
        };
        $this->authorize('accounting.permission', 'period.'.$action, $period->entity);

        $result = $this->runInTransaction(function () use ($period, $status, $reason, $action): Period {
            $locked = Period::where('entity_id', $period->entity_id)->lockForUpdate()->findOrFail($period->id);
            $previous = $locked->status;
            if ($previous === $status) {
                return $locked;
            }
            if ($status === Period::STATUS_INACTIVE && ! $locked->isOpen()) {
                throw ValidationException::withMessages(['status' => 'Hanya periode aktif yang dapat dinonaktifkan.']);
            }
            if ($status === Period::STATUS_CLOSED && Journal::where('entity_id', $locked->entity_id)
                ->where('period_id', $locked->id)->whereIn('status', [Journal::STATUS_DRAFT, Journal::STATUS_SUBMITTED, Journal::STATUS_REJECTED])->exists()) {
                throw ValidationException::withMessages(['period' => 'Selesaikan jurnal Diajukan, Di review, dan Perlu Revisi sebelum menutup periode. Gunakan Nonaktifkan untuk mengunci sementara.']);
            }
            $this->fireBefore('period.before_'.$action, $locked, Auth::user(), $reason);
            $locked->forceFill([
                'status' => $status,
                'closed_at' => $status === Period::STATUS_CLOSED ? now() : null,
                'closed_by' => $status === Period::STATUS_CLOSED ? Auth::id() : null,
            ])->save();
            $this->audit('period.'.$action, Period::class, $locked->id, $locked->entity_id, [
                'status_from' => $previous, 'status_to' => $status, 'reason' => $reason,
            ], Auth::id());

            return $locked;
        });
        $this->fireAfter('period.after_'.$action, $result, Auth::user(), $reason);

        return $result;
    }
}
