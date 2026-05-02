<x-filament-panels::page>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Filter form                                                         --}}
    {{-- ------------------------------------------------------------------ --}}
    <x-filament::section heading="Filtros">
        <form wire:submit.prevent="fetchSuggestions">
            {{ $this->form }}

            <div class="mt-4">
                <x-filament::button
                    type="submit"
                    icon="heroicon-o-sparkles"
                    :disabled="$loading"
                >
                    @if ($loading)
                        Consultando IA&hellip;
                    @else
                        Obtener sugerencias
                    @endif
                </x-filament::button>
            </div>
        </form>
    </x-filament::section>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Results table                                                       --}}
    {{-- ------------------------------------------------------------------ --}}
    @if (count($suggestions) > 0)
        <x-filament::section heading="Sugerencias de reasignación ({{ count($suggestions) }})">

            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-4 py-3 font-semibold text-gray-600 dark:text-gray-300">
                                Cliente
                            </th>
                            <th class="px-4 py-3 font-semibold text-gray-600 dark:text-gray-300">
                                Vendedor actual
                            </th>
                            <th class="px-4 py-3 font-semibold text-gray-600 dark:text-gray-300">
                                Vendedor sugerido
                            </th>
                            <th class="px-4 py-3 font-semibold text-gray-600 dark:text-gray-300">
                                Motivo
                            </th>
                            <th class="px-4 py-3 font-semibold text-gray-600 dark:text-gray-300 text-center">
                                Confianza
                            </th>
                            <th class="px-4 py-3 font-semibold text-gray-600 dark:text-gray-300 text-center">
                                Accion
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($suggestions as $index => $suggestion)
                            @php
                                $confidence   = (float) ($suggestion['confidence'] ?? 0);
                                $confidencePct = number_format($confidence * 100, 0) . '%';
                                $confidenceColor = match (true) {
                                    $confidence >= 0.75 => 'text-success-600 dark:text-success-400',
                                    $confidence >= 0.50 => 'text-warning-600 dark:text-warning-400',
                                    default             => 'text-danger-600 dark:text-danger-400',
                                };

                                // Resolve display names from loaded customer/user data when available,
                                // falling back to the UUID short-form for traceability.
                                $customerId         = $suggestion['customer_id'] ?? '';
                                $currentSellerId    = $suggestion['current_seller_id'] ?? '';
                                $suggestedSellerId  = $suggestion['suggested_seller_id'] ?? '';

                                $customerLabel      = $suggestion['customer_name']        ?? substr($customerId, 0, 8) . '...';
                                $currentLabel       = $suggestion['current_seller_name']  ?? substr($currentSellerId, 0, 8) . '...';
                                $suggestedLabel     = $suggestion['suggested_seller_name'] ?? substr($suggestedSellerId, 0, 8) . '...';
                            @endphp
                            <tr class="bg-white dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-900 dark:text-gray-100">
                                        {{ $customerLabel }}
                                    </div>
                                    <div class="text-xs text-gray-400 font-mono">{{ $customerId }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-gray-700 dark:text-gray-300">{{ $currentLabel }}</div>
                                    <div class="text-xs text-gray-400 font-mono">{{ $currentSellerId }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-gray-700 dark:text-gray-300 font-medium">{{ $suggestedLabel }}</div>
                                    <div class="text-xs text-gray-400 font-mono">{{ $suggestedSellerId }}</div>
                                </td>
                                <td class="px-4 py-3 max-w-xs">
                                    <p class="text-gray-700 dark:text-gray-300 text-xs leading-relaxed">
                                        {{ $suggestion['reason'] ?? '' }}
                                    </p>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="font-bold {{ $confidenceColor }}">
                                        {{ $confidencePct }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <x-filament::button
                                        size="sm"
                                        color="success"
                                        wire:click="applyReassignment({{ $index }})"
                                        wire:confirm="Confirmar reasignacion: asignar este cliente al vendedor sugerido. Esta accion queda registrada en el log de auditoria."
                                    >
                                        Aplicar
                                    </x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

        </x-filament::section>
    @elseif (! $loading)
        <x-filament::section>
            <p class="text-center text-gray-500 dark:text-gray-400 py-8">
                Selecciona los filtros y pulsá "Obtener sugerencias" para que la IA analice el rendimiento de los vendedores.
            </p>
        </x-filament::section>
    @endif

    {{-- Wire loading overlay --}}
    <div wire:loading class="fixed inset-0 z-50 flex items-center justify-center bg-black/30">
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-xl px-8 py-6 flex flex-col items-center gap-3">
            <x-filament::loading-indicator class="h-8 w-8 text-primary-500" />
            <p class="text-sm text-gray-600 dark:text-gray-300">Consultando la IA&hellip;</p>
        </div>
    </div>

</x-filament-panels::page>
