<?php

declare(strict_types=1);

namespace App\Filament\Resources\ConfigurationResource\Pages;

use App\Filament\Resources\ConfigurationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateConfiguration extends CreateRecord
{
    protected static string $resource = ConfigurationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['updated_by'] = auth()->id();

        return $data;
    }
}
