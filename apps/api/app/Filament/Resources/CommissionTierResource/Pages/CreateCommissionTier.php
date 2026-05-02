<?php

declare(strict_types=1);

namespace App\Filament\Resources\CommissionTierResource\Pages;

use App\Filament\Resources\CommissionTierResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCommissionTier extends CreateRecord
{
    protected static string $resource = CommissionTierResource::class;
}
