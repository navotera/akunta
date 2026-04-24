<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Aksi Cepat</x-slot>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($actions as $action)
                <x-filament::button
                    tag="a"
                    href="{{ $action['url'] }}"
                    color="{{ $action['color'] }}"
                    :icon="$action['icon']"
                    class="w-full justify-center"
                >
                    {{ $action['label'] }}
                </x-filament::button>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
