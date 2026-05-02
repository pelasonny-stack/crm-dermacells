<?php

declare(strict_types=1);

namespace App\Services\Bcra;

use RuntimeException;

/**
 * Thrown by {@see BcraClient} when the BCRA estadísticas cambiarias API
 * returns an HTTP error, times out, or returns a response body that does not
 * contain the expected USD sell rate field.
 *
 * Catching this exception in {@see \App\Jobs\FetchBcraExchangeRateJob} triggers
 * the fallback logic per §16.7: copy the most recent available rate and mark
 * it as source='fallback', then notify all Directors.
 */
final class BcraUnavailableException extends RuntimeException {}
