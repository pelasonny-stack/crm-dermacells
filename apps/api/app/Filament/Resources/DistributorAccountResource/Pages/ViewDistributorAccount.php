<?php

declare(strict_types=1);

namespace App\Filament\Resources\DistributorAccountResource\Pages;

use App\Filament\Resources\DistributorAccountResource;
use App\Jobs\DistributorFinance\RecalculateDistributorAccountJob;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewDistributorAccount extends ViewRecord
{
    protected static string $resource = DistributorAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('recalculate')
                ->label('Recalcular ahora')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->action(function (): void {
                    RecalculateDistributorAccountJob::dispatch($this->record->distributor_id);
                    Notification::make()
                        ->success()
                        ->title('Recálculo programado')
                        ->body('El saldo se actualizará en breve.')
                        ->send();
                }),
        ];
    }
}
