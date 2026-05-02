<?php

declare(strict_types=1);

namespace App\Filament\Resources\SellerStockResource\Pages;

use App\Filament\Resources\SellerStockResource;
use Filament\Resources\Pages\EditRecord;

class EditSellerStock extends EditRecord
{
    protected static string $resource = SellerStockResource::class;
}
