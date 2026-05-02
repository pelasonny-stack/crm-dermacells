<?php

declare(strict_types=1);

namespace App\Filament\Resources\SellerMonthlyGoalResource\Pages;

use App\Filament\Resources\SellerMonthlyGoalResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSellerMonthlyGoal extends CreateRecord
{
    protected static string $resource = SellerMonthlyGoalResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}
