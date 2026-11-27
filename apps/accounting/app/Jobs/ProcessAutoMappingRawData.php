<?php

declare(strict_types=1);

namespace App\Jobs;

use Akunta\Rbac\Support\TenantContext;
use App\Jobs\Middleware\RunInTenant;
use App\Models\AutoMappingRawData;
use App\Services\AutoMappingEngine;
use App\Support\Tenancy\TenantOwnership;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessAutoMappingRawData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Tenant the raw data belongs to, carried in the payload (see RunInTenant). */
    public ?string $tenantId;

    public function __construct(public string $rawId)
    {
        $this->tenantId = TenantContext::id() ?? TenantOwnership::parentTenant('auto_mapping_raw_data', $rawId);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new RunInTenant];
    }

    public function handle(AutoMappingEngine $engine): void
    {
        $raw = AutoMappingRawData::findOrFail($this->rawId);
        $engine->process($raw);
    }
}
