<?php

declare(strict_types=1);

namespace App\Filament\Resources\ExchangeRateResource\Pages;

use App\Filament\Resources\ExchangeRateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListExchangeRates extends ListRecords
{
    protected static string $resource = ExchangeRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Ingresar TC manual'),
        ];
    }
}
