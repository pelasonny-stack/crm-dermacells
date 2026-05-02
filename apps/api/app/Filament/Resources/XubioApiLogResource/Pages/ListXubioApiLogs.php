<?php

declare(strict_types=1);

namespace App\Filament\Resources\XubioApiLogResource\Pages;

use App\Filament\Resources\XubioApiLogResource;
use Filament\Resources\Pages\ListRecords;

class ListXubioApiLogs extends ListRecords
{
    protected static string $resource = XubioApiLogResource::class;
}
