<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AutoMappingRawData;
use App\Services\AutoMappingEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessAutoMappingRawData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public string $rawId) {}

    public function handle(AutoMappingEngine $engine): void
    {
        $raw = AutoMappingRawData::findOrFail($this->rawId);
        $engine->process($raw);
    }
}
