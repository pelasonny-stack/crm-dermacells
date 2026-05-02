<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Exports\AuditLogExport;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
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
 * The page uses Filament's InteractsWithTable trait to render the table but
 * reads from DB facade (not an Eloquent model) because audit_log is
 * partitioned and declared append-only; we do not maintain a full Model for it.
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

    public ?string $filterUserId = null;

    public ?string $filterTableName = null;

    public ?string $filterAction = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->role === UserRole::Director;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->buildQuery())
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Fecha y hora')
                    ->sortable()
                    ->dateTime('d/m/Y H:i:s'),

                TextColumn::make('user_name')
                    ->label('Usuario')
                    ->searchable()
                    ->placeholder('Sistema'),

                TextColumn::make('action')
                    ->label('Accion')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'created'  => 'success',
                        'updated'  => 'warning',
                        'deleted'  => 'danger',
                        default    => 'gray',
                    }),

                TextColumn::make('table_name')
                    ->label('Tabla')
                    ->searchable(),

                TextColumn::make('record_id')
                    ->label('ID registro')
                    ->searchable()
                    ->limit(12)
                    ->tooltip(fn ($state): string => (string) $state),

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
                            $query->where('al.occurred_at', '>=', $data['date_from']);
                        }
                        if ($data['date_to'] ?? null) {
                            $query->where('al.occurred_at', '<=', $data['date_to'] . ' 23:59:59');
                        }

                        return $query;
                    }),

                SelectFilter::make('action')
                    ->label('Accion')
                    ->options([
                        'created' => 'Creado',
                        'updated' => 'Actualizado',
                        'deleted' => 'Eliminado',
                    ])
                    ->query(fn (Builder $query, array $data): Builder =>
                        $data['value'] ? $query->where('al.action', $data['value']) : $query
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

    private function buildQuery(): Builder
    {
        return DB::table('audit_log as al')
            ->leftJoin('users as u', 'u.id', '=', 'al.user_id')
            ->select([
                'al.id',
                'al.occurred_at',
                DB::raw("COALESCE(u.full_name, al.user_id::TEXT) AS user_name"),
                'u.email AS user_email',
                'al.action',
                'al.table_name',
                'al.record_id',
                'al.field_name',
                'al.old_value',
                'al.new_value',
                'al.hmac_hash',
            ]);
    }

    /** @return array<string, mixed> */
    private function activeFilters(): array
    {
        return array_filter([
            'date_from'  => $this->filterDateFrom,
            'date_to'    => $this->filterDateTo,
            'user_id'    => $this->filterUserId,
            'table_name' => $this->filterTableName,
            'action'     => $this->filterAction,
        ]);
    }
}
