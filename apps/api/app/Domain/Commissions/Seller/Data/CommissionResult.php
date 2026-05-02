<?php

declare(strict_types=1);

namespace App\Domain\Commissions\Seller\Data;

use Brick\Math\BigDecimal;
use Brick\Money\Money;

/**
 * Immutable value object returned by {@see \App\Domain\Commissions\Seller\Services\CommissionCalculatorService}.
 *
 * Represents the commission calculation outcome for a single Vendedor in a
 * given calendar month (§12.2). Key design decisions:
 *
 * - accumulated_usd  : sum of all ARS/USD payments converted to USD-equivalent
 *                      using the payment-date exchange rate. Used exclusively
 *                      for tier resolution — never persisted, never shown in
 *                      customer account (§7.4).
 * - tier_rate        : the applicable commission percentage as a BigDecimal
 *                      fraction (e.g. 0.10, 0.12, 0.15). Escalón único per §12.2.
 * - commission_ars   : portion of commission payable in ARS (total_ars × tier_rate).
 * - commission_usd   : portion of commission payable in USD (total_usd × tier_rate).
 * - breakdown_by_zone: per-zone detail array for Vendedor dashboard.
 * - is_director      : when true the calculator short-circuited (§12.3) and all
 *                      money values are zero. The flag signals to callers that
 *                      no further commission logic should execute.
 *
 * @property-read Money                   $accumulated_usd
 * @property-read BigDecimal              $tier_rate
 * @property-read Money                   $commission_ars
 * @property-read Money                   $commission_usd
 * @property-read list<CommissionBreakdown> $breakdown_by_zone
 * @property-read bool                    $is_director
 */
final readonly class CommissionResult
{
    /**
     * @param list<CommissionBreakdown> $breakdown_by_zone
     */
    public function __construct(
        public Money      $accumulated_usd,
        public BigDecimal $tier_rate,
        public Money      $commission_ars,
        public Money      $commission_usd,
        public array      $breakdown_by_zone,
        public bool       $is_director = false,
    ) {}

    /**
     * Serialize to a plain array for JSON API responses and Filament tables.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'accumulated_usd'    => $this->accumulated_usd->getAmount()->__toString(),
            'tier_rate'          => $this->tier_rate->__toString(),
            'commission_ars'     => $this->commission_ars->getAmount()->__toString(),
            'commission_usd'     => $this->commission_usd->getAmount()->__toString(),
            'breakdown_by_zone'  => array_map(
                static fn (CommissionBreakdown $b): array => $b->toArray(),
                $this->breakdown_by_zone,
            ),
            'is_director'        => $this->is_director,
        ];
    }
}
