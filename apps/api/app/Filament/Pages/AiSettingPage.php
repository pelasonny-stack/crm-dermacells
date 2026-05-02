<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Models\AiSetting;
use App\Models\AiUsage;
use Filament\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Crypt;

/**
 * AiSettingPage — Director-only configuration UI for the AI Assistant module
 * (Phase 13 — §11.5 + §16.9).
 *
 * Surfaces:
 *   - Global enable / disable switch
 *   - Provider (openai | anthropic)
 *   - Model identifier (free text — §11.5)
 *   - Endpoint override (optional, defaults to provider's official URL)
 *   - API key (write-only, masked, encrypted via Crypt)
 *   - Monthly token / USD cap defaults
 *   - Current month total token usage + cost (read-only telemetry)
 *
 * Per-user overrides are managed through Users → Edit (the User form already
 * carries the `ai_enabled` toggle). Custom per-user caps live on the
 * AiUserOverride table; expose them via a separate resource if Director needs
 * granular tuning beyond the boolean.
 */
class AiSettingPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationLabel = 'Asistente IA';

    protected static ?string $title = 'Asistente IA — Configuracion';

    protected static ?string $slug = 'ai-settings';

    protected static ?string $navigationGroup = 'Sistema';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.ai-setting';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->role === UserRole::Director;
    }

    public function mount(): void
    {
        $settings = AiSetting::current();

        $this->data = [
            'global_enabled'            => $settings->global_enabled,
            'provider'                  => $settings->provider,
            'model'                     => $settings->model,
            'endpoint'                  => $settings->endpoint,
            // Never echo the encrypted key back — leave the password input empty.
            'api_key_plaintext'         => '',
            'monthly_token_cap_default' => $settings->monthly_token_cap_default,
            'monthly_usd_cap_default'   => $settings->monthly_usd_cap_default,
        ];

        $this->form->fill($this->data);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Activacion')
                ->description('Switch global del asistente. Si esta apagado, las APIs /ai/* devuelven 503.')
                ->icon('heroicon-o-power')
                ->schema([
                    Toggle::make('global_enabled')
                        ->label('Asistente IA habilitado globalmente')
                        ->helperText('Override por usuario disponible en Usuarios.'),
                ]),

            Section::make('Proveedor')
                ->description('La switch toma efecto sin redeploy.')
                ->icon('heroicon-o-cpu-chip')
                ->columns(2)
                ->schema([
                    Select::make('provider')
                        ->label('Proveedor')
                        ->options([
                            'openai'    => 'OpenAI',
                            'anthropic' => 'Anthropic',
                        ])
                        ->required()
                        ->live(),

                    TextInput::make('model')
                        ->label('Modelo (string libre)')
                        ->placeholder('p. ej. gpt-4o-mini, claude-sonnet-4-7')
                        ->helperText('Editar sin temor: el factory lee este valor en cada request.')
                        ->required(),

                    TextInput::make('endpoint')
                        ->label('Endpoint base (opcional)')
                        ->placeholder('https://api.openai.com/v1')
                        ->helperText('Vacio = usar el endpoint oficial del proveedor.'),

                    TextInput::make('api_key_plaintext')
                        ->label('API key (write-only)')
                        ->password()
                        ->revealable(false)
                        ->placeholder('Pegar para reemplazar; vacio = mantener actual')
                        ->helperText('Almacenada cifrada con Crypt. Solo se reescribe si pegas un valor.'),
                ]),

            Section::make('Limites de consumo')
                ->description('Caps mensuales por defecto. Override por usuario en Usuarios.')
                ->icon('heroicon-o-shield-check')
                ->columns(2)
                ->schema([
                    TextInput::make('monthly_token_cap_default')
                        ->label('Cap mensual de tokens (default)')
                        ->numeric()
                        ->integer()
                        ->minValue(0),

                    TextInput::make('monthly_usd_cap_default')
                        ->label('Cap mensual en USD (default)')
                        ->numeric()
                        ->minValue(0),
                ]),

            Section::make('Consumo actual')
                ->description('Totales del mes en curso (read-only).')
                ->icon('heroicon-o-chart-bar')
                ->schema([
                    \Filament\Forms\Components\Placeholder::make('usage_summary')
                        ->label('Resumen del mes')
                        ->content(function (): string {
                            $period = now()->startOfMonth()->toDateString();
                            $row = AiUsage::query()
                                ->where('period_month', $period)
                                ->selectRaw('
                                    COALESCE(SUM(total_tokens), 0)     as total_tokens,
                                    COALESCE(SUM(cost_estimate_usd), 0) as cost_usd,
                                    COUNT(*)                            as call_count
                                ')->first();

                            $tokens = (int) ($row->total_tokens ?? 0);
                            $usd    = (string) ($row->cost_usd ?? '0.0000');
                            $calls  = (int) ($row->call_count ?? 0);

                            return "Tokens: {$tokens} · USD: \${$usd} · Llamadas: {$calls}";
                        }),
                ]),
        ])->statePath('data');
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Guardar')
                ->action('save')
                ->icon('heroicon-o-check')
                ->color('primary'),
        ];
    }

    public function save(): void
    {
        $values = $this->form->getState();

        $settings = AiSetting::current();

        $settings->global_enabled            = (bool) ($values['global_enabled'] ?? false);
        $settings->provider                  = $values['provider'] ?? null;
        $settings->model                     = $values['model'] ?? null;
        $settings->endpoint                  = $values['endpoint'] ?? null;
        $settings->monthly_token_cap_default = (int) ($values['monthly_token_cap_default'] ?? 1_000_000);
        $settings->monthly_usd_cap_default   = (string) ($values['monthly_usd_cap_default'] ?? '100.00');
        $settings->updated_by                = auth()->id();
        $settings->updated_at                = now();

        // Only re-encrypt the API key when a fresh plaintext was pasted.
        $plaintext = (string) ($values['api_key_plaintext'] ?? '');
        if ($plaintext !== '') {
            $settings->api_key_encrypted = Crypt::encryptString($plaintext);
        }

        $settings->save();

        // Reset the password input so a future page load doesn't show stale UX.
        $this->data['api_key_plaintext'] = '';

        Notification::make()
            ->success()
            ->title('Configuracion guardada')
            ->body('La proxima llamada IA usara los nuevos valores.')
            ->send();
    }
}
