<?php

declare(strict_types=1);

namespace App\Filament\Resources\SellerCommissionConfigResource\Pages;

use App\Filament\Resources\SellerCommissionConfigResource;
use Filament\Resources\Pages\EditRecord;

class EditSellerCommissionConfig extends EditRecord
{
    protected static string $resource = SellerCommissionConfigResource::class;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['set_by'] = auth()->id();
        return $data;
    }
}
