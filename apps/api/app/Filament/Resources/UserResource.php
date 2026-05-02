<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Customers\Services\CustomerReassignmentService;
use App\Enums\UserRole;
use App\Filament\Resources\UserResource\Pages;
use App\Models\Customer;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;

/**
 * Filament Resource for managing CRM Dermacells users.
 *
 * Visibility: Director-only. The {@see canAccess()} gate prevents non-directors
 * from seeing the resource in the navigation or reaching its routes.
 *
 * The email field is editable on creation but read-only when editing —
 * email is the OAuth identity anchor (Google/Microsoft) and must not be
 * changed without a corresponding IdP update.
 *
 * The `can_sell` toggle is only meaningful when role=director (§2.4) and is
 * hidden for other roles to avoid confusion.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Usuarios';

    protected static ?string $modelLabel = 'Usuario';

    protected static ?string $pluralModelLabel = 'Usuarios';

    protected static ?string $navigationGroup = 'Sistema';

    protected static ?int $navigationSort = 10;

    /**
     * Only Directors may access this resource.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->role === UserRole::Director;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Datos personales')
                ->schema([
                    Forms\Components\TextInput::make('full_name')
                        ->label('Nombre completo')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('email')
                        ->label('Correo electrónico')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        // Email is the OAuth identity anchor — disable editing
                        // after the record is created to prevent IdP mismatch.
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                ])
                ->columns(2),

            Forms\Components\Section::make('Rol y permisos')
                ->schema([
                    Forms\Components\Select::make('role')
                        ->label('Rol')
                        ->options(collect(UserRole::cases())->mapWithKeys(
                            fn (UserRole $case) => [$case->value => ucfirst($case->value)]
                        ))
                        ->required()
                        ->live(),

                    // can_sell is only relevant for Directors (§2.4):
                    // a Director with can_sell=true generates commission-triggering
                    // sales, which is an edge case flag set explicitly.
                    Forms\Components\Toggle::make('can_sell')
                        ->label('Puede vender')
                        ->helperText('Solo aplica para rol Director. Habilita comisiones por ventas propias.')
                        ->visible(fn (Get $get): bool => $get('role') === UserRole::Director->value),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Activo')
                        ->default(true),

                    Forms\Components\Toggle::make('ai_enabled')
                        ->label('Asistente IA habilitado')
                        ->helperText('El switch global en Configuración → IA también debe estar activo.'),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('email')
                    ->label('Correo electrónico')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('full_name')
                    ->label('Nombre completo')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('role')
                    ->label('Rol')
                    ->formatStateUsing(fn (UserRole $state): string => ucfirst($state->value))
                    ->colors([
                        'danger'  => UserRole::Director->value,
                        'warning' => UserRole::Distributor->value,
                        'success' => UserRole::Seller->value,
                    ]),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Activo'),

                Tables\Columns\TextColumn::make('last_login_at')
                    ->label('Último acceso')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->placeholder('Nunca'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->label('Rol')
                    ->options(collect(UserRole::cases())->mapWithKeys(
                        fn (UserRole $case) => [$case->value => ucfirst($case->value)]
                    )),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Activo'),
            ])
            ->defaultSort('full_name')
            ->actions([
                // "Reasignar masivamente" — §3.11. Opens a modal that lists the
                // selected Vendedor's active clients with a reassignment target select.
                // Only meaningful for Seller/Director-with-sell rows.
                Action::make('bulk_reassign')
                    ->label('Reasignar clientes')
                    ->icon('heroicon-o-arrows-right-left')
                    ->color('warning')
                    ->visible(
                        fn (User $record): bool =>
                            $record->role === UserRole::Seller ||
                            ($record->role === UserRole::Director && $record->can_sell)
                    )
                    ->form([
                        Forms\Components\Select::make('to_seller_id')
                            ->label('Reasignar a')
                            ->options(
                                User::query()
                                    ->where('is_active', true)
                                    ->whereIn('role', [UserRole::Seller->value, UserRole::Director->value])
                                    ->pluck('full_name', 'id')
                                    ->all()
                            )
                            ->searchable()
                            ->required()
                            ->helperText('Todos los clientes activos del Vendedor seran reasignados a este usuario.'),

                        Forms\Components\CheckboxList::make('customer_ids')
                            ->label('Clientes a reasignar')
                            ->options(
                                fn (array $arguments, Action $action) =>
                                    Customer::query()
                                        ->where('assigned_seller_id', $action->getRecord()?->id)
                                        ->where('is_active', true)
                                        ->orderBy('last_name')
                                        ->get(['id', 'first_name', 'last_name'])
                                        ->mapWithKeys(fn (Customer $c) => [
                                            $c->id => "{$c->last_name}, {$c->first_name}",
                                        ])
                                        ->all()
                            )
                            ->required()
                            ->helperText('Selecciona los clientes que deseas reasignar.'),
                    ])
                    ->action(function (User $record, array $data): void {
                        $toSeller = User::findOrFail($data['to_seller_id']);
                        $director = auth()->user();

                        $service = app(CustomerReassignmentService::class);
                        $count   = $service->bulkReassign($record, $data['customer_ids'], $toSeller, $director);

                        Notification::make()
                            ->success()
                            ->title('Reasignacion completada')
                            ->body("{$count} cliente(s) reasignado(s) a {$toSeller->full_name}.")
                            ->send();
                    }),

                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit'   => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
