<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment methods lookup table — Phase 7 (§7.1).
 *
 * Seeded in PaymentMethodSeeder with the five methods defined in spec:
 *   transfer_dermacells  — Transferencia a cuenta Dermacells
 *   transfer_distributor — Transferencia a cuenta del Distribuidor
 *   cash                 — Efectivo (destination auto-resolved)
 *   credit_card          — Tarjeta de crédito
 *   check                — Cheque
 *
 * Columns:
 *   requires_reference    — true for transfer_dermacells, transfer_distributor
 *   requires_installments — true for credit_card
 *   requires_check_fields — true for check (number, bank, due_date)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->text('code')->unique();
            $table->text('name');

            $table->boolean('requires_reference')->default(false);
            $table->boolean('requires_installments')->default(false);
            $table->boolean('requires_check_fields')->default(false);

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
