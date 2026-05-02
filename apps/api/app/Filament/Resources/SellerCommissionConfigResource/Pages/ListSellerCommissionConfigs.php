<?php

declare(strict_types=1);

namespace App\Filament\Resources\SellerCommissionConfigResource\Pages;

use App\Filament\Resources\SellerCommissionConfigResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSellerCommissionConfigs extends ListRecords
{
    protected static string $resource = SellerCommissionConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
