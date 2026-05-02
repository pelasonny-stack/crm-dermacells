<?php

declare(strict_types=1);

namespace App\Filament\Resources\DistributorPreferredCostResource\Pages;

use App\Filament\Resources\DistributorPreferredCostResource;
use Filament\Resources\Pages\EditRecord;

class EditDistributorPreferredCost extends EditRecord
{
    protected static string $resource = DistributorPreferredCostResource::class;

    /**
     * Inject updated_by before persisting the edit.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = auth()->id();
        $data['updated_at'] = now();
        return $data;
    }
}
