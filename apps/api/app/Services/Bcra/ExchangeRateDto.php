<?php

declare(strict_types=1);

namespace App\Services\Bcra;

use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;

/**
 * Data Transfer Object returned by {@see BcraClient::fetchUsdSellRate()}.
 *
 * Carries the parsed USD sell rate (ARS/USD) from the BCRA estadísticas
 * cambiarias API together with the effective date and origin source tag.
 *
 * @see https://estadisticas-cambiarias.bcra.apidocs.ar/
 */
final class ExchangeRateDto
{
    public function __construct(
        /** Effective date for this rate (the date the BCRA published the closing rate). */
        public readonly Carbon $date,

        /**
         * Sell (venta) rate in ARS per 1 USD.
         *
         * BigDecimal is used instead of float to avoid IEEE 754 precision loss
         * in downstream commission calculations. The value is stored as
         * NUMERIC(18,6) in the DB.
         */
        public readonly BigDecimal $rateArsPerUsd,

        /**
         * Source tag to be persisted in exchange_rates.source.
         *
         * Values: 'api_bna' | 'manual_override' | 'fallback'
         */
        public readonly string $source,
    ) {}
}
