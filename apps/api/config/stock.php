<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Lot Expiry Alert Window (days)
    |--------------------------------------------------------------------------
    |
    | LotExpiryAlertJob scans stock_lots for lots with expiry_date between
    | today and today + this many days. Directors are notified for each lot
    | in that window (§8.3, §16.11).
    |
    | Configurable by Director from the admin panel (Phase 16). The env var
    | allows ops to override without a deploy.
    |
    */
    'lot_expiry_alert_days' => (int) env('STOCK_LOT_EXPIRY_ALERT_DAYS', 30),
];
