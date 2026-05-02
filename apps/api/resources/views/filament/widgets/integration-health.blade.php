<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Estado Integraciones
        </x-slot>

        <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
            @foreach ($statuses as $name => $status)
                <div class="flex flex-col items-center gap-1 rounded-lg border p-3 text-center
                    {{ $status['ok'] === true  ? 'border-success-300 bg-success-50 dark:border-success-700 dark:bg-success-900/20' : '' }}
                    {{ $status['ok'] === false ? 'border-danger-300 bg-danger-50 dark:border-danger-700 dark:bg-danger-900/20' : '' }}
                    {{ $status['ok'] === null  ? 'border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800/20' : '' }}
                ">
                    @if ($status['ok'] === true)
                        <x-filament::icon icon="heroicon-m-check-circle" class="w-6 h-6 text-success-500" />
                    @elseif ($status['ok'] === false)
                        <x-filament::icon icon="heroicon-m-x-circle" class="w-6 h-6 text-danger-500" />
                    @else
                        <x-filament::icon icon="heroicon-m-question-mark-circle" class="w-6 h-6 text-gray-400" />
                    @endif

                    <span class="text-xs font-semibold text-gray-700 dark:text-gray-200">{{ $name }}</span>
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $status['label'] }}</span>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
