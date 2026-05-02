<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer credit balances (saldo a favor) — Phase 7 (§7.5).
 *
 * ORIGIN CASES
 * ============
 * 1. Sale cancelled WITH payments AND without invoice:
 *    origin_sale_id = cancelled sale, origin_credit_note_id = NULL
 * 2. Sale cancelled WITH payments AND WITH invoice (Phase 6 NC flow):
 *    origin_sale_id = cancelled sale, origin_credit_note_id = the NC id
 * 3. Partial return without invoice (§5.8):
 *    origin_sale_id = parent sale, origin_credit_note_id = NULL
 *
 * APPLICATION
 * ===========
 * Each row represents a discrete credit balance available to the customer.
 * When manually applied to a sale by a Director (§7.5), applied_to_sale_id and
 * applied_at are set. Unapplied rows have applied_to_sale_id = NULL.
 *
 * PARTIAL INDEX
 * =============
 * A partial index WHERE applied_to_sale_id IS NULL makes the "available balance"
 * query (SUM of unapplied balances per customer) efficient at scale.
 *
 * CURRENCY
 * ========
 * Follows the same pattern as payments: amount stored as NUMERIC(18,4) + CHAR(3).
 * The currency of the credit balance matches the currency of the original payment.
 * A customer may have separate ARS and USD credit balance rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_credit_balances', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('customer_id')
                ->constrained('customers');

            // Amount + currency (MoneyCast compound pattern)
            $table->decimal('amount_amount', 18, 4);
            $table->char('amount_currency', 3);

            // Origin: can come from a cancelled sale, a credit note, or a partial return
            $table->foreignUuid('origin_sale_id')
                ->nullable()
                ->constrained('sales');

            // Phase 6 will populate this when NC is issued via Xubio
            // Using a plain uuid column (not constrained) because credit_notes table
            // does not exist until Phase 6. The FK will be added via Phase 6 migration.
            $table->uuid('origin_credit_note_id')->nullable();

            // When applied to a future sale
            $table->foreignUuid('applied_to_sale_id')
                ->nullable()
                ->constrained('sales');

            $table->timestampTz('applied_at')->nullable();

            $table->timestamps();
        });

        // Partial index: fast "available credit" lookups (unapplied rows)
        DB::statement(
            'CREATE INDEX ccb_unapplied_idx ON customer_credit_balances (customer_id, amount_currency) WHERE applied_to_sale_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credit_balances');
    }
};
