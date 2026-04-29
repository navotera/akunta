<x-filament-panels::page>
    <form wire:submit="save" class="space-y-4">
        {{ $this->form }}

        <div class="flex items-center gap-3 pt-2">
            <x-filament::button type="submit" icon="heroicon-o-check">
                Simpan &amp; Review
            </x-filament::button>
            <x-filament::button
                tag="a"
                color="gray"
                :href="\App\Filament\Pages\Dashboard::getUrl(tenant: \Filament\Facades\Filament::getTenant())"
            >
                Batal
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
