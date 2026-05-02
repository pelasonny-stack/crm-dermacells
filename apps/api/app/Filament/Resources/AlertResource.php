<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\AlertResource\Pages\ListAlerts;
use App\Models\Alert;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * AlertResource — Phase 10 Filament panel.
 *
 * Visibility:
 *   Director  — sees ALL alerts (no Eloquent scope; RLS policy handles it).
 *   Vendedor/Distribuidor — sees only their own alerts (target_user_id = auth user).
 *
 * The resource is intentionally read-only (no Create / Edit pages).
 * Marking read is done via the API endpoint (PATCH /alerts/{id}/read).
 */
class AlertResource extends Resource
{
    protected static ?string $model = Alert::class;

    protected static ?string $navigationIcon = 'heroicon-o-bell';

    protected static ?string $navigationLabel = 'Alertas';

    protected static ?string $modelLabel = 'Alerta';

    protected static ?string $pluralModelLabel = 'Alertas';

    protected static ?string $navigationGroup = 'Operaciones';

    protected static ?int $navigationSort = 10;

    /**
     * Restrict panel access: Directors see all; Sellers/Distributors see own.
     * The Eloquent query scope is applied in getEloquentQuery().
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    /**
     * Scope: Directors see all (RLS passes through); others see own.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = \Filament\Facades\Filament::auth()->user();

        if ($user && $user->role !== UserRole::Director) {
            $query->where('target_user_id', $user->getKey());
        }

        return $query->orderByDesc('created_at');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('alert_type')
                    ->label('Tipo')
                    ->badge()
                    ->sortable()
                    ->searchable(),

                TextColumn::make('targetUser.full_name')
                    ->label('Destinatario')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('severity')
                    ->label('Severidad')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'critical' => 'danger',
                        'warning'  => 'warning',
                        default    => 'info',
                    }),

                IconColumn::make('delivered')
                    ->label('Entregada')
                    ->boolean(),

                IconColumn::make('read_at')
                    ->label('Leida')
                    ->getStateUsing(fn (Alert $record): bool => $record->read_at !== null)
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->timezone('America/Argentina/Buenos_Aires')
                    ->sortable(),

                TextColumn::make('reference_entity_type')
                    ->label('Entidad')
                    ->formatStateUsing(fn (?string $state): string => $state
                        ? class_basename($state)
                        : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('alert_type')
                    ->label('Tipo')
                    ->options(fn (): array => Alert::distinct()->pluck('alert_type', 'alert_type')->toArray()),

                SelectFilter::make('severity')
                    ->label('Severidad')
                    ->options([
                        'info'     => 'Info',
                        'warning'  => 'Advertencia',
                        'critical' => 'Crítica',
                    ]),

                Filter::make('unread')
                    ->label('No leidas')
                    ->query(fn (Builder $query): Builder => $query->whereNull('read_at')),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAlerts::route('/'),
        ];
    }
}
