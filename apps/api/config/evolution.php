<?php

declare(strict_types=1);

/**
 * Phase 10 — Evolution Engine + Alerting configuration.
 *
 * All thresholds are configurable via environment variables so the Director
 * can tune them from Filament (Phase 16 §16.9) by updating .env or Secrets Manager
 * without a code deploy.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Próximo a vencer ciclo — días de anticipación (§10.4)
    |--------------------------------------------------------------------------
    |
    | How many days before the expected next purchase date to fire the
    | "cycle_due_soon" alert to the Seller.
    |
    */
    'next_cycle_alert_days_before' => (int) env('EVO_NEXT_CYCLE_DAYS', 5),

    /*
    |--------------------------------------------------------------------------
    | Primera compra sin recompra — días de espera (§10.4)
    |--------------------------------------------------------------------------
    |
    | How many days after a customer's first purchase before firing the
    | "first_purchase_no_reorder" alert if no second purchase has been made.
    |
    */
    'first_purchase_no_reorder_days' => (int) env('EVO_FIRST_REORDER_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Zona en riesgo — porcentaje de clientes inactivos (§10.4)
    |--------------------------------------------------------------------------
    |
    | The percentage of inactive customers in a zone above which the
    | "zone_at_risk" alert fires to the Distributor + all Directors.
    |
    */
    'zone_inactive_threshold_pct' => (float) env('EVO_ZONE_RISK_PCT', 30),

    /*
    |--------------------------------------------------------------------------
    | Multiplicador de inactividad (§10.2)
    |--------------------------------------------------------------------------
    |
    | A customer is classified as "inactive" when:
    |   days_since_last_purchase > frequency_expected * inactive_multiplier
    |
    | Default: 2.0 (double the expected frequency).
    |
    */
    'inactive_multiplier' => 2.0,

    /*
    |--------------------------------------------------------------------------
    | Banda de estabilidad (§10.2)
    |--------------------------------------------------------------------------
    |
    | The ±% band around the average interval within which a customer is
    | classified as "stable". Outside the band: increasing or decreasing.
    |
    | Default: 0.20 (±20%).
    |
    */
    'stable_band_pct' => 0.20,

];
