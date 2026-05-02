<?php

declare(strict_types=1);

namespace App\Domain\Commissions\Seller\Data;

use Brick\Money\Money;

/**
 * Per-zone commission breakdown row.
 *
 * Returned inside {@see CommissionResult::$breakdown_by_zone} to give
 * Vendedores and Directors visibility into how much each zone contributed
 * to the monthly accumulated total and the resulting commission split (§12.2).
 *
 * All monetary values are brick/money immutable objects. Amounts in ARS and
 * USD are always kept separate — no cross-currency arithmetic is performed
 * (per §7.4 dual-balance design).
 *
 * @property-read string      $zone_id           UUID of the zone.
 * @property-read string      $zone_name         Human-readable zone label for display.
 * @property-read Money       $collected_ars     Raw ARS payments collected in this zone this month.
 * @property-read Money       $collected_usd     Raw USD payments collected in this zone this month.
 * @property-read Money       $usd_equivalent    ARS converted to USD-equiv + native USD for tier resolution only.
 * @property-read Money       $commission_ars    ARS portion of the commission for this zone.
 * @property-read Money       $commission_usd    USD portion of the commission for this zone.
 */
final readonly class CommissionBreakdown
{
    public function __construct(
        public string $zone_id,
        public string $zone_name,
        public Money  $collected_ars,
        public Money  $collected_usd,
        public Money  $usd_equivalent,
        public Money  $commission_ars,
        public Money  $commission_usd,
    ) {}

    /**
     * Serialize to a plain array suitable for JSON API responses.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'zone_id'        => $this->zone_id,
            'zone_name'      => $this->zone_name,
            'collected_ars'  => $this->collected_ars->getAmount()->__toString(),
            'collected_usd'  => $this->collected_usd->getAmount()->__toString(),
            'usd_equivalent' => $this->usd_equivalent->getAmount()->__toString(),
            'commission_ars' => $this->commission_ars->getAmount()->__toString(),
            'commission_usd' => $this->commission_usd->getAmount()->__toString(),
        ];
    }
}
