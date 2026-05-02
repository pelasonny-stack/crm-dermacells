<?php

declare(strict_types=1);

namespace App\Filament\Resources\DistributorPreferredCostResource\Pages;

use App\Filament\Resources\DistributorPreferredCostResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDistributorPreferredCost extends CreateRecord
{
    protected static string $resource = DistributorPreferredCostResource::class;

    /**
     * Inject updated_by (authenticated Director) before persisting.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['updated_by'] = auth()->id();
        $data['updated_at'] = now();
        return $data;
    }
}
