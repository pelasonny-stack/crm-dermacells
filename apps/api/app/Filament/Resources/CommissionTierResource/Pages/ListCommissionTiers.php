<?php

declare(strict_types=1);

namespace App\Filament\Resources\CommissionTierResource\Pages;

use App\Filament\Resources\CommissionTierResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCommissionTiers extends ListRecords
{
    protected static string $resource = CommissionTierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Agregar tier'),
        ];
    }
}
