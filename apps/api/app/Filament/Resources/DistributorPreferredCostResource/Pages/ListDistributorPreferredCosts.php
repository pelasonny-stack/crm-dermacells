<?php

declare(strict_types=1);

namespace App\Filament\Resources\DistributorPreferredCostResource\Pages;

use App\Filament\Resources\DistributorPreferredCostResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDistributorPreferredCosts extends ListRecords
{
    protected static string $resource = DistributorPreferredCostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
