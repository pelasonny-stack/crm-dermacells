<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 — authorization_requests table.
 *
 * Stores every price-change or exchange-rate-change request submitted by a
 * Seller or Distributor and the resolution recorded by any Director.
 *
 * Key design decisions:
 * - UUID primary key (gen_random_uuid() server-side; HasUuids as fallback in tests).
 * - `sale_id` is nullable: a request may be raised before a sale exists
 *   (e.g., during price negotiation on a customer card).
 * - Numeric(18,4) mirrors the Money storage convention used across the CRM.
 * - `value_currency` CHAR(3) is nullable because exchange-rate requests carry
 *   a scalar rate (no currency code), while price-change requests do carry one.
 * - Three partial indexes: pending lookup, per-sale lookup, per-status; keeps
 *   the Director approval queue fast even with millions of rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authorization_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            $table->string('type', 32); // 'price_change' | 'exchange_rate_change'

            // The sale this request is attached to (optional at creation time)
            $table->uuid('sale_id')->nullable();
            $table->foreign('sale_id')
                ->references('id')->on('sales')
                ->nullOnDelete();

            // Who submitted the request
            $table->uuid('requested_by');
            $table->foreign('requested_by')
                ->references('id')->on('users')
                ->restrictOnDelete();

            // Value pair — NUMERIC(18,4) matches MoneyCast storage convention
            $table->decimal('current_value', 18, 4);
            $table->decimal('proposed_value', 18, 4);

            // Currency code for price changes; NULL for TC (exchange rate) changes
            $table->char('value_currency', 3)->nullable();

            $table->text('reason'); // mandatory per §13.2

            $table->string('status', 16)->default('pending'); // 'pending'|'approved'|'rejected'

            // Resolution fields — populated by the resolving Director
            $table->uuid('resolved_by')->nullable();
            $table->foreign('resolved_by')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->timestampTz('resolved_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // Optional expiry — allows auto-expiring stale requests (future cron)
            $table->timestampTz('expires_at')->nullable();

            $table->timestamps();
        });

        // Regular index: lookup all pending requests (Director approval queue)
        DB::statement(
            'CREATE INDEX idx_auth_requests_status ON authorization_requests (status)'
        );

        // Regular index: lookup all requests linked to a specific sale
        DB::statement(
            'CREATE INDEX idx_auth_requests_sale_id ON authorization_requests (sale_id) WHERE sale_id IS NOT NULL'
        );

        // Partial index: only pending rows — used by pendingForSale() guard
        DB::statement(
            "CREATE INDEX idx_auth_requests_pending ON authorization_requests (sale_id, status) WHERE status = 'pending'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('authorization_requests');
    }
};
