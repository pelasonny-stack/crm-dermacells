<?php

declare(strict_types=1);

namespace App\Filament\Resources\DistributorStockResource\Pages;

use App\Filament\Resources\DistributorStockResource;
use Filament\Resources\Pages\EditRecord;

class EditDistributorStock extends EditRecord
{
    protected static string $resource = DistributorStockResource::class;
}
