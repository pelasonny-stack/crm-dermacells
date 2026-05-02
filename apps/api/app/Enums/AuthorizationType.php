<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Represents the two types of modification requests a Seller or Distributor
 * can submit for Director approval (§13.1).
 *
 * - price_change:         Request to use a different price than the client's
 *                         reference price for a specific sale or line item.
 * - exchange_rate_change: Request to apply a TC different from the BNA
 *                         last-close suggestion shown by the system.
 *
 * The Director resolves either type from the same approval queue; the enum
 * drives display labels and notification copy.
 */
enum AuthorizationType: string
{
    case PriceChange       = 'price_change';
    case ExchangeRateChange = 'exchange_rate_change';

    /** Human-readable Spanish label used in notifications and Filament UI. */
    public function label(): string
    {
        return match ($this) {
            self::PriceChange        => 'Modificación de precio',
            self::ExchangeRateChange => 'Modificación de tipo de cambio',
        };
    }
}
