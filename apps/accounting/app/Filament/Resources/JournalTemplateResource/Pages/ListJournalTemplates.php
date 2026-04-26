<?php

namespace App\Filament\Resources\JournalTemplateResource\Pages;

use App\Actions\SeedSampleJournalTemplatesAction;
use App\Filament\Resources\JournalTemplateResource;
use App\Models\Account;
use App\Models\JournalTemplate;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;

class ListJournalTemplates extends ListRecords
{
    protected static string $resource = JournalTemplateResource::class;

    public function mount(): void
    {
        parent::mount();
        $this->autoSeedSamples();
    }

    /**
     * One-shot auto-seed: kalau entity belum punya template apa pun DAN CoA
     * sudah diterapkan, isi 5 contoh template otomatis. No-op kalau sudah
     * ada template (artinya user sudah nge-explore — jangan ganggu).
     */
    protected function autoSeedSamples(): void
    {
        $entity = Filament::getTenant();
        if ($entity === null) {
            return;
        }

        if (JournalTemplate::where('entity_id', $entity->id)->exists()) {
            return;
        }

        if (! Account::where('entity_id', $entity->id)->exists()) {
            // CoA belum ada — sample template butuh akun, skip dulu
            return;
        }

        try {
            app(SeedSampleJournalTemplatesAction::class)->execute($entity->id, auth()->id());
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Auto-seed sample templates failed', [
                'entity_id' => $entity->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
