<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer account balances — Phase 7 (§7.4).
 *
 * ONE ROW PER CUSTOMER. Maintained as a running balance by PaymentObserver.
 *
 * DUAL CURRENCY DESIGN (§7.4)
 * ===========================
 * Two separate columns: balance_ars and balance_usd. Payments in ARS increment
 * balance_ars ONLY; payments in USD increment balance_usd ONLY. The conversion
 * to USD-equivalent for commission calculation happens at query time in
 * CommissionCalculatorService (Phase 9) — it is NEVER stored here.
 *
 * DERIVED STATE
 * =============
 * This table is recomputed from the payments table. It does NOT use the Auditable
 * trait because it is not a primary record — it is an optimised denormalised
 * balance cache. The source of truth is always the payments table.
 *
 * Only updated_at is tracked (no created_at) because the row is upserted.
 *
 * UPSERT PATTERN (AccountBalanceUpdater)
 * =======================================
 * INSERT ... ON CONFLICT (customer_id) DO UPDATE SET balance_{currency} = ..., updated_at = now()
 * This is executed inside the same DB::transaction as the Payment creation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_account_balances', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('customer_id')
                ->unique()
                ->constrained('customers');

            $table->decimal('balance_ars', 18, 4)->default(0);
            $table->decimal('balance_usd', 18, 4)->default(0);

            // Managed manually — no created_at, only updated_at to track last sync
            $table->timestampTz('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_account_balances');
    }
};
