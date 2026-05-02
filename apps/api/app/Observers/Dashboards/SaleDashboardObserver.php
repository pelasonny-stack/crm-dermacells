<?php

declare(strict_types=1);

namespace App\Observers\Dashboards;

use App\Events\Dashboards\SaleDelivered;
use App\Models\Sale;

/**
 * Phase 14 observer on the Sale model.
 *
 * Fires SaleDelivered broadcast when a sale transitions to 'delivered'.
 * Does NOT rewrite Phase 5 models or actions — it only observes model
 * saved events and checks the status transition.
 *
 * Registered in AppServiceProvider::boot() alongside existing observers.
 */
final class SaleDashboardObserver
{
    public function updated(Sale $sale): void
    {
        if ($sale->wasChanged('status') && $sale->status === \App\Enums\SaleStatus::Delivered) {
            SaleDelivered::dispatch($sale);
        }
    }
}
