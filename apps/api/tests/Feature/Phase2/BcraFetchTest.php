<?php

declare(strict_types=1);

use App\Jobs\FetchBcraExchangeRateJob;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Notifications\BcraFallbackTriggered;
use App\Services\Bcra\BcraClient;
use App\Services\Bcra\BcraUnavailableException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| BCRA Exchange Rate Fetch Job Tests  (Phase 2 — §16.7)
|--------------------------------------------------------------------------
|
| Verifies:
|   1. On successful BCRA API response → ExchangeRate row with source='api_bna'.
|   2. On BCRA 5xx with a pre-existing rate → fallback row created with
|      source='fallback' and BcraFallbackTriggered notification dispatched.
|   3. On BCRA 5xx with NO prior rate → job marks itself as failed.
*/

beforeEach(function (): void {
    // Ensure notifications do not actually send during tests.
    Notification::fake();
    Queue::fake();
});

// ---------------------------------------------------------------------------
// 1. Happy path — API returns USD sell rate
// ---------------------------------------------------------------------------

it('persists an exchange rate row with source=api_bna on successful BCRA response', function (): void {
    $today = Carbon::today('America/Argentina/Buenos_Aires');

    Http::fake([
        '*' => Http::response([
            [
                'codigoMoneda'   => 'USD',
                'tipoCotizacion' => [
                    'venta' => 1050.50,
                ],
            ],
        ], 200),
    ]);

    // Run the job synchronously.
    (new FetchBcraExchangeRateJob())->handle(new BcraClient());

    $rate = ExchangeRate::where('rate_date', $today->toDateString())->first();

    expect($rate)->not->toBeNull();
    expect($rate->source)->toBe('api_bna');
    expect((float) $rate->rate_ars_per_usd)->toBe(1050.5);
});

it('uses updateOrCreate — does not create a duplicate row on re-run', function (): void {
    $today = Carbon::today('America/Argentina/Buenos_Aires');

    Http::fake([
        '*' => Http::response([
            [
                'codigoMoneda'   => 'USD',
                'tipoCotizacion' => [
                    'venta' => 1050.50,
                ],
            ],
        ], 200),
    ]);

    $job = new FetchBcraExchangeRateJob();
    $job->handle(new BcraClient());
    $job->handle(new BcraClient());

    $count = ExchangeRate::where('rate_date', $today->toDateString())->count();
    expect($count)->toBe(1);
});

// ---------------------------------------------------------------------------
// 2. Fallback path — API returns 5xx, prior rate exists
// ---------------------------------------------------------------------------

it('creates a fallback row when BCRA returns 5xx and a prior rate exists', function (): void {
    $yesterday = Carbon::yesterday('America/Argentina/Buenos_Aires');
    $today     = Carbon::today('America/Argentina/Buenos_Aires');

    // Insert a prior rate for yesterday (as director — setUp provides that GUC).
    ExchangeRate::create([
        'rate_date'        => $yesterday->toDateString(),
        'rate_ars_per_usd' => '1040.000000',
        'source'           => 'api_bna',
        'recorded_by'      => null,
        'created_at'       => $yesterday,
    ]);

    // Mock BCRA returning 500.
    Http::fake([
        '*' => Http::response([], 500),
    ]);

    (new FetchBcraExchangeRateJob())->handle(new BcraClient());

    $fallback = ExchangeRate::where('rate_date', $today->toDateString())->first();

    expect($fallback)->not->toBeNull();
    expect($fallback->source)->toBe('fallback');
    expect($fallback->rate_ars_per_usd)->toBe('1040.000000');
});

it('sends BcraFallbackTriggered notification to all Directors when fallback is applied', function (): void {
    $yesterday = Carbon::yesterday('America/Argentina/Buenos_Aires');

    // Create a Director so the notification has a recipient.
    $director = User::factory()->director()->create();

    ExchangeRate::create([
        'rate_date'        => $yesterday->toDateString(),
        'rate_ars_per_usd' => '1040.000000',
        'source'           => 'api_bna',
        'recorded_by'      => null,
        'created_at'       => $yesterday,
    ]);

    Http::fake([
        '*' => Http::response([], 503),
    ]);

    (new FetchBcraExchangeRateJob())->handle(new BcraClient());

    Notification::assertSentTo($director, BcraFallbackTriggered::class);
});

it('does not send a fallback notification when the API succeeds', function (): void {
    $director = User::factory()->director()->create();

    Http::fake([
        '*' => Http::response([
            ['codigoMoneda' => 'USD', 'tipoCotizacion' => ['venta' => 1050.0]],
        ], 200),
    ]);

    (new FetchBcraExchangeRateJob())->handle(new BcraClient());

    Notification::assertNotSentTo($director, BcraFallbackTriggered::class);
});

// ---------------------------------------------------------------------------
// 3. No prior rate — job should fail, not create an empty row
// ---------------------------------------------------------------------------

it('does not create a fallback row when BCRA fails and no prior rate exists', function (): void {
    $today = Carbon::today('America/Argentina/Buenos_Aires');

    Http::fake([
        '*' => Http::response([], 500),
    ]);

    // The job catches the exception in handleFallback and calls $this->fail().
    // In tests, Illuminate marks the job as failed internally — no exception
    // is re-thrown by default in the synchronous handle() context, so we just
    // assert no row was created.
    (new FetchBcraExchangeRateJob())->handle(new BcraClient());

    $count = ExchangeRate::where('rate_date', $today->toDateString())->count();
    expect($count)->toBe(0);
});

// ---------------------------------------------------------------------------
// 4. BcraClient unit-level response parsing
// ---------------------------------------------------------------------------

it('BcraClient parses tipoCotizacion.venta from flat response', function (): void {
    Http::fake([
        '*' => Http::response([
            ['codigoMoneda' => 'USD', 'tipoCotizacion' => ['venta' => 1099.75]],
        ], 200),
    ]);

    $client = new BcraClient();
    $dto    = $client->fetchUsdSellRate();

    expect($dto->source)->toBe('api_bna');
    expect($dto->rateArsPerUsd->toFloat())->toBe(1099.75);
});

it('BcraClient throws BcraUnavailableException on HTTP error', function (): void {
    Http::fake([
        '*' => Http::response([], 502),
    ]);

    $client = new BcraClient();

    expect(fn () => $client->fetchUsdSellRate())->toThrow(BcraUnavailableException::class);
});

it('BcraClient throws BcraUnavailableException when USD entry is missing from response', function (): void {
    Http::fake([
        '*' => Http::response([
            ['codigoMoneda' => 'EUR', 'tipoCotizacion' => ['venta' => 1200.0]],
        ], 200),
    ]);

    $client = new BcraClient();

    expect(fn () => $client->fetchUsdSellRate())->toThrow(BcraUnavailableException::class);
});
