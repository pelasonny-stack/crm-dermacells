<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\IvaCondition;
use App\Filament\Resources\CustomerResource\Pages\CreateCustomer;
use App\Filament\Resources\CustomerResource\Pages\EditCustomer;
use App\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Models\Customer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filament resource for the Customer entity.
 *
 * TABS (§3 Filament spec):
 *   1. Datos principales   — identity, category, zone, assignment, prices
 *   2. Razones sociales    — billing entities (§3.5)
 *   3. Contactos           — customer contacts + birthday (§3.6)
 *   4. Acciones programadas — scheduled actions (§3.8)
 *
 * PLACEHOLDER TAB: WhatsApp history (Phase 12) is scaffolded as a disabled
 * tab so the UI slot is reserved without functional code.
 *
 * ACCESS: This resource is registered in the Filament Admin panel
 * (AdminPanelProvider) which is Director-only. Distributors and Sellers access
 * customers exclusively through the PWA/mobile API endpoints.
 */
class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Clientes';

    protected static ?string $navigationGroup = 'Comercial';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'first_name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['first_name', 'last_name', 'cuit', 'email', 'phone'];
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('customer_tabs')
                    ->tabs([
                        // ============================================================
                        // TAB 1: Datos principales
                        // ============================================================
                        Forms\Components\Tabs\Tab::make('Datos principales')
                            ->icon('heroicon-o-identification')
                            ->schema([
                                Forms\Components\Section::make('Identidad')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('first_name')
                                            ->label('Nombre')
                                            ->required()
                                            ->maxLength(255),
                                        Forms\Components\TextInput::make('last_name')
                                            ->label('Apellido')
                                            ->required()
                                            ->maxLength(255),
                                        Forms\Components\TextInput::make('cuit')
                                            ->label('CUIT')
                                            ->required()
                                            ->unique(ignorable: fn ($record) => $record)
                                            ->maxLength(11)
                                            ->placeholder('20123456789'),
                                        Forms\Components\TextInput::make('phone')
                                            ->label('Teléfono')
                                            ->required()
                                            ->tel()
                                            ->maxLength(50),
                                        Forms\Components\TextInput::make('email')
                                            ->label('Email')
                                            ->required()
                                            ->email()
                                            ->maxLength(255),
                                        Forms\Components\Textarea::make('address')
                                            ->label('Dirección')
                                            ->required()
                                            ->columnSpanFull(),
                                    ]),

                                Forms\Components\Section::make('Clasificación y asignación')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Select::make('category_id')
                                            ->label('Categoría')
                                            ->relationship('category', 'name')
                                            ->required()
                                            ->searchable()
                                            ->preload(),
                                        Forms\Components\Select::make('zone_id')
                                            ->label('Zona')
                                            ->relationship('zone', 'name', fn (Builder $query) => $query->where('is_active', true))
                                            ->required()
                                            ->searchable()
                                            ->preload(),
                                        Forms\Components\Select::make('assigned_seller_id')
                                            ->label('Vendedor asignado')
                                            ->relationship('assignedSeller', 'full_name', fn (Builder $query) => $query->where('is_active', true))
                                            ->required()
                                            ->searchable()
                                            ->preload(),
                                        Forms\Components\Select::make('default_payment_terms_id')
                                            ->label('Condición de pago por defecto')
                                            ->relationship('defaultPaymentTerm', 'name', fn (Builder $query) => $query->where('is_active', true))
                                            ->required()
                                            ->preload(),
                                    ]),

                                Forms\Components\Section::make('Precio de referencia')
                                    ->columns(3)
                                    ->description('Solo editable por Directores. Si no se carga, aplica el precio base del producto.')
                                    ->schema([
                                        Forms\Components\TextInput::make('reference_price_amount')
                                            ->label('Precio referencia (monto)')
                                            ->numeric()
                                            ->minValue(0)
                                            ->step(0.0001)
                                            ->placeholder('750.0000'),
                                        Forms\Components\Select::make('reference_price_currency')
                                            ->label('Moneda')
                                            ->options(['USD' => 'USD', 'ARS' => 'ARS'])
                                            ->default('USD'),
                                        Forms\Components\TextInput::make('reference_price_unit_amount')
                                            ->label('Precio por unidad suelta')
                                            ->numeric()
                                            ->minValue(0)
                                            ->step(0.0001)
                                            ->helperText('Por defecto = precio caja / 5'),
                                    ]),

                                Forms\Components\Section::make('Motor de evolución')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('purchase_frequency_days')
                                            ->label('Frecuencia de compra esperada (días)')
                                            ->numeric()
                                            ->minValue(1)
                                            ->maxValue(3650)
                                            ->helperText('Override individual. Por defecto se usa el valor de la categoría.'),
                                        Forms\Components\DatePicker::make('first_purchase_date')
                                            ->label('Fecha primera compra')
                                            ->disabled()
                                            ->helperText('Calculado automáticamente por el sistema.'),
                                    ]),
                            ]),

                        // ============================================================
                        // TAB 2: Razones sociales
                        // ============================================================
                        Forms\Components\Tabs\Tab::make('Razones sociales')
                            ->icon('heroicon-o-building-office')
                            ->schema([
                                Forms\Components\Repeater::make('billingEntities')
                                    ->relationship()
                                    ->label('Razones sociales de facturación')
                                    ->schema([
                                        Forms\Components\TextInput::make('name')
                                            ->label('Razón social')
                                            ->required()
                                            ->maxLength(255),
                                        Forms\Components\TextInput::make('cuit')
                                            ->label('CUIT')
                                            ->required()
                                            ->maxLength(11),
                                        Forms\Components\Select::make('iva_condition')
                                            ->label('Condición IVA')
                                            ->required()
                                            ->options(
                                                collect(IvaCondition::cases())
                                                    ->mapWithKeys(fn (IvaCondition $c) => [$c->value => $c->label()])
                                                    ->all()
                                            ),
                                        Forms\Components\Toggle::make('is_primary')
                                            ->label('Principal')
                                            ->default(false),
                                        Forms\Components\TextInput::make('xubio_cliente_id')
                                            ->label('ID Xubio')
                                            ->disabled()
                                            ->helperText('Asignado automáticamente en la primera factura.'),
                                    ])
                                    ->columns(2)
                                    ->addActionLabel('Agregar razón social'),
                            ]),

                        // ============================================================
                        // TAB 3: Contactos
                        // ============================================================
                        Forms\Components\Tabs\Tab::make('Contactos')
                            ->icon('heroicon-o-phone')
                            ->schema([
                                Forms\Components\Repeater::make('contacts')
                                    ->relationship()
                                    ->label('Contactos del cliente')
                                    ->schema([
                                        Forms\Components\TextInput::make('full_name')
                                            ->label('Nombre completo')
                                            ->required()
                                            ->maxLength(255),
                                        Forms\Components\TextInput::make('phone')
                                            ->label('Teléfono')
                                            ->required()
                                            ->maxLength(50),
                                        Forms\Components\TextInput::make('email')
                                            ->label('Email')
                                            ->required()
                                            ->email()
                                            ->maxLength(255),
                                        Forms\Components\TextInput::make('role_label')
                                            ->label('Cargo / Rol')
                                            ->required()
                                            ->maxLength(255),
                                        Forms\Components\DatePicker::make('birthday')
                                            ->label('Fecha de cumpleaños')
                                            ->helperText('Si se carga, el vendedor recibe un push a las 8AM del día.'),
                                    ])
                                    ->columns(2)
                                    ->addActionLabel('Agregar contacto'),
                            ]),

                        // ============================================================
                        // TAB 4: Acciones programadas
                        // ============================================================
                        Forms\Components\Tabs\Tab::make('Acciones programadas')
                            ->icon('heroicon-o-calendar')
                            ->schema([
                                Forms\Components\Repeater::make('scheduledActions')
                                    ->relationship()
                                    ->label('Acciones futuras programadas')
                                    ->schema([
                                        Forms\Components\DatePicker::make('scheduled_date')
                                            ->label('Fecha programada')
                                            ->required()
                                            ->minDate(now()),
                                        Forms\Components\Textarea::make('note')
                                            ->label('Nota / Motivo')
                                            ->required()
                                            ->maxLength(2000),
                                        Forms\Components\Toggle::make('is_resolved')
                                            ->label('Resuelta')
                                            ->default(false),
                                    ])
                                    ->columns(2)
                                    ->addActionLabel('Agregar acción'),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('last_name')
                    ->label('Apellido')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('first_name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('cuit')
                    ->label('CUIT')
                    ->searchable(),
                Tables\Columns\TextColumn::make('category.code')
                    ->label('Cat.')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('zone.name')
                    ->label('Zona')
                    ->sortable(),
                Tables\Columns\TextColumn::make('assignedSeller.full_name')
                    ->label('Vendedor')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Alta')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Estado')
                    ->trueLabel('Solo activos')
                    ->falseLabel('Solo inactivos'),
                Tables\Filters\SelectFilter::make('category_id')
                    ->label('Categoría')
                    ->relationship('category', 'name'),
                Tables\Filters\SelectFilter::make('zone_id')
                    ->label('Zona')
                    ->relationship('zone', 'name'),
                Tables\Filters\SelectFilter::make('assigned_seller_id')
                    ->label('Vendedor')
                    ->relationship('assignedSeller', 'full_name'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('last_name');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'edit'   => EditCustomer::route('/{record}/edit'),
        ];
    }
}
