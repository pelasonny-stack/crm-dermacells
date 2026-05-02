<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseEvolutionMetricResource\Pages;

use App\Filament\Resources\PurchaseEvolutionMetricResource;
use Filament\Resources\Pages\ListRecords;

class ListPurchaseEvolutionMetrics extends ListRecords
{
    protected static string $resource = PurchaseEvolutionMetricResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
