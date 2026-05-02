<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Exports\AuditLogExport;
use App\Models\AuditLog;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AuditLogViewer — searchable + filterable read-only view of audit_log (§16.13).
 *
 * The audit_log is append-only and HMAC-chained. This page presents it with:
 *   - Full-text search by user, table, or record ID
 *   - Filters: date range, user, table_name, action
 *   - Export actions: CSV / PDF (PDF requires barryvdh/laravel-dompdf)
 *
 * Director can NOT edit or delete rows — the form is read-only by design.
 * The Postgres BEFORE trigger enforces immutability at DB level as well.
 *
 * The page uses Filament's InteractsWithTable trait to render the table.
 * Reads via the AuditLog Eloquent model (read-only, no timestamps) which wraps
 * the append-only audit_log table and provides an Eloquent Builder for Filament.
 */
class AuditLogViewer extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Log de Auditoria';

    protected static ?string $title = 'Log de Auditoria';

    protected static ?string $slug = 'audit-log';

    protected static ?string $navigationGroup = 'Sistema';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.audit-log-viewer';

    // -------------------------------------------------------------------------
    // Active export filters (bound from URL or form)
    // -------------------------------------------------------------------------

    public ?string $filterDateFrom = null;

    public ?string $filterDateTo = null;

    public ?string $filterActorUserId = null;

    public ?string $filterSection = null;

    public ?string $filterActorRole = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->role === UserRole::Director;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => AuditLog::withActorName())
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Fecha y hora')
                    ->sortable()
                    ->dateTime('d/m/Y H:i:s'),

                TextColumn::make('actor_name')
                    ->label('Actor')
                    ->searchable(false)
                    ->placeholder('Sistema'),

                TextColumn::make('actor_role')
                    ->label('Rol')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'director'    => 'danger',
                        'seller'      => 'success',
                        'distributor' => 'warning',
                        default       => 'gray',
                    })
                    ->placeholder('—'),

                TextColumn::make('section')
                    ->label('Seccion')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('entity_type')
                    ->label('Entidad')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('entity_id')
                    ->label('ID entidad')
                    ->limit(12)
                    ->tooltip(fn ($state): string => (string) ($state ?? ''))
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('field_name')
                    ->label('Campo')
                    ->placeholder('—'),

                TextColumn::make('old_value')
                    ->label('Valor anterior')
                    ->limit(40)
                    ->placeholder('—'),

                TextColumn::make('new_value')
                    ->label('Valor nuevo')
                    ->limit(40)
                    ->placeholder('—'),
            ])
            ->filters([
                Filter::make('date_range')
                    ->form([
                        DatePicker::make('date_from')
                            ->label('Desde')
                            ->displayFormat('d/m/Y'),
                        DatePicker::make('date_to')
                            ->label('Hasta')
                            ->displayFormat('d/m/Y'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if ($data['date_from'] ?? null) {
                            $query->where('audit_log.occurred_at', '>=', $data['date_from']);
                        }
                        if ($data['date_to'] ?? null) {
                            $query->where('audit_log.occurred_at', '<=', $data['date_to'] . ' 23:59:59');
                        }

                        return $query;
                    }),

                SelectFilter::make('actor_role')
                    ->label('Rol')
                    ->options([
                        'director'    => 'Director',
                        'seller'      => 'Vendedor',
                        'distributor' => 'Distribuidor',
                    ])
                    ->query(fn (Builder $query, array $data): Builder =>
                        $data['value'] ? $query->where('audit_log.actor_role', $data['value']) : $query
                    ),

                SelectFilter::make('section')
                    ->label('Seccion')
                    ->options(fn (): array => AuditLog::distinct()->orderBy('section')->pluck('section', 'section')->filter()->toArray())
                    ->query(fn (Builder $query, array $data): Builder =>
                        $data['value'] ? $query->where('audit_log.section', $data['value']) : $query
                    ),
            ])
            ->headerActions([
                Action::make('export_csv')
                    ->label('Exportar CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(fn () => $this->exportCsv()),

                Action::make('export_pdf')
                    ->label('Exportar PDF')
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->action(fn () => $this->exportPdf())
                    ->visible(fn (): bool => class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->paginated([25, 50, 100]);
    }

    // -------------------------------------------------------------------------
    // Export actions
    // -------------------------------------------------------------------------

    public function exportCsv(): StreamedResponse
    {
        $export = new AuditLogExport($this->activeFilters());
        $csv    = $export->toCsv();

        return Response::streamDownload(
            fn () => print($csv),
            'audit-log-' . now()->format('Y-m-d-His') . '.csv',
            ['Content-Type' => 'text/csv'],
        );
    }

    public function exportPdf(): StreamedResponse
    {
        $export = new AuditLogExport($this->activeFilters());
        $pdf    = $export->toPdf();

        return Response::streamDownload(
            fn () => print($pdf),
            'audit-log-' . now()->format('Y-m-d-His') . '.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function activeFilters(): array
    {
        return array_filter([
            'date_from'    => $this->filterDateFrom,
            'date_to'      => $this->filterDateTo,
            'actor_user_id' => $this->filterActorUserId,
            'section'      => $this->filterSection,
            'actor_role'   => $this->filterActorRole,
        ]);
    }
}
