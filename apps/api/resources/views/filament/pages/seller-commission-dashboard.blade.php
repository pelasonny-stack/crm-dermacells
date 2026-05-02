<x-filament-panels::page>

    {{-- Filter Form --}}
    <x-filament::section heading="Commission Calculator">
        <form wire:submit.prevent="calculate">
            {{ $this->form }}

            <x-filament::button type="submit" class="mt-4">
                Calculate
            </x-filament::button>
        </form>
    </x-filament::section>

    {{-- Results --}}
    @if($result !== null)
        @php $summary = $this->getSummary(); @endphp

        @if($result->is_director)
            <x-filament::section heading="Result">
                <x-filament::badge color="danger">
                    Directors do not earn commissions (§12.3).
                </x-filament::badge>
            </x-filament::section>
        @else
            {{-- Summary Panel --}}
            <x-filament::section :heading="'Commission Summary — ' . $selectedSellerName . ' (' . $selectedMonth . ')'">
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div class="rounded-lg border border-gray-200 p-4">
                        <p class="text-sm text-gray-500">Accumulated USD-equiv</p>
                        <p class="text-xl font-semibold">USD {{ $summary['accumulated_usd'] }}</p>
                    </div>
                    <div class="rounded-lg border border-gray-200 p-4">
                        <p class="text-sm text-gray-500">Tier Rate</p>
                        <p class="text-xl font-semibold">{{ $summary['tier_rate_pct'] }}</p>
                    </div>
                    <div class="rounded-lg border border-gray-200 p-4">
                        <p class="text-sm text-gray-500">Commission ARS</p>
                        <p class="text-xl font-semibold">ARS {{ $summary['commission_ars'] }}</p>
                    </div>
                    <div class="rounded-lg border border-gray-200 p-4">
                        <p class="text-sm text-gray-500">Commission USD</p>
                        <p class="text-xl font-semibold">USD {{ $summary['commission_usd'] }}</p>
                    </div>
                </div>
            </x-filament::section>

            {{-- Per-Zone Breakdown Table --}}
            @php $rows = $this->getBreakdownRows(); @endphp
            @if(count($rows) > 0)
                <x-filament::section heading="Breakdown by Zone">
                    <div class="overflow-x-auto">
                        <table class="w-full table-auto text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-left text-gray-500">
                                    <th class="py-2 pr-4">Zone</th>
                                    <th class="py-2 pr-4">Collected ARS</th>
                                    <th class="py-2 pr-4">Collected USD</th>
                                    <th class="py-2 pr-4">USD-equiv</th>
                                    <th class="py-2 pr-4">Commission ARS</th>
                                    <th class="py-2">Commission USD</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($rows as $row)
                                    <tr class="border-b border-gray-100">
                                        <td class="py-2 pr-4 font-medium">{{ $row['zone_name'] }}</td>
                                        <td class="py-2 pr-4">{{ $row['collected_ars'] }}</td>
                                        <td class="py-2 pr-4">{{ $row['collected_usd'] }}</td>
                                        <td class="py-2 pr-4">{{ $row['usd_equivalent'] }}</td>
                                        <td class="py-2 pr-4">{{ $row['commission_ars'] }}</td>
                                        <td class="py-2">{{ $row['commission_usd'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @else
                <x-filament::section heading="Breakdown by Zone">
                    <p class="text-gray-500">No payments found for this seller in the selected month.</p>
                </x-filament::section>
            @endif
        @endif
    @endif

</x-filament-panels::page>
