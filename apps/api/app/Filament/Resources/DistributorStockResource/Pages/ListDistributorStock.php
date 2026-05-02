<?php

declare(strict_types=1);

namespace App\Filament\Resources\DistributorStockResource\Pages;

use App\Filament\Resources\DistributorStockResource;
use Filament\Resources\Pages\ListRecords;

class ListDistributorStock extends ListRecords
{
    protected static string $resource = DistributorStockResource::class;
}
