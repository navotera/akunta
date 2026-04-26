<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="flex items-center justify-end gap-3 mt-6">
            <x-filament::button type="submit" color="primary">
                Simpan
            </x-filament::button>
        </div>
    </form>

    <div class="mt-6 text-xs text-gray-500">
        Semua perubahan disimpan di Ecopa dan disinkronisasi ke aplikasi lain via webhook.
        Email tidak bisa diubah dari sini — hubungi admin Ecopa kalau perlu.
    </div>
</x-filament-panels::page>
