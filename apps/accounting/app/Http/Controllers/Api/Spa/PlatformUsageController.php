<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use Akunta\Rbac\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Services\PlatformAuditLogger;
use App\Services\PlatformUsageReport;
use Illuminate\Http\JsonResponse;

/**
 * Platform Owner usage overview. Route-level `platform.owner` is the
 * authorization boundary. Deliberately runs without a tenant context: the
 * report is installation-wide and limited to aggregates (see
 * PlatformUsageReport) - no transaction content is ever returned.
 */
class PlatformUsageController extends Controller
{
    public function __construct(
        private readonly PlatformUsageReport $report,
        private readonly PlatformAuditLogger $platformAudit,
    ) {}

    public function index(): JsonResponse
    {
        $this->platformAudit->record('platform.usage_viewed', 'platform_usage');

        // Installation-wide on purpose (aggregates only): the documented explicit
        // cross-tenant path, not a bypass of the Platform Owner's own organisation context.
        return response()->json(['data' => TenantContext::without(fn (): array => $this->report->report())]);
    }
}
