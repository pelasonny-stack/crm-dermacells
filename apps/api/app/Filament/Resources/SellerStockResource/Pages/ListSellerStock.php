<?php

declare(strict_types=1);

namespace App\Filament\Resources\SellerStockResource\Pages;

use App\Filament\Resources\SellerStockResource;
use Filament\Resources\Pages\ListRecords;

class ListSellerStock extends ListRecords
{
    protected static string $resource = SellerStockResource::class;
}
