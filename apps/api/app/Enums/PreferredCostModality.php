<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Preferred cost modality for Distributor pricing — §9.1.
 *
 * - FixedPrice: absolute price per box (e.g. USD 500/caja)
 * - DiscountPct: percentage discount off the product's base price (e.g. 0.20 = 20% off → USD 600 if base is USD 750)
 */
enum PreferredCostModality: string
{
    case FixedPrice  = 'fixed_price';
    case DiscountPct = 'discount_pct';

    public function label(): string
    {
        return match ($this) {
            self::FixedPrice  => 'Precio fijo',
            self::DiscountPct => 'Descuento porcentual',
        };
    }
}
