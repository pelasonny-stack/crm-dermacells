<?php

declare(strict_types=1);

namespace App\Filament\Resources\DistributorAccountResource\Pages;

use App\Filament\Resources\DistributorAccountResource;
use Filament\Resources\Pages\ListRecords;

class ListDistributorAccounts extends ListRecords
{
    protected static string $resource = DistributorAccountResource::class;
}
