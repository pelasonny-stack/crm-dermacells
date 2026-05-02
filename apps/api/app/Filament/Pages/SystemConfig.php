<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Models\Configuration;
use Filament\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * SystemConfig — Director-only configuration landing page (§16).
 *
 * Covers §16.9 alert/threshold configurations via a tabbed form.
 * Sub-sections §16.1-§16.8, §16.10-§16.13 are dedicated Resources and Pages
 * reachable from the sidebar navigation groups.
 *
 * This page provides a single-form UX for the key/value configurations stored
 * in the `configurations` table that do not warrant a dedicated resource:
 *   - Alert thresholds (§16.9)
 *   - Stock minimum defaults
 *   - Evolution engine defaults
 *
 * All saves write to the `configurations` table via Configuration::setValue()
 * which triggers AuditObserver for full audit trail coverage.
 */
class SystemConfig extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Configuracion del Sistema';

    protected static ?string $title = 'Configuracion del Sistema';

    protected static ?string $slug = 'system-config';

    protected static ?string $navigationGroup = 'Sistema';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.system-config';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->role === UserRole::Director;
    }

    public function mount(): void
    {
        $keys = [
            'stock_minimum_default',
            'alert_unusual_sale_threshold',
            'alert_draft_inactivity_days',
            'lot_expiry_alert_days',
            'zone_risk_threshold_pct',
            'first_purchase_no_reorder_days',
            'purchase_frequency_default_a',
            'purchase_frequency_default_b',
            'purchase_frequency_default_c',
            'purchase_frequency_default_d',
            'evolution_alert_advance_days',
        ];

        foreach ($keys as $key) {
            $this->data[$key] = Configuration::getValue($key, '');
        }

        $this->form->fill($this->data);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Stock y alertas de inventario')
                    ->description('Configuracion de stock minimo y alertas de vencimiento.')
                    ->icon('heroicon-o-cube')
                    ->columns(2)
                    ->schema([
                        TextInput::make('stock_minimum_default')
                            ->label('Stock minimo por defecto (cajas)')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->helperText('Aplicado a nuevos Vendedores/Distribuidores sin configuracion individual.'),

                        TextInput::make('lot_expiry_alert_days')
                            ->label('Dias de anticipacion para alerta de vencimiento de lote')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->helperText('El Director recibe push X dias antes del vencimiento de un lote.'),
                    ]),

                Section::make('Alertas de ventas')
                    ->description('Umbrales para alertas reactivas de ventas.')
                    ->icon('heroicon-o-bell-alert')
                    ->columns(2)
                    ->schema([
                        TextInput::make('alert_unusual_sale_threshold')
                            ->label('Umbral venta inusual (USD)')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Monto en USD a partir del cual una venta se considera inusual y dispara alerta al Director.'),

                        TextInput::make('alert_draft_inactivity_days')
                            ->label('Dias de inactividad de Borrador para alerta')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->helperText('Borradores sin modificacion por mas de X dias disparan alerta al Vendedor y Director.'),
                    ]),

                Section::make('Zonas y cartera')
                    ->description('Umbrales para alertas de zona en riesgo y primera compra.')
                    ->icon('heroicon-o-map')
                    ->columns(2)
                    ->schema([
                        TextInput::make('zone_risk_threshold_pct')
                            ->label('Umbral zona en riesgo (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->helperText('Porcentaje de clientes inactivos en una zona para disparar alerta al Distribuidor y Director.'),

                        TextInput::make('first_purchase_no_reorder_days')
                            ->label('Dias post primera compra sin recompra para alerta')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->helperText('Dias desde la primera compra sin segunda compra para disparar alerta de seguimiento.'),
                    ]),

                Section::make('Motor de evolucion de compra')
                    ->description('Frecuencia de compra esperada por categoria y anticipacion de alertas.')
                    ->icon('heroicon-o-chart-bar')
                    ->columns(3)
                    ->schema([
                        TextInput::make('purchase_frequency_default_a')
                            ->label('Frecuencia Cat. A (dias)')
                            ->numeric()
                            ->integer()
                            ->minValue(1),

                        TextInput::make('purchase_frequency_default_b')
                            ->label('Frecuencia Cat. B (dias)')
                            ->numeric()
                            ->integer()
                            ->minValue(1),

                        TextInput::make('purchase_frequency_default_c')
                            ->label('Frecuencia Cat. C (dias)')
                            ->numeric()
                            ->integer()
                            ->minValue(1),

                        TextInput::make('purchase_frequency_default_d')
                            ->label('Frecuencia Cat. D (dias)')
                            ->numeric()
                            ->integer()
                            ->minValue(1),

                        TextInput::make('evolution_alert_advance_days')
                            ->label('Dias anticipacion alerta proximo vencimiento ciclo')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->helperText('Dias antes del vencimiento de frecuencia esperada para disparar alerta.'),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Guardar configuracion')
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
            ->title('Configuracion guardada')
            ->body('Los cambios se registraron en el log de auditoria.')
            ->send();
    }
}
