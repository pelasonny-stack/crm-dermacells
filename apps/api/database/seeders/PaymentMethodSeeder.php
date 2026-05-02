<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the five payment methods defined in §7.1.
 *
 * Idempotent: uses INSERT ... ON CONFLICT DO NOTHING so it is safe to re-run
 * in any environment, including after rollback + re-migrate.
 *
 * Methods:
 *   transfer_dermacells  — Transferencia a cuenta Dermacells (needs reference)
 *   transfer_distributor — Transferencia a cuenta del Distribuidor (needs reference)
 *   cash                 — Efectivo (destination auto-resolved per §7.2)
 *   credit_card          — Tarjeta de crédito (needs installments)
 *   check                — Cheque (needs check_number, check_bank, check_due_date)
 */
class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            [
                'code'                  => 'transfer_dermacells',
                'name'                  => 'Transferencia a cuenta Dermacells',
                'requires_reference'    => true,
                'requires_installments' => false,
                'requires_check_fields' => false,
                'is_active'             => true,
            ],
            [
                'code'                  => 'transfer_distributor',
                'name'                  => 'Transferencia a cuenta del Distribuidor',
                'requires_reference'    => true,
                'requires_installments' => false,
                'requires_check_fields' => false,
                'is_active'             => true,
            ],
            [
                'code'                  => 'cash',
                'name'                  => 'Efectivo',
                'requires_reference'    => false,
                'requires_installments' => false,
                'requires_check_fields' => false,
                'is_active'             => true,
            ],
            [
                'code'                  => 'credit_card',
                'name'                  => 'Tarjeta de crédito',
                'requires_reference'    => false,
                'requires_installments' => true,
                'requires_check_fields' => false,
                'is_active'             => true,
            ],
            [
                'code'                  => 'check',
                'name'                  => 'Cheque',
                'requires_reference'    => false,
                'requires_installments' => false,
                'requires_check_fields' => true,
                'is_active'             => true,
            ],
        ];

        $now = now();

        foreach ($methods as $method) {
            DB::table('payment_methods')->insertOrIgnore([
                'id'                    => Str::uuid()->toString(),
                'code'                  => $method['code'],
                'name'                  => $method['name'],
                'requires_reference'    => $method['requires_reference'],
                'requires_installments' => $method['requires_installments'],
                'requires_check_fields' => $method['requires_check_fields'],
                'is_active'             => $method['is_active'],
                'created_at'            => $now,
                'updated_at'            => $now,
            ]);
        }
    }
}
