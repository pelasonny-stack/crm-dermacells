<?php

declare(strict_types=1);

namespace App\Filament\Resources\CentralStockResource\Pages;

use App\Filament\Resources\CentralStockResource;
use Filament\Resources\Pages\ListRecords;

class ListCentralStock extends ListRecords
{
    protected static string $resource = CentralStockResource::class;
}
