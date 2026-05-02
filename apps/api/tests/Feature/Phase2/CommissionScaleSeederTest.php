<?php

declare(strict_types=1);

use App\Models\CommissionTier;
use Database\Seeders\CommissionScaleSeeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Commission Scale Seeder Tests  (Phase 2 — §12.2)
|--------------------------------------------------------------------------
|
| Verifies that CommissionScaleSeeder inserts exactly 3 tiers with the
| correct thresholds and rates, and that re-running it is idempotent.
*/

it('seeder creates exactly 3 commission tiers', function (): void {
    (new CommissionScaleSeeder())->run();

    $count = CommissionTier::count();
    expect($count)->toBe(3);
});

it('seeder creates tier 1 with threshold USD 0 and rate 10%', function (): void {
    (new CommissionScaleSeeder())->run();

    $tier = CommissionTier::where('tier_order', 1)->first();

    expect($tier)->not->toBeNull();
    expect((string) $tier->threshold_amount)->toBe('0.0000');
    expect($tier->threshold_currency)->toBe('USD');
    expect((string) $tier->rate_pct)->toBe('0.1000');
});

it('seeder creates tier 2 with threshold USD 7500 and rate 12%', function (): void {
    (new CommissionScaleSeeder())->run();

    $tier = CommissionTier::where('tier_order', 2)->first();

    expect($tier)->not->toBeNull();
    expect((float) $tier->threshold_amount)->toBe(7500.0);
    expect((string) $tier->rate_pct)->toBe('0.1200');
});

it('seeder creates tier 3 with threshold USD 11250 and rate 15%', function (): void {
    (new CommissionScaleSeeder())->run();

    $tier = CommissionTier::where('tier_order', 3)->first();

    expect($tier)->not->toBeNull();
    expect((float) $tier->threshold_amount)->toBe(11250.0);
    expect((string) $tier->rate_pct)->toBe('0.1500');
});

it('seeder is idempotent — re-running does not create duplicate tiers', function (): void {
    $seeder = new CommissionScaleSeeder();

    $seeder->run();
    $seeder->run();

    $count = CommissionTier::where('tier_order', '<=', 3)->count();

    // Exactly 3 tiers regardless of how many times the seeder runs.
    expect($count)->toBe(3);
});

it('all tiers share the same effective_from date (today)', function (): void {
    (new CommissionScaleSeeder())->run();

    $effectiveDate = today()->toDateString();

    $tiers = CommissionTier::where('effective_from', $effectiveDate)->get();
    expect($tiers)->toHaveCount(3);
});

it('tiers are ordered correctly by tier_order', function (): void {
    (new CommissionScaleSeeder())->run();

    $tiers = CommissionTier::orderBy('tier_order')->get();

    expect($tiers->first()->tier_order)->toBe(1);
    expect($tiers->last()->tier_order)->toBe(3);
});
