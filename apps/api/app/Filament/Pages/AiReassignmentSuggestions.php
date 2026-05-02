<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\AI\UseCases\SuggestCustomerReassignments;
use App\Enums\UserRole;
use App\Models\Zone;
use App\Services\Customers\CustomerReassignmentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * AiReassignmentSuggestions — Filament page for §11.4 AI-assisted customer
 * reassignment (Phase 13).
 *
 * Director-only. Provides:
 *   - Zone selector (optional) and limit input.
 *   - "Get suggestions" action that calls SuggestCustomerReassignments.
 *   - Results table with Customer, Current Seller, Suggested Seller, Reason,
 *     Confidence and an inline "Apply" action that invokes
 *     CustomerReassignmentService::reassignSingle().
 *
 * State is kept in $this->suggestions (array) and rendered via the Blade view.
 * No Livewire polling — results are fetched on explicit user action only.
 */
class AiReassignmentSuggestions extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-arrow-path-rounded-square';
    protected static ?string $navigationLabel = 'Reasignaciones IA';
    protected static ?string $navigationGroup = 'Inteligencia Artificial';
    protected static ?int    $navigationSort  = 30;
    protected static string  $view            = 'filament.pages.ai-reassignment-suggestions';

    /** @var array<int, array<string, mixed>> */
    public array $suggestions = [];

    public ?string $zone_id = null;
    public int $limit = 20;

    /** Whether an LLM call is currently in progress (prevents double-submit). */
    public bool $loading = false;

    // =========================================================================
    // Filament page access control
    // =========================================================================

    public static function canAccess(): bool
    {
        $user = Auth::user();
        if ($user === null) {
            return false;
        }
        $role = $user->getAttribute('role');
        return ($role instanceof UserRole && $role->isDirector())
            || $role === UserRole::Director->value;
    }

    // =========================================================================
    // Form
    // =========================================================================

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('zone_id')
                    ->label('Zona (opcional)')
                    ->placeholder('Todas las zonas')
                    ->options(fn (): array => Zone::where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->nullable()
                    ->searchable(),

                TextInput::make('limit')
                    ->label('Cantidad máxima de sugerencias')
                    ->numeric()
                    ->default(20)
                    ->minValue(1)
                    ->maxValue(50)
                    ->required(),
            ])
            ->statePath('');
    }

    // =========================================================================
    // Header actions
    // =========================================================================

    protected function getHeaderActions(): array
    {
        return [
            Action::make('getSuggestions')
                ->label('Obtener sugerencias')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->action('fetchSuggestions'),
        ];
    }

    // =========================================================================
    // Livewire actions
    // =========================================================================

    /**
     * Calls the AI use case and populates $suggestions.
     * Triggered by the "Obtener sugerencias" header action.
     */
    public function fetchSuggestions(): void
    {
        $this->validate([
            'zone_id' => ['nullable', 'uuid'],
            'limit'   => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        $this->loading     = true;
        $this->suggestions = [];

        try {
            /** @var \App\Models\User $director */
            $director = Auth::user();
            $zone     = $this->zone_id ? Zone::find($this->zone_id) : null;

            /** @var SuggestCustomerReassignments $useCase */
            $useCase = app(SuggestCustomerReassignments::class);

            $this->suggestions = $useCase->execute($director, $zone, (int) $this->limit);

            if (empty($this->suggestions)) {
                Notification::make()
                    ->title('Sin sugerencias')
                    ->body('No se encontraron candidatos a reasignación con los filtros actuales.')
                    ->info()
                    ->send();
            } else {
                Notification::make()
                    ->title('Sugerencias obtenidas')
                    ->body(count($this->suggestions) . ' sugerencia(s) generada(s) por la IA.')
                    ->success()
                    ->send();
            }
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Error al obtener sugerencias')
                ->body($e->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->loading = false;
        }
    }

    /**
     * Apply a single AI suggestion by calling CustomerReassignmentService.
     * Triggered by the "Aplicar" inline button in the Blade table.
     *
     * @param int $index Row index in $this->suggestions.
     */
    public function applyReassignment(int $index): void
    {
        if (! isset($this->suggestions[$index])) {
            Notification::make()
                ->title('Sugerencia no encontrada')
                ->danger()
                ->send();
            return;
        }

        $suggestion = $this->suggestions[$index];

        try {
            /** @var \App\Models\User $director */
            $director = Auth::user();

            /** @var CustomerReassignmentService $service */
            $service = app(CustomerReassignmentService::class);

            $service->reassignSingle(
                customerId:  $suggestion['customer_id'],
                newSellerId: $suggestion['suggested_seller_id'],
                director:    $director,
                reason:      'IA §11.4 — ' . ($suggestion['reason'] ?? ''),
            );

            // Remove the applied suggestion from the list.
            unset($this->suggestions[$index]);
            $this->suggestions = array_values($this->suggestions);

            Notification::make()
                ->title('Reasignación aplicada')
                ->body('El cliente fue reasignado correctamente.')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Error al aplicar reasignación')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
