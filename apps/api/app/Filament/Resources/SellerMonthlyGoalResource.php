<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\SellerMonthlyGoalResource\Pages;
use App\Models\SellerMonthlyGoal;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * SellerMonthlyGoalResource — metas mensuales por Vendedor (§12.1, §16.8).
 *
 * Visibility: Director-only.
 *
 * Key features:
 *   - CRUD for seller_monthly_goals (seller_id + year_month UNIQUE constraint).
 *   - year_month stored as first-of-month DATE.
 *   - Header action "Copiar mes anterior" — copies all goals from previous month
 *     to current month, skipping sellers that already have a goal this month.
 *
 * Navigation group: Configuracion.
 */
class SellerMonthlyGoalResource extends Resource
{
    protected static ?string $model = SellerMonthlyGoal::class;

    protected static ?string $navigationIcon = 'heroicon-o-trophy';

    protected static ?string $navigationLabel = 'Metas Mensuales';

    protected static ?string $modelLabel = 'Meta mensual';

    protected static ?string $pluralModelLabel = 'Metas Mensuales';

    protected static ?string $navigationGroup = 'Configuracion';

    protected static ?int $navigationSort = 13;

    public static function canAccess(): bool
    {
        return auth()->user()?->role === UserRole::Director;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Meta mensual')
                ->schema([
                    Forms\Components\Select::make('seller_id')
                        ->label('Vendedor')
                        ->relationship(
                            name: 'seller',
                            titleAttribute: 'full_name',
                            modifyQueryUsing: fn (Builder $query) => $query
                                ->where('is_active', true)
                                ->whereIn('role', [
                                    UserRole::Seller->value,
                                    UserRole::Director->value,
                                ]),
                        )
                        ->searchable()
                        ->preload()
                        ->required()
                        ->helperText('Vendedores activos y Directores con flag puede_vender.'),

                    Forms\Components\DatePicker::make('year_month')
                        ->label('Mes y ano')
                        ->required()
                        ->displayFormat('m/Y')
                        ->default(now()->startOfMonth())
                        ->helperText('Se guarda como primer dia del mes.')
                        ->dehydrateStateUsing(
                            fn ($state) => $state
                                ? Carbon::parse($state)->startOfMonth()->toDateString()
                                : null
                        ),

                    Forms\Components\TextInput::make('target_boxes')
                        ->label('Meta (cajas)')
                        ->required()
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->helperText('Cantidad de cajas objetivo para el mes.'),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('year_month')
                    ->label('Mes')
                    ->formatStateUsing(
                        fn (SellerMonthlyGoal $record): string =>
                            $record->year_month->translatedFormat('F Y')
                    )
                    ->sortable(),

                Tables\Columns\TextColumn::make('seller.full_name')
                    ->label('Vendedor')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('target_boxes')
                    ->label('Meta (cajas)')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('createdBy.full_name')
                    ->label('Configurado por')
                    ->placeholder('Sistema'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('seller_id')
                    ->label('Vendedor')
                    ->relationship('seller', 'full_name'),
            ])
            ->headerActions([
                Action::make('copy_previous_month')
                    ->label('Copiar mes anterior')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Copiar metas del mes anterior')
                    ->modalDescription(
                        'Copia las metas del mes anterior al mes actual. ' .
                        'Los Vendedores que ya tienen meta este mes no se modifican.'
                    )
                    ->action(fn () => static::copyPreviousMonth()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('year_month', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSellerMonthlyGoals::route('/'),
            'create' => Pages\CreateSellerMonthlyGoal::route('/create'),
            'edit'   => Pages\EditSellerMonthlyGoal::route('/{record}/edit'),
        ];
    }

    // -------------------------------------------------------------------------
    // "Copy from previous month" action
    // -------------------------------------------------------------------------

    public static function copyPreviousMonth(): void
    {
        $thisMonth = now()->startOfMonth()->toDateString();
        $prevMonth = now()->subMonth()->startOfMonth()->toDateString();

        $previousGoals = SellerMonthlyGoal::query()
            ->where('year_month', $prevMonth)
            ->get();

        if ($previousGoals->isEmpty()) {
            Notification::make()
                ->warning()
                ->title('Sin metas en el mes anterior')
                ->body('No hay metas configuradas en el mes anterior para copiar.')
                ->send();

            return;
        }

        // Sellers that already have a goal this month.
        $existingSellerIds = SellerMonthlyGoal::query()
            ->where('year_month', $thisMonth)
            ->pluck('seller_id')
            ->all();

        $copied = 0;
        $directorId = auth()->id();

        foreach ($previousGoals as $goal) {
            if (in_array($goal->seller_id, $existingSellerIds, true)) {
                continue;
            }

            SellerMonthlyGoal::create([
                'seller_id'    => $goal->seller_id,
                'year_month'   => $thisMonth,
                'target_boxes' => $goal->target_boxes,
                'created_by'   => $directorId,
            ]);

            $copied++;
        }

        Notification::make()
            ->success()
            ->title('Metas copiadas')
            ->body("Se copiaron {$copied} meta(s) del mes anterior al mes actual.")
            ->send();
    }
}
