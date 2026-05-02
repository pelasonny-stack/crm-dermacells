<?php

declare(strict_types=1);

namespace App\Filament\Resources\ZoneResource\Pages;

use App\Filament\Resources\ZoneResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListZones extends ListRecords
{
    protected static string $resource = ZoneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
