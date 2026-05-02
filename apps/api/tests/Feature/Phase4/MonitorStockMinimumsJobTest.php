<?php

declare(strict_types=1);

use App\Jobs\MonitorStockMinimumsJob;
use App\Models\CentralStock;
use App\Models\DistributorStock;
use App\Models\Product;
use App\Models\SellerStock;
use App\Models\User;
use App\Notifications\StockBelowMinimumNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Phase 4 — MonitorStockMinimumsJob tests (§8.3, §15)
|--------------------------------------------------------------------------
|
| Verifies the job correctly identifies below-minimum rows and dispatches
| push notifications. Cache de-duplication is also tested.
|
*/

// ---------------------------------------------------------------------------
// Central stock alerts
// ---------------------------------------------------------------------------

it('Job dispatches StockBelowMinimumNotification when central stock is below minimum', function (): void {
    Notification::fake();

    $director = User::factory()->director()->create();
    $product  = Product::factory()->create();

    // available = 3, minimum_stock = 10 → below minimum
    CentralStock::create([
        'product_id'       => $product->id,
        'total_imported'   => 3,
        'total_dispatched' => 0,
        'minimum_stock'    => 10,
    ]);

    (new MonitorStockMinimumsJob())->handle();

    Notification::assertSentTo($director, StockBelowMinimumNotification::class);
});

it('Job does NOT alert when central stock equals minimum', function (): void {
    Notification::fake();

    User::factory()->director()->create();
    $product = Product::factory()->create();

    // available = 10, minimum_stock = 10 → NOT below minimum (equal is OK)
    CentralStock::create([
        'product_id'       => $product->id,
        'total_imported'   => 10,
        'total_dispatched' => 0,
        'minimum_stock'    => 10,
    ]);

    (new MonitorStockMinimumsJob())->handle();

    Notification::assertNothingSent();
});

it('Job does NOT alert when minimum_stock is zero (no threshold set)', function (): void {
    Notification::fake();

    User::factory()->director()->create();
    $product = Product::factory()->create();

    CentralStock::create([
        'product_id'       => $product->id,
        'total_imported'   => 0,
        'total_dispatched' => 0,
        'minimum_stock'    => 0, // no threshold
    ]);

    (new MonitorStockMinimumsJob())->handle();

    Notification::assertNothingSent();
});

// ---------------------------------------------------------------------------
// Distributor stock alerts
// ---------------------------------------------------------------------------

it('Job dispatches notification to Director and Distributor when distributor stock is below minimum', function (): void {
    Notification::fake();

    $director    = User::factory()->director()->create();
    $distributor = User::factory()->distributor()->create();
    $product     = Product::factory()->create();

    DistributorStock::create([
        'distributor_id'      => $distributor->id,
        'product_id'          => $product->id,
        'total_received'      => 2,
        'total_redistributed' => 0,
        'reserved'            => 0,
        'available'           => 2,
        'minimum_stock'       => 5,
    ]);

    (new MonitorStockMinimumsJob())->handle();

    Notification::assertSentTo($director, StockBelowMinimumNotification::class);
    Notification::assertSentTo($distributor, StockBelowMinimumNotification::class);
});

// ---------------------------------------------------------------------------
// Seller stock alerts
// ---------------------------------------------------------------------------

it('Job dispatches notification to Director when seller stock is below minimum', function (): void {
    Notification::fake();

    $director = User::factory()->director()->create();
    $seller   = User::factory()->seller()->create();
    $product  = Product::factory()->create();

    SellerStock::create([
        'seller_id'      => $seller->id,
        'product_id'     => $product->id,
        'boxes'          => 1,
        'loose_units'    => 0,
        'reserved_boxes' => 0,
        'reserved_units' => 0,
        'minimum_stock'  => 5, // 1 available < 5 minimum
    ]);

    (new MonitorStockMinimumsJob())->handle();

    Notification::assertSentTo($director, StockBelowMinimumNotification::class);
});

// ---------------------------------------------------------------------------
// De-duplication via cache
// ---------------------------------------------------------------------------

it('Job does not re-send notification within 24h for the same product/entity', function (): void {
    Notification::fake();
    Cache::flush();

    $director = User::factory()->director()->create();
    $product  = Product::factory()->create();

    CentralStock::create([
        'product_id'       => $product->id,
        'total_imported'   => 1,
        'total_dispatched' => 0,
        'minimum_stock'    => 10,
    ]);

    // First run: alert sent + cache set
    (new MonitorStockMinimumsJob())->handle();
    Notification::assertSentToTimes($director, StockBelowMinimumNotification::class, 1);

    // Second run within 24h: cache hit → no additional notification
    (new MonitorStockMinimumsJob())->handle();
    Notification::assertSentToTimes($director, StockBelowMinimumNotification::class, 1);
});

it('Job re-sends notification after cache expires (simulated)', function (): void {
    Notification::fake();
    Cache::flush();

    $director = User::factory()->director()->create();
    $product  = Product::factory()->create();
    $cacheKey = "stock_alert:central:{$product->id}";

    CentralStock::create([
        'product_id'       => $product->id,
        'total_imported'   => 1,
        'total_dispatched' => 0,
        'minimum_stock'    => 10,
    ]);

    (new MonitorStockMinimumsJob())->handle();
    Notification::assertSentToTimes($director, StockBelowMinimumNotification::class, 1);

    // Simulate cache expiry
    Cache::forget($cacheKey);

    (new MonitorStockMinimumsJob())->handle();
    Notification::assertSentToTimes($director, StockBelowMinimumNotification::class, 2);
});

// ---------------------------------------------------------------------------
// Notification payload
// ---------------------------------------------------------------------------

it('StockBelowMinimumNotification carries correct payload', function (): void {
    Notification::fake();

    $director = User::factory()->director()->create();
    $product  = Product::factory()->create(['name' => 'Dermal Test']);

    CentralStock::create([
        'product_id'       => $product->id,
        'total_imported'   => 2,
        'total_dispatched' => 0,
        'minimum_stock'    => 10,
    ]);

    (new MonitorStockMinimumsJob())->handle();

    Notification::assertSentTo(
        $director,
        StockBelowMinimumNotification::class,
        function (StockBelowMinimumNotification $notification) use ($product): bool {
            return $notification->productId === $product->id
                && $notification->stockType === 'central'
                && $notification->available === 2
                && $notification->minimum === 10;
        }
    );
});
