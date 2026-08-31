<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $completedKey = 'installation_onboarding_completed_at';

        if (DB::table('ecopa_config_integration')->where('name', $completedKey)->exists()) {
            return;
        }

        $isComplete = DB::table('entities')
            ->where('is_fake_data', false)
            ->whereNotNull('workspace_settings')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('accounts')
                    ->whereColumn('accounts.entity_id', 'entities.id')
                    ->whereNull('accounts.system_key');
            })
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('periods')
                    ->whereColumn('periods.entity_id', 'entities.id');
            })
            ->get(['workspace_settings'])
            ->contains(function (object $entity): bool {
                $settings = json_decode((string) $entity->workspace_settings, true);

                return is_array($settings) && array_key_exists('bookkeeping_mode', $settings);
            });

        if ($isComplete) {
            DB::table('ecopa_config_integration')->insert([
                'name' => $completedKey,
                'value' => now()->toIso8601String(),
            ]);
        }
    }

    public function down(): void
    {
        // The marker may have been written by a completed onboarding after this migration ran.
    }
};
