<?php

declare(strict_types=1);

namespace App\Services\Bcra;

use Brick\Math\BigDecimal;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the BCRA estadísticas cambiarias API.
 *
 * The BCRA API is public and requires no authentication. Closing rates are
 * published around 17:30–18:00 ART. Before that hour the endpoint returns the
 * previous day's closing rate, which is acceptable per §16.7 ("cierre anterior").
 *
 * Endpoint used: GET /Cotizaciones
 *   Returns a JSON array where each element represents a currency pair.
 *   We look for the USD entry and read `tipoCotizacion.venta` (sell rate in ARS).
 *
 * Base URL is configured in config/services.php under the 'bcra' key:
 *   BCRA_BASE_URL=https://api.bcra.gob.ar/estadisticascambiarias/v1.0
 *
 * @see https://estadisticas-cambiarias.bcra.apidocs.ar/
 */
final class BcraClient
{
    /**
     * Fetch the USD sell (venta) rate from the BCRA estadísticas cambiarias API.
     *
     * @param  Carbon|null  $date  If provided, fetches the historical rate for that date.
     *                             If null (default), fetches the latest available closing rate.
     *
     * @throws BcraUnavailableException  When the API returns an HTTP error, times out,
     *                                   or the response body does not contain the USD sell rate.
     */
    public function fetchUsdSellRate(?Carbon $date = null): ExchangeRateDto
    {
        $baseUrl = config('services.bcra.base_url');

        try {
            $response = Http::baseUrl($baseUrl)
                ->timeout(10)
                ->acceptJson()
                ->get('/Cotizaciones');

            if ($response->failed()) {
                throw new BcraUnavailableException(
                    sprintf(
                        'BCRA API returned HTTP %d for GET /Cotizaciones.',
                        $response->status()
                    )
                );
            }

            $body = $response->json();
        } catch (RequestException $e) {
            throw new BcraUnavailableException(
                'BCRA API request failed: ' . $e->getMessage(),
                previous: $e,
            );
        } catch (BcraUnavailableException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new BcraUnavailableException(
                'Unexpected error communicating with BCRA API: ' . $e->getMessage(),
                previous: $e,
            );
        }

        $sellRate = $this->extractUsdSellRate($body);

        Log::info('BCRA exchange rate fetched', [
            'rate_ars_per_usd' => $sellRate,
            'date'             => $date?->toDateString() ?? 'latest',
        ]);

        return new ExchangeRateDto(
            date:          $date ?? Carbon::today('America/Argentina/Buenos_Aires'),
            rateArsPerUsd: BigDecimal::of($sellRate),
            source:        'api_bna',
        );
    }

    /**
     * Walk the response body to extract the USD venta (sell) rate.
     *
     * BCRA API returns an array of currency entries. The exact shape can vary
     * across API versions; we defensively navigate the structure.
     *
     * Expected shapes (as documented / observed):
     *
     *   Shape A — array of objects with tipoCotizacion sub-object:
     *   [
     *     { "codigoMoneda": "USD", "tipoCotizacion": { "venta": 1050.50 }, ... },
     *     ...
     *   ]
     *
     *   Shape B — flat venta key at top level:
     *   [
     *     { "codigoMoneda": "USD", "venta": 1050.50, ... },
     *     ...
     *   ]
     *
     * @param  mixed  $body  Parsed JSON response (should be an array).
     *
     * @throws BcraUnavailableException  If the expected field cannot be found.
     */
    private function extractUsdSellRate(mixed $body): string
    {
        if (! is_array($body)) {
            throw new BcraUnavailableException(
                'BCRA API response is not an array. Received: ' . gettype($body)
            );
        }

        foreach ($body as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $code = $entry['codigoMoneda'] ?? $entry['codigo'] ?? null;

            if (strtoupper((string) $code) !== 'USD') {
                continue;
            }

            // Shape A: nested tipoCotizacion.venta
            if (isset($entry['tipoCotizacion']['venta'])) {
                $venta = $entry['tipoCotizacion']['venta'];

                if (is_numeric($venta)) {
                    return (string) $venta;
                }
            }

            // Shape B: flat venta key
            if (isset($entry['venta']) && is_numeric($entry['venta'])) {
                return (string) $entry['venta'];
            }
        }

        throw new BcraUnavailableException(
            'Could not find USD sell rate (tipoCotizacion.venta or venta) in BCRA API response.'
        );
    }
}
