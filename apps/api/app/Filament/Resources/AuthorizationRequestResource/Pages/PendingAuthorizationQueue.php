<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuthorizationRequestResource\Pages;

use App\Filament\Resources\AuthorizationRequestResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 11 — Pending Authorization Queue page (§16.12).
 *
 * Shows only requests in 'pending' status, oldest first, so Directors process
 * them in FIFO order. This is the primary workflow page linked from the
 * Director's dashboard "Autorizaciones pendientes" widget.
 *
 * Approve/reject inline actions with modal for rejection_reason are defined
 * on the parent resource's table actions and apply here transparently.
 */
class PendingAuthorizationQueue extends ListRecords
{
    protected static string $resource = AuthorizationRequestResource::class;

    public function getTitle(): string
    {
        return 'Cola de Autorizaciones Pendientes';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Restrict this page's table to pending-only rows.
     * RLS provides defense-in-depth; this scope is for UX focus.
     */
    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()
            ->where('status', 'pending')
            ->orderBy('created_at', 'asc');
    }
}
