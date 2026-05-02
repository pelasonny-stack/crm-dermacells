<?php

declare(strict_types=1);

namespace App\Observers\Dashboards;

use App\Events\Dashboards\AlertCreated;
use App\Models\Alert;

/**
 * Phase 14 observer on the Alert model.
 *
 * Fires AlertCreated broadcast when a new alert row is persisted so the
 * target user's dashboard can refresh the "today alerts" section in real-time.
 *
 * Phase 10 AlertDispatcher already persists the Alert and fires Notifications.
 * This observer adds the Reverb broadcast layer without touching AlertDispatcher.
 */
final class AlertDashboardObserver
{
    public function created(Alert $alert): void
    {
        AlertCreated::dispatch($alert);
    }
}
