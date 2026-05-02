<?php

declare(strict_types=1);

namespace App\Filament\Resources\SellerMonthlyGoalResource\Pages;

use App\Filament\Resources\SellerMonthlyGoalResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSellerMonthlyGoal extends EditRecord
{
    protected static string $resource = SellerMonthlyGoalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
