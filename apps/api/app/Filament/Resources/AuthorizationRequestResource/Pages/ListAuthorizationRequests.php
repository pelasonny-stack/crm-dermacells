<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuthorizationRequestResource\Pages;

use App\Filament\Resources\AuthorizationRequestResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Standard all-records list page for authorization requests.
 * Includes both pending and resolved; Directors use PendingAuthorizationQueue
 * for the focused approval workflow.
 */
class ListAuthorizationRequests extends ListRecords
{
    protected static string $resource = AuthorizationRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
