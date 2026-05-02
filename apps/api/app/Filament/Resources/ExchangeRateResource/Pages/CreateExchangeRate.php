<?php

declare(strict_types=1);

namespace App\Filament\Resources\ExchangeRateResource\Pages;

use App\Filament\Resources\ExchangeRateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateExchangeRate extends CreateRecord
{
    protected static string $resource = ExchangeRateResource::class;
}
