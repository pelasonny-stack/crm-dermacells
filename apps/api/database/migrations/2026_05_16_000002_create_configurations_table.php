<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the configurations table — §16.9.
 *
 * Generic key/value store for system-wide Director-editable settings.
 * All values stored as TEXT to avoid type coupling; the application layer
 * casts them. Typical keys:
 *
 *   stock_minimum_default          — global default stock minimum (integer)
 *   alert_unusual_sale_threshold   — USD amount for venta inusual alert
 *   alert_draft_inactivity_days    — days before borrador inactivity alert
 *   lot_expiry_alert_days          — days before lot expiry alert
 *   zone_risk_threshold_pct        — % inactive clients to trigger zona riesgo
 *   first_purchase_no_reorder_days — days after 1st purchase before alert
 *
 * REVOKE UPDATE/DELETE enforced at DB role level for immutability of audit
 * entries is NOT needed here — configurations is mutable by design. The
 * audit trail is kept via AuditObserver on the Configuration model.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE configurations (
                id          UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                key         TEXT        NOT NULL,
                value       TEXT        NULL,
                description TEXT        NULL,
                group_name  TEXT        NOT NULL DEFAULT 'general',
                created_by  UUID        NULL
                            REFERENCES users (id) ON DELETE SET NULL,
                updated_by  UUID        NULL
                            REFERENCES users (id) ON DELETE SET NULL,
                created_at  TIMESTAMPTZ NULL,
                updated_at  TIMESTAMPTZ NULL,

                CONSTRAINT uq_configurations_key UNIQUE (key)
            )
        SQL);

        DB::statement('CREATE INDEX idx_configurations_group ON configurations (group_name)');

        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON configurations TO app_role');
        DB::statement('GRANT SELECT ON configurations TO report_role');
        DB::statement('GRANT SELECT ON configurations TO worker_role');

        // Seed default configuration values
        DB::statement(<<<'SQL'
            INSERT INTO configurations (id, key, value, description, group_name) VALUES
              (gen_random_uuid(), 'stock_minimum_default',          '5',  'Stock mínimo por defecto para nuevos Vendedores/Distribuidores',          'stock'),
              (gen_random_uuid(), 'alert_unusual_sale_threshold',   '5000', 'Monto USD a partir del cual una venta se considera inusual',             'alerts'),
              (gen_random_uuid(), 'alert_draft_inactivity_days',    '30', 'Días sin actividad en Borrador antes de disparar alerta',                  'alerts'),
              (gen_random_uuid(), 'lot_expiry_alert_days',          '30', 'Días antes del vencimiento de lote para disparar alerta al Director',      'stock'),
              (gen_random_uuid(), 'zone_risk_threshold_pct',        '30', 'Porcentaje de clientes inactivos en una zona para alerta de zona en riesgo', 'alerts'),
              (gen_random_uuid(), 'first_purchase_no_reorder_days', '60', 'Días desde primera compra sin recompra para disparar alerta de seguimiento',  'alerts'),
              (gen_random_uuid(), 'purchase_frequency_default_a',   '30', 'Frecuencia de compra esperada por defecto para categoría A (días)',         'evolution'),
              (gen_random_uuid(), 'purchase_frequency_default_b',   '45', 'Frecuencia de compra esperada por defecto para categoría B (días)',         'evolution'),
              (gen_random_uuid(), 'purchase_frequency_default_c',   '60', 'Frecuencia de compra esperada por defecto para categoría C (días)',         'evolution'),
              (gen_random_uuid(), 'purchase_frequency_default_d',   '30', 'Frecuencia de compra esperada por defecto para categoría D (días)',         'evolution'),
              (gen_random_uuid(), 'evolution_alert_advance_days',   '5',  'Días de anticipación para alerta de próximo vencimiento de ciclo',          'evolution')
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS configurations CASCADE');
    }
};
