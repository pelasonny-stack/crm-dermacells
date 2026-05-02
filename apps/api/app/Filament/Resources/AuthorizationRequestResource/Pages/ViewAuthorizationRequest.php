<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuthorizationRequestResource\Pages;

use App\Domain\Authorizations\Services\AuthorizationService;
use App\Filament\Resources\AuthorizationRequestResource;
use App\Models\AuthorizationRequest;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * Detail view for a single authorization request.
 *
 * Provides approve and reject header actions when the record is still pending.
 * Uses the same AuthorizationService as the REST API to ensure consistent
 * broadcasting and notification fan-out.
 */
class ViewAuthorizationRequest extends ViewRecord
{
    protected static string $resource = AuthorizationRequestResource::class;

    protected function getHeaderActions(): array
    {
        /** @var AuthorizationRequest $record */
        $record = $this->getRecord();

        if (! $record->isPending()) {
            return [];
        }

        return [
            Actions\Action::make('approve')
                ->label('Aprobar')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->action(function (AuthorizationService $service): void {
                    /** @var AuthorizationRequest $record */
                    $record = $this->getRecord();
                    $service->approve($record, auth()->user());
                    Notification::make()->title('Solicitud aprobada')->success()->send();
                    $this->redirect(AuthorizationRequestResource::getUrl('index'));
                }),

            Actions\Action::make('reject')
                ->label('Rechazar')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->form([
                    Forms\Components\Textarea::make('rejection_reason')
                        ->label('Motivo del rechazo')
                        ->required()
                        ->minLength(5)
                        ->maxLength(2000),
                ])
                ->action(function (array $data, AuthorizationService $service): void {
                    /** @var AuthorizationRequest $record */
                    $record = $this->getRecord();
                    $service->reject($record, auth()->user(), $data['rejection_reason']);
                    Notification::make()->title('Solicitud rechazada')->danger()->send();
                    $this->redirect(AuthorizationRequestResource::getUrl('index'));
                }),
        ];
    }
}
