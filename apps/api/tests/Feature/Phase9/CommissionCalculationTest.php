<?php

declare(strict_types=1);

/**
 * Phase 9 — Commission Calculation Unit/Integration Tests.
 *
 * Tests the CommissionCalculatorService in isolation from the HTTP layer.
 * Payments are inserted directly via DB facade because the Phase 7 payments
 * table is being built in parallel and no Payment model/factory exists yet.
 * The guard at the top of each test checks for the table's existence and
 * skips gracefully if Phase 7 has not been migrated in this environment.
 *
 * Tier seed (from Phase 2 seeder):
 *   tier 1: threshold USD 0       → 10% (tier_order 1)
 *   tier 2: threshold USD 7500    → 12% (tier_order 2)
 *   tier 3: threshold USD 11250   → 15% (tier_order 3)
 */

use App\Domain\Commissions\Seller\Services\CommissionCalculatorService;
use App\Enums\UserRole;
use App\Models\CommissionTier;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\User;
use App\Models\Zone;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// ---------------------------------------------------------------------------
// Helpers shared across tests in this file.
// ---------------------------------------------------------------------------

/**
 * Skip the test if the payments table does not yet exist (Phase 7 not migrated).
 */
function skipIfNoPaymentsTable(): void
{
    if (! Schema::hasTable('payments')) {
        test()->markTestSkipped('payments table not yet migrated (Phase 7 running in parallel).');
    }
}

/**
 * Load the active commission tiers for the given month and return a
 * ready-to-use CommissionCalculatorService.
 */
function makeCalculator(Carbon $month): CommissionCalculatorService
{
    $tiers = CommissionTier::activeOn($month)->get();

    return new CommissionCalculatorService($tiers);
}

/**
 * Insert a payment row directly into the payments table.
 * Returns the generated UUID.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertPayment(array $overrides = []): string
{
    $id = Str::uuid()->toString();

    // Resolve required FK columns if not provided
    $paymentMethod = PaymentMethod::factory()->create();
    $recorder      = User::factory()->create();
    $customer      = Customer::factory()->create();

    // Map legacy 'amount'/'currency' keys to the correct column names
    $amount   = $overrides['amount']   ?? $overrides['amount_amount']   ?? '1000.0000';
    $currency = $overrides['currency'] ?? $overrides['amount_currency'] ?? 'USD';
    unset($overrides['amount'], $overrides['currency']);

    DB::table('payments')->insert(array_merge([
        'id'               => $id,
        'sale_id'          => null,
        'customer_id'      => $customer->id,
        'payment_method_id' => $paymentMethod->id,
        'amount_amount'    => $amount,
        'amount_currency'  => $currency,
        'payment_date'     => '2026-05-15',
        'exchange_rate_id' => null,
        'is_advance'       => false,
        'reversed'         => false,
        'recorded_by'      => $recorder->id,
        'created_at'       => now(),
        'updated_at'       => now(),
    ], $overrides));

    return $id;
}

// ---------------------------------------------------------------------------
// Tier resolution — USD only
// ---------------------------------------------------------------------------

it('applies 10% tier when accumulated USD-equiv is USD 5000', function (): void {
    skipIfNoPaymentsTable();

    $month  = Carbon::create(2026, 5, 2);
    $seller = User::factory()->seller()->create();
    $zone   = Zone::factory()->create(['distributor_id' => null]);
    $rate   = ExchangeRate::factory()->create(['rate_ars_per_usd' => '900.000000', 'rate_date' => '2026-05-10']);
    $sale   = Sale::factory()->create(['seller_id' => $seller->id, 'zone_id' => $zone->id, 'currency' => 'USD']);

    insertPayment([
        'sale_id'          => $sale->id,
        'amount'           => '5000.0000',
        'currency'         => 'USD',
        'payment_date'     => '2026-05-15',
        'exchange_rate_id' => $rate->id,
    ]);

    $result = makeCalculator($month)->calculateForMonth($seller, $month);

    expect($result->is_director)->toBeFalse();
    expect($result->accumulated_usd->getAmount()->__toString())->toBe('5000.00');
    expect($result->tier_rate->isEqualTo(BigDecimal::of('0.1000')))->toBeTrue();
    expect($result->commission_usd->getAmount()->__toString())->toBe('500.00');
    expect($result->commission_ars->getAmount()->isZero())->toBeTrue();
});

it('applies 12% tier over 100% when accumulated USD-equiv is USD 8000 (mix ARS+USD)', function (): void {
    skipIfNoPaymentsTable();

    $month  = Carbon::create(2026, 5, 2);
    $seller = User::factory()->seller()->create();
    $zone   = Zone::factory()->create(['distributor_id' => null]);
    $rate   = ExchangeRate::factory()->create(['rate_ars_per_usd' => '1000.000000', 'rate_date' => '2026-05-10']);
    $sale   = Sale::factory()->create(['seller_id' => $seller->id, 'zone_id' => $zone->id, 'currency' => 'USD']);

    // ARS 4 000 000 / 1000 = USD 4000 equiv
    insertPayment([
        'sale_id'          => $sale->id,
        'amount'           => '4000000.0000',
        'currency'         => 'ARS',
        'payment_date'     => '2026-05-10',
        'exchange_rate_id' => $rate->id,
    ]);

    // USD 4000 native
    insertPayment([
        'sale_id'          => $sale->id,
        'amount'           => '4000.0000',
        'currency'         => 'USD',
        'payment_date'     => '2026-05-12',
        'exchange_rate_id' => $rate->id,
    ]);

    $result = makeCalculator($month)->calculateForMonth($seller, $month);

    // Accumulated: 4000 + 4000 = 8000 USD-equiv → tier 12%
    expect($result->tier_rate->isEqualTo(BigDecimal::of('0.1200')))->toBeTrue();
    // commission_ars = 4 000 000 * 0.12 = 480 000
    expect($result->commission_ars->getAmount()->__toString())->toBe('480000.00');
    // commission_usd = 4 000 * 0.12 = 480
    expect($result->commission_usd->getAmount()->__toString())->toBe('480.00');
});

it('applies 15% tier when accumulated USD-equiv is USD 12000', function (): void {
    skipIfNoPaymentsTable();

    $month  = Carbon::create(2026, 5, 2);
    $seller = User::factory()->seller()->create();
    $zone   = Zone::factory()->create(['distributor_id' => null]);
    $rate   = ExchangeRate::factory()->create(['rate_ars_per_usd' => '900.000000', 'rate_date' => '2026-05-10']);
    $sale   = Sale::factory()->create(['seller_id' => $seller->id, 'zone_id' => $zone->id, 'currency' => 'USD']);

    insertPayment([
        'sale_id'          => $sale->id,
        'amount'           => '12000.0000',
        'currency'         => 'USD',
        'payment_date'     => '2026-05-20',
        'exchange_rate_id' => $rate->id,
    ]);

    $result = makeCalculator($month)->calculateForMonth($seller, $month);

    expect($result->tier_rate->isEqualTo(BigDecimal::of('0.1500')))->toBeTrue();
    expect($result->commission_usd->getAmount()->__toString())->toBe('1800.00');
});

// ---------------------------------------------------------------------------
// Director always gets zero
// ---------------------------------------------------------------------------

it('returns zero commission and is_director=true for a Director seller', function (): void {
    skipIfNoPaymentsTable();

    $month    = Carbon::create(2026, 5, 2);
    $director = User::factory()->director()->create();
    $zone     = Zone::factory()->create(['distributor_id' => null]);
    $rate     = ExchangeRate::factory()->create(['rate_ars_per_usd' => '900.000000', 'rate_date' => '2026-05-10']);
    $sale     = Sale::factory()->create(['seller_id' => $director->id, 'zone_id' => $zone->id]);

    insertPayment([
        'sale_id'          => $sale->id,
        'amount'           => '20000.0000',
        'currency'         => 'USD',
        'payment_date'     => '2026-05-10',
        'exchange_rate_id' => $rate->id,
    ]);

    $result = makeCalculator($month)->calculateForMonth($director, $month);

    expect($result->is_director)->toBeTrue();
    expect($result->accumulated_usd->getAmount()->isZero())->toBeTrue();
    expect($result->tier_rate->isZero())->toBeTrue();
    expect($result->commission_ars->getAmount()->isZero())->toBeTrue();
    expect($result->commission_usd->getAmount()->isZero())->toBeTrue();
    expect($result->breakdown_by_zone)->toBeEmpty();
});

// ---------------------------------------------------------------------------
// Anticipo — counted in month of cobro, not month of sale
// ---------------------------------------------------------------------------

it('counts an anticipo payment in the month it was received', function (): void {
    skipIfNoPaymentsTable();

    $month  = Carbon::create(2026, 4, 1);  // April — month of the anticipo
    $seller = User::factory()->seller()->create();
    $zone   = Zone::factory()->create(['distributor_id' => null]);
    $rate   = ExchangeRate::factory()->create(['rate_ars_per_usd' => '900.000000', 'rate_date' => '2026-04-20']);
    // Sale created in May (future month)
    $sale   = Sale::factory()->create(['seller_id' => $seller->id, 'zone_id' => $zone->id, 'currency' => 'USD']);

    insertPayment([
        'sale_id'          => $sale->id,
        'amount'           => '5000.0000',
        'currency'         => 'USD',
        'payment_date'     => '2026-04-20',  // April → anticipo month
        'exchange_rate_id' => $rate->id,
        'is_advance'       => true,
    ]);

    $resultApril = makeCalculator($month)->calculateForMonth($seller, $month);
    $resultMay   = makeCalculator(Carbon::create(2026, 5, 2))->calculateForMonth($seller, Carbon::create(2026, 5, 2));

    // Anticipo counted in April
    expect($resultApril->accumulated_usd->getAmount()->__toString())->toBe('5000.00');
    // Not counted in May
    expect($resultMay->accumulated_usd->getAmount()->isZero())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Reversed payment exclusion
// ---------------------------------------------------------------------------

it('excludes reversed payments from accumulation', function (): void {
    skipIfNoPaymentsTable();

    $month  = Carbon::create(2026, 5, 2);
    $seller = User::factory()->seller()->create();
    $zone   = Zone::factory()->create(['distributor_id' => null]);
    $rate   = ExchangeRate::factory()->create(['rate_ars_per_usd' => '900.000000', 'rate_date' => '2026-05-10']);
    $sale   = Sale::factory()->create(['seller_id' => $seller->id, 'zone_id' => $zone->id, 'currency' => 'USD']);

    // Valid payment
    insertPayment([
        'sale_id'          => $sale->id,
        'amount'           => '3000.0000',
        'currency'         => 'USD',
        'payment_date'     => '2026-05-10',
        'exchange_rate_id' => $rate->id,
        'reversed'         => false,
    ]);

    // Reversed payment — must NOT be counted
    insertPayment([
        'sale_id'          => $sale->id,
        'amount'           => '10000.0000',
        'currency'         => 'USD',
        'payment_date'     => '2026-05-12',
        'exchange_rate_id' => $rate->id,
        'reversed'         => true,
    ]);

    $result = makeCalculator($month)->calculateForMonth($seller, $month);

    // Only the USD 3000 valid payment should accumulate
    expect($result->accumulated_usd->getAmount()->__toString())->toBe('3000.00');
    expect($result->tier_rate->isEqualTo(BigDecimal::of('0.1000')))->toBeTrue();
    expect($result->commission_usd->getAmount()->__toString())->toBe('300.00');
});

// ---------------------------------------------------------------------------
// ARS-only month: commission_ars > 0, commission_usd = 0
// ---------------------------------------------------------------------------

it('returns commission_ars > 0 and commission_usd = 0 for an ARS-only month', function (): void {
    skipIfNoPaymentsTable();

    $month  = Carbon::create(2026, 5, 2);
    $seller = User::factory()->seller()->create();
    $zone   = Zone::factory()->create(['distributor_id' => null]);
    $rate   = ExchangeRate::factory()->create(['rate_ars_per_usd' => '1000.000000', 'rate_date' => '2026-05-10']);
    $sale   = Sale::factory()->create(['seller_id' => $seller->id, 'zone_id' => $zone->id, 'currency' => 'ARS']);

    // ARS 6 000 000 / 1000 = USD 6000 equiv → tier 10%
    insertPayment([
        'sale_id'          => $sale->id,
        'amount'           => '6000000.0000',
        'currency'         => 'ARS',
        'payment_date'     => '2026-05-10',
        'exchange_rate_id' => $rate->id,
    ]);

    $result = makeCalculator($month)->calculateForMonth($seller, $month);

    expect($result->commission_ars->getAmount()->__toString())->toBe('600000.00');
    expect($result->commission_usd->getAmount()->isZero())->toBeTrue();
    expect($result->commission_usd->getCurrency()->getCurrencyCode())->toBe('USD');
});

// ---------------------------------------------------------------------------
// USD-only month: commission_usd > 0, commission_ars = 0
// ---------------------------------------------------------------------------

it('returns commission_usd > 0 and commission_ars = 0 for a USD-only month', function (): void {
    skipIfNoPaymentsTable();

    $month  = Carbon::create(2026, 5, 2);
    $seller = User::factory()->seller()->create();
    $zone   = Zone::factory()->create(['distributor_id' => null]);
    $rate   = ExchangeRate::factory()->create(['rate_ars_per_usd' => '900.000000', 'rate_date' => '2026-05-10']);
    $sale   = Sale::factory()->create(['seller_id' => $seller->id, 'zone_id' => $zone->id, 'currency' => 'USD']);

    insertPayment([
        'sale_id'          => $sale->id,
        'amount'           => '6000.0000',
        'currency'         => 'USD',
        'payment_date'     => '2026-05-10',
        'exchange_rate_id' => $rate->id,
    ]);

    $result = makeCalculator($month)->calculateForMonth($seller, $month);

    expect($result->commission_usd->getAmount()->__toString())->toBe('600.00');
    expect($result->commission_ars->getAmount()->isZero())->toBeTrue();
    expect($result->commission_ars->getCurrency()->getCurrencyCode())->toBe('ARS');
});

// ---------------------------------------------------------------------------
// Mixed: proportional split per currency
// ---------------------------------------------------------------------------

it('splits commission proportionally in a mixed ARS+USD month', function (): void {
    skipIfNoPaymentsTable();

    $month  = Carbon::create(2026, 5, 2);
    $seller = User::factory()->seller()->create();
    $zone   = Zone::factory()->create(['distributor_id' => null]);
    $rate   = ExchangeRate::factory()->create(['rate_ars_per_usd' => '1000.000000', 'rate_date' => '2026-05-10']);
    $sale   = Sale::factory()->create(['seller_id' => $seller->id, 'zone_id' => $zone->id, 'currency' => 'USD']);

    // ARS 3 000 000 / 1000 = USD 3000 equiv
    insertPayment([
        'sale_id'          => $sale->id,
        'amount'           => '3000000.0000',
        'currency'         => 'ARS',
        'payment_date'     => '2026-05-10',
        'exchange_rate_id' => $rate->id,
    ]);

    // USD 3000 native
    insertPayment([
        'sale_id'          => $sale->id,
        'amount'           => '3000.0000',
        'currency'         => 'USD',
        'payment_date'     => '2026-05-12',
        'exchange_rate_id' => $rate->id,
    ]);

    // Total equiv = 6000 → tier 10%
    $result = makeCalculator($month)->calculateForMonth($seller, $month);

    expect($result->tier_rate->isEqualTo(BigDecimal::of('0.1000')))->toBeTrue();
    // commission_ars = 3 000 000 * 0.10 = 300 000
    expect($result->commission_ars->getAmount()->__toString())->toBe('300000.00');
    // commission_usd = 3 000 * 0.10 = 300
    expect($result->commission_usd->getAmount()->__toString())->toBe('300.00');
});
