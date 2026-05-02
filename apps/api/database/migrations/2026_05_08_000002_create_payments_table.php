<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payments table — Phase 7 (§7.1 – §7.6).
 *
 * KEY DESIGN DECISIONS
 * ====================
 * 1. Amount stored as NUMERIC(18,4) + CHAR(3) currency — never BIGINT minor units,
 *    per PLAN.md decisions and MoneyCast pattern established in Phase 2.
 *
 * 2. exchange_rate_id nullable: ARS payments on ARS sales have no TC;
 *    USD payments on USD sales have no TC; ARS payments on USD sales carry the TC
 *    used at commission-calculation time.
 *
 * 3. cash_destination is an application-level enum enforced via a CHECK constraint.
 *    NULL for non-cash payment methods. Auto-resolved by CashDestinationResolver.
 *
 * 4. cash_destination_dist_id references users (the Distributor user), NULL when
 *    destination = 'dermacells'.
 *
 * 5. reversed / reversed_by / reversed_at / reversal_reason support §7.6 (Director
 *    only devolución de cobros). The reversed flag + reversal fields on the original
 *    payment row is the audit trail; no separate reversal row is needed.
 *
 * 6. recorded_by: who registered this payment (Seller, Distributor, or Director).
 *
 * INDEX STRATEGY
 * ==============
 *   - (customer_id, payment_date DESC) — account balance queries, seller cash-owed
 *   - (sale_id)                         — payment list per sale
 *   - (recorded_by)                     — seller's own payments / cash-owed report
 *   - Partial index on (reversed=false)  — most queries filter non-reversed only
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('sale_id')
                ->constrained('sales')
                ->cascadeOnDelete();

            $table->foreignUuid('customer_id')
                ->constrained('customers');

            $table->foreignUuid('payment_method_id')
                ->constrained('payment_methods');

            // Money compound columns (MoneyCast pattern from Phase 2)
            $table->decimal('amount_amount', 18, 4);
            $table->char('amount_currency', 3);

            // TC at payment time (null when payment and sale share the same currency)
            $table->foreignUuid('exchange_rate_id')
                ->nullable()
                ->constrained('exchange_rates');

            // Anticipos — counts in commission month of collection, not sale month
            $table->boolean('is_advance')->default(false);

            // Method-specific optional fields
            $table->text('reference')->nullable();     // transfers
            $table->integer('installments')->nullable(); // credit_card
            $table->text('check_number')->nullable();
            $table->text('check_bank')->nullable();
            $table->date('check_due_date')->nullable();

            // Cash destination (auto-resolved by CashDestinationResolver)
            $table->string('cash_destination', 20)->nullable();  // 'dermacells' | 'distributor'
            $table->foreignUuid('cash_destination_dist_id')
                ->nullable()
                ->constrained('users');

            $table->date('payment_date');

            // Reversal fields (§7.6 — Director only)
            $table->boolean('reversed')->default(false);
            $table->foreignUuid('reversed_by')
                ->nullable()
                ->constrained('users');
            $table->timestampTz('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();

            $table->foreignUuid('recorded_by')
                ->constrained('users');

            $table->timestamps();

            // --------------- CHECK CONSTRAINT ---------------
            // cash_destination must be one of the two valid values or NULL
        });

        DB::statement(
            "ALTER TABLE payments ADD CONSTRAINT chk_cash_destination CHECK (cash_destination IN ('dermacells', 'distributor') OR cash_destination IS NULL)"
        );

        // Performance indexes
        DB::statement('CREATE INDEX payments_customer_date_idx ON payments (customer_id, payment_date DESC)');
        DB::statement('CREATE INDEX payments_sale_id_idx ON payments (sale_id)');
        DB::statement('CREATE INDEX payments_recorded_by_idx ON payments (recorded_by)');

        // Partial index — most queries only care about non-reversed payments
        DB::statement("CREATE INDEX payments_active_idx ON payments (customer_id, amount_currency) WHERE reversed = false");
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
