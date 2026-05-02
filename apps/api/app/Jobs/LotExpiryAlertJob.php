<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\UserRole;
use App\Models\StockLot;
use App\Models\User;
use App\Notifications\LotExpiringSoonNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Daily job that scans stock_lots for entries expiring within the configured
 * alert window and notifies all Directors.
 *
 * Alert window: config('stock.lot_expiry_alert_days', 30).
 *
 * De-duplication: cache key per lot prevents re-alerting on the same lot
 * within 24 hours (the job runs once daily so this prevents edge cases where
 * the job is run manually or retried).
 *
 * @see routes/console.php — scheduled daily at 06:30 ART
 */
class LotExpiryAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 120;

    public function handle(): void
    {
        $alertDays = (int) config('stock.lot_expiry_alert_days', 30);

        $expiringSoon = StockLot::with('product')
            ->expiringWithin($alertDays)
            ->get();

        if ($expiringSoon->isEmpty()) {
            return;
        }

        $directors = User::where('role', UserRole::Director->value)
            ->where('is_active', true)
            ->get();

        foreach ($expiringSoon as $lot) {
            $cacheKey = "lot_expiry_alert:{$lot->id}";

            if (Cache::has($cacheKey)) {
                continue;
            }

            $notification = new LotExpiringSoonNotification(
                lotId: $lot->id,
                lotNumber: $lot->lot_number,
                productId: $lot->product_id,
                productName: $lot->product->name ?? 'Unknown',
                expiryDate: $lot->expiry_date->toDateString(),
                daysUntilExpiry: (int) now()->diffInDays($lot->expiry_date, false),
                quantityBoxes: $lot->quantity_boxes,
            );

            foreach ($directors as $director) {
                $director->notify($notification);
            }

            Cache::put($cacheKey, true, now()->addHours(24));

            Log::info("LotExpiry: alert sent for lot {$lot->id} expiring {$lot->expiry_date->toDateString()}");
        }
    }
}
