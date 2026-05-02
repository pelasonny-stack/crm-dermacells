<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Models\Configuration;
use Filament\Actions\Action;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * AlertsThresholds — dedicated UI for §16.9 alert/threshold configuration.
 *
 * Covers all six threshold types:
 *   1. Stock minimo (por producto y global)
 *   2. Venta inusual (monto USD)
 *   3. Frecuencia de compra esperada (por categoria A/B/C/D)
 *   4. Zona en riesgo (porcentaje)
 *   5. Vencimiento de lote (dias anticipacion)
 *   6. Borrador inactividad (dias)
 *
 * Persists all values to the `configurations` table via Configuration::setValue().
 * AuditObserver on Configuration generates the audit_log entries automatically.
 */
class AlertsThresholds extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationLabel = 'Alertas y Umbrales';

    protected static ?string $title = 'Alertas y Umbrales de Configuracion';

    protected static ?string $slug = 'alerts-thresholds';

    protected static ?string $navigationGroup = 'Sistema';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.alerts-thresholds';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->role === UserRole::Director;
    }

    public function mount(): void
    {
        $defaults = [
            'stock_minimum_default'          => '5',
            'alert_unusual_sale_threshold'   => '5000',
            'alert_draft_inactivity_days'    => '30',
            'lot_expiry_alert_days'          => '30',
            'zone_risk_threshold_pct'        => '30',
            'first_purchase_no_reorder_days' => '60',
            'purchase_frequency_default_a'   => '30',
            'purchase_frequency_default_b'   => '45',
            'purchase_frequency_default_c'   => '60',
            'purchase_frequency_default_d'   => '30',
            'evolution_alert_advance_days'   => '5',
        ];

        foreach ($defaults as $key => $default) {
            $this->data[$key] = Configuration::getValue($key, $default);
        }

        $this->form->fill($this->data);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Stock minimo')
                    ->description('Valor global por defecto. Los stocks individuales de cada Vendedor/Distribuidor se configuran en sus respectivos recursos.')
                    ->icon('heroicon-o-cube')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('stock_minimum_default')
                                ->label('Stock minimo global por defecto (cajas)')
                                ->numeric()
                                ->integer()
                                ->minValue(0)
                                ->required()
                                ->helperText('Aplicado cuando no existe configuracion individual para el Vendedor o Distribuidor.'),
                        ]),
                    ]),

                Section::make('Venta inusual')
                    ->description('Alerta al Director cuando una venta supera el monto configurado.')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('alert_unusual_sale_threshold')
                                ->label('Umbral venta inusual (USD)')
                                ->numeric()
                                ->minValue(0)
                                ->required()
                                ->prefix('USD')
                                ->helperText('Monto en USD equivalente. Si la venta supera este valor, se genera alerta critica al Director.'),
                        ]),
                    ]),

                Section::make('Frecuencia de compra esperada por categoria')
                    ->description('Dias esperados entre compras para cada categoria de cliente. Override individual disponible en ficha del cliente.')
                    ->icon('heroicon-o-calendar-days')
                    ->columns(4)
                    ->schema([
                        TextInput::make('purchase_frequency_default_a')
                            ->label('Cat. A — Clinica (dias)')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->required()
                            ->suffix('dias'),

                        TextInput::make('purchase_frequency_default_b')
                            ->label('Cat. B — Profesional grande (dias)')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->required()
                            ->suffix('dias'),

                        TextInput::make('purchase_frequency_default_c')
                            ->label('Cat. C — Profesional independiente (dias)')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->required()
                            ->suffix('dias'),

                        TextInput::make('purchase_frequency_default_d')
                            ->label('Cat. D — Distribuidor-cliente (dias)')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->required()
                            ->suffix('dias'),
                    ]),

                Section::make('Zona en riesgo')
                    ->description('Alerta al Distribuidor y Director cuando un porcentaje de clientes de la zona estan inactivos.')
                    ->icon('heroicon-o-map-pin')
                    ->columns(2)
                    ->schema([
                        TextInput::make('zone_risk_threshold_pct')
                            ->label('Umbral zona en riesgo (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->required()
                            ->suffix('%')
                            ->helperText('Por defecto: 30%. Cuando mas del X% de los clientes de una zona estan inactivos, se genera alerta.'),

                        TextInput::make('evolution_alert_advance_days')
                            ->label('Anticipacion de alerta proximo vencimiento ciclo (dias)')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->required()
                            ->suffix('dias')
                            ->helperText('Dias antes del vencimiento de la frecuencia esperada para notificar al Vendedor.'),
                    ]),

                Section::make('Vencimiento de lote')
                    ->description('Alerta al Director X dias antes del vencimiento de un lote de stock central.')
                    ->icon('heroicon-o-clock')
                    ->columns(2)
                    ->schema([
                        TextInput::make('lot_expiry_alert_days')
                            ->label('Dias de anticipacion para alerta de vencimiento de lote')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->required()
                            ->suffix('dias')
                            ->helperText('Por defecto: 30 dias.'),
                    ]),

                Section::make('Borrador inactividad')
                    ->description('Alerta al Vendedor y Director cuando un Borrador no tiene actividad por X dias.')
                    ->icon('heroicon-o-document-minus')
                    ->columns(2)
                    ->schema([
                        TextInput::make('alert_draft_inactivity_days')
                            ->label('Dias de inactividad de Borrador para alerta')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->required()
                            ->suffix('dias')
                            ->helperText('Por defecto: 30 dias. Los Borradores no se cancelan automaticamente.'),

                        TextInput::make('first_purchase_no_reorder_days')
                            ->label('Dias post primera compra sin recompra')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->required()
                            ->suffix('dias')
                            ->helperText('Dias desde la primera compra sin segunda para disparar alerta de seguimiento al Vendedor.'),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Guardar umbrales')
                ->action('save')
                ->icon('heroicon-o-check')
                ->color('primary'),
        ];
    }

    public function save(): void
    {
        $validated = $this->form->getState();
        $userId    = auth()->id();

        foreach ($validated as $key => $value) {
            if ($value !== null && $value !== '') {
                Configuration::setValue($key, (string) $value, $userId);
            }
        }

        Notification::make()
            ->success()
            ->title('Umbrales guardados')
            ->body('Los cambios quedaron registrados en el log de auditoria.')
            ->send();
    }
}
