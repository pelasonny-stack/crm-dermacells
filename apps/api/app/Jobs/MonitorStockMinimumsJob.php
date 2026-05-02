<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CentralStock;
use App\Models\DistributorStock;
use App\Models\SellerStock;
use App\Models\User;
use App\Notifications\StockBelowMinimumNotification;
use App\Enums\UserRole;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Hourly job that scans all stock tables for entries below their configured
 * minimum and dispatches StockBelowMinimumNotification to the relevant users.
 *
 * De-duplication: a cache key per entity+product prevents repeat notifications
 * within a 24-hour window (§8.3 — "no alert sent in last 24h").
 *
 * Queue: 'default'. The job is idempotent — running it twice in a window
 * will not double-notify because of the cache guard.
 *
 * @see routes/console.php — scheduled hourly
 */
class MonitorStockMinimumsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function handle(): void
    {
        $directors = User::where('role', UserRole::Director->value)
            ->where('is_active', true)
            ->get();

        $this->checkCentralStock($directors);
        $this->checkDistributorStock($directors);
        $this->checkSellerStock($directors);
    }

    // -------------------------------------------------------------------------
    // Central stock
    // -------------------------------------------------------------------------

    private function checkCentralStock(\Illuminate\Support\Collection $directors): void
    {
        $lowRows = CentralStock::with('product')
            ->belowMinimum()
            ->get();

        foreach ($lowRows as $row) {
            $cacheKey = "stock_alert:central:{$row->product_id}";

            if (Cache::has($cacheKey)) {
                continue;
            }

            $notification = new StockBelowMinimumNotification(
                stockType: 'central',
                productId: $row->product_id,
                productName: $row->product->name ?? 'Unknown',
                available: $row->available,
                minimum: $row->minimum_stock,
                entityId: null,
                entityName: 'Bodega Central',
            );

            foreach ($directors as $director) {
                $director->notify($notification);
            }

            Cache::put($cacheKey, true, now()->addHours(24));

            Log::info("StockMonitor: central stock alert for product {$row->product_id}");
        }
    }

    // -------------------------------------------------------------------------
    // Distributor stock
    // -------------------------------------------------------------------------

    private function checkDistributorStock(\Illuminate\Support\Collection $directors): void
    {
        $lowRows = DistributorStock::with(['product', 'distributor'])
            ->belowMinimum()
            ->get();

        foreach ($lowRows as $row) {
            $cacheKey = "stock_alert:distributor:{$row->distributor_id}:{$row->product_id}";

            if (Cache::has($cacheKey)) {
                continue;
            }

            $notification = new StockBelowMinimumNotification(
                stockType: 'distributor',
                productId: $row->product_id,
                productName: $row->product->name ?? 'Unknown',
                available: $row->available,
                minimum: $row->minimum_stock,
                entityId: $row->distributor_id,
                entityName: $row->distributor->full_name ?? 'Distribuidor',
            );

            // Notify all directors
            foreach ($directors as $director) {
                $director->notify($notification);
            }

            // Notify the distributor themselves
            if ($row->distributor !== null) {
                $row->distributor->notify($notification);
            }

            Cache::put($cacheKey, true, now()->addHours(24));

            Log::info("StockMonitor: distributor stock alert for {$row->distributor_id} product {$row->product_id}");
        }
    }

    // -------------------------------------------------------------------------
    // Seller stock
    // -------------------------------------------------------------------------

    private function checkSellerStock(\Illuminate\Support\Collection $directors): void
    {
        $lowRows = SellerStock::with(['product', 'seller'])
            ->belowMinimum()
            ->get();

        foreach ($lowRows as $row) {
            $cacheKey = "stock_alert:seller:{$row->seller_id}:{$row->product_id}";

            if (Cache::has($cacheKey)) {
                continue;
            }

            $notification = new StockBelowMinimumNotification(
                stockType: 'seller',
                productId: $row->product_id,
                productName: $row->product->name ?? 'Unknown',
                available: $row->availableBoxes(),
                minimum: $row->minimum_stock,
                entityId: $row->seller_id,
                entityName: $row->seller->full_name ?? 'Vendedor',
            );

            foreach ($directors as $director) {
                $director->notify($notification);
            }

            // Find the distributor of the seller's zone and notify them.
            // Phase 4 simplification: notify via seller-zone lookup if customers exist.
            $this->notifyDistributorForSeller($row->seller_id, $notification);

            Cache::put($cacheKey, true, now()->addHours(24));

            Log::info("StockMonitor: seller stock alert for {$row->seller_id} product {$row->product_id}");
        }
    }

    /**
     * Attempt to find and notify the distributor responsible for the seller's zone.
     * Requires the customers table (Phase 3). If it does not exist, silently skips.
     */
    private function notifyDistributorForSeller(string $sellerId, StockBelowMinimumNotification $notification): void
    {
        try {
            $distributorIds = \Illuminate\Support\Facades\DB::table('customers as c')
                ->join('zones as z', 'z.id', '=', 'c.zone_id')
                ->where('c.seller_id', $sellerId)
                ->whereNotNull('z.distributor_id')
                ->distinct()
                ->pluck('z.distributor_id');

            foreach ($distributorIds as $distributorId) {
                $distributor = User::find($distributorId);
                $distributor?->notify($notification);
            }
        } catch (\Exception) {
            // customers table may not exist yet (Phase 3 not deployed)
        }
    }
}
