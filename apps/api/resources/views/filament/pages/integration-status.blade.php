<x-filament-panels::page>
    <div class="space-y-4">
        <div class="flex justify-end">
            <x-filament::button
                wire:click="refresh"
                color="gray"
                icon="heroicon-o-arrow-path"
            >
                Actualizar
            </x-filament::button>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
            @foreach ($integrations as $key => $integration)
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-filament::icon
                                :icon="$integration['icon']"
                                class="w-5 h-5"
                            />
                            {{ $integration['name'] }}
                        </div>
                    </x-slot>

                    <div class="space-y-2">
                        @if ($integration['healthy'] === true)
                            <x-filament::badge color="success" icon="heroicon-m-check-circle">
                                Operativo
                            </x-filament::badge>
                        @elseif ($integration['healthy'] === false)
                            <x-filament::badge color="danger" icon="heroicon-m-x-circle">
                                Con problemas
                            </x-filament::badge>
                        @else
                            <x-filament::badge color="gray" icon="heroicon-m-question-mark-circle">
                                Desconocido
                            </x-filament::badge>
                        @endif

                        @if ($integration['last_success'])
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Ultimo exito: {{ \Carbon\Carbon::parse($integration['last_success'])->diffForHumans() }}
                            </p>
                        @endif

                        <p class="text-sm text-gray-600 dark:text-gray-300">
                            {{ $integration['detail'] }}
                        </p>

                        @if (isset($integration['error_rate_pct']))
                            <p class="text-xs font-medium {{ $integration['error_rate_pct'] > 10 ? 'text-danger-600' : 'text-gray-500' }}">
                                Tasa de error 24h: {{ $integration['error_rate_pct'] }}%
                            </p>
                        @endif
                    </div>
                </x-filament::section>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
