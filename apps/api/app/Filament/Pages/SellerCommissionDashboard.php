<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Commissions\Seller\Data\CommissionResult;
use App\Domain\Commissions\Seller\Services\CommissionCalculatorService;
use App\Enums\UserRole;
use App\Models\CommissionTier;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Filament admin page — Seller Commission Dashboard (Phase 9, §12.2).
 *
 * Accessible only to Directors (enforced by AdminPanelProvider middleware stack).
 * Provides:
 *   - A seller selector (all non-Director users).
 *   - A month picker (YYYY-MM format).
 *   - A commission summary panel (accumulated USD-equiv, tier, ARS/USD split).
 *   - A breakdown table with one row per zone.
 *
 * Data is computed on page load and on form submission — no background job
 * is dispatched. The page is intentionally stateless (no Livewire persistence
 * beyond the current request) to guarantee Directors always see live data.
 */
class SellerCommissionDashboard extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static string $view = 'filament.pages.seller-commission-dashboard';

    protected static ?string $navigationLabel = 'Seller Commissions';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 10;

    // -------------------------------------------------------------------------
    // Public form state (Livewire wire properties).
    // -------------------------------------------------------------------------

    /** @var string|null  UUID of the selected Vendedor. */
    public ?string $selectedSellerId = null;

    /** @var string  Calendar month in YYYY-MM format. Defaults to current month. */
    public string $selectedMonth;

    // -------------------------------------------------------------------------
    // Computed result (populated by calculate()).
    // -------------------------------------------------------------------------

    /** @var CommissionResult|null */
    public ?CommissionResult $result = null;

    /** @var string|null  Full name of the selected seller for display. */
    public ?string $selectedSellerName = null;

    public function mount(): void
    {
        $this->selectedMonth = Carbon::now()->format('Y-m');
        $this->form->fill([
            'selectedSellerId' => null,
            'selectedMonth'    => $this->selectedMonth,
        ]);
    }

    /**
     * Filament form schema.
     *
     * @return array<int, \Filament\Forms\Components\Component>
     */
    protected function getFormSchema(): array
    {
        return [
            Select::make('selectedSellerId')
                ->label('Seller')
                ->options($this->sellerOptions())
                ->searchable()
                ->required()
                ->placeholder('Select a seller...'),

            \Filament\Forms\Components\TextInput::make('selectedMonth')
                ->label('Month (YYYY-MM)')
                ->placeholder(Carbon::now()->format('Y-m'))
                ->regex('/^\d{4}-\d{2}$/')
                ->required(),
        ];
    }

    /**
     * Calculate commission for the selected seller and month.
     * Called by the "Calculate" form action button.
     */
    public function calculate(): void
    {
        $this->form->validate();

        $month  = $this->resolveMonth($this->selectedMonth);
        $seller = User::findOrFail($this->selectedSellerId);
        $tiers  = CommissionTier::activeOn($month)->get();

        $calculator   = new CommissionCalculatorService($tiers);
        $this->result = $calculator->calculateForMonth($seller, $month);

        $this->selectedSellerName = $seller->full_name ?? $seller->email;
    }

    /**
     * Serialize the result breakdown for the Blade view table.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBreakdownRows(): array
    {
        if ($this->result === null) {
            return [];
        }

        return array_map(
            static fn ($b) => $b->toArray(),
            $this->result->breakdown_by_zone,
        );
    }

    /**
     * Returns the result as a flat summary array for the summary panel.
     *
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        if ($this->result === null) {
            return [];
        }

        return [
            'accumulated_usd' => $this->result->accumulated_usd->getAmount()->__toString(),
            'tier_rate_pct'   => bcmul($this->result->tier_rate->__toString(), '100', 2) . '%',
            'commission_ars'  => $this->result->commission_ars->getAmount()->__toString(),
            'commission_usd'  => $this->result->commission_usd->getAmount()->__toString(),
            'is_director'     => $this->result->is_director,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Options for the seller Select: all non-Director active users.
     *
     * @return array<string, string>
     */
    private function sellerOptions(): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereIn('role', [UserRole::Seller->value, UserRole::Distributor->value])
            ->orderBy('full_name')
            ->pluck('full_name', 'id')
            ->all();
    }

    /**
     * Parse YYYY-MM into a Carbon at start of that month.
     */
    private function resolveMonth(string $monthStr): Carbon
    {
        try {
            return Carbon::createFromFormat('Y-m', $monthStr)->startOfMonth();
        } catch (\Exception) {
            return Carbon::now()->startOfMonth();
        }
    }
}
