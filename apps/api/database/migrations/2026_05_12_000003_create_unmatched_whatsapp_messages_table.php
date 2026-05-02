<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the unmatched_whatsapp_messages table (Phase 12 — §17).
 *
 * PURPOSE
 * =======
 * Inbound messages whose wa_phone does not match any customer.phone row
 * land here instead of whatsapp_messages. A Director triage queue in
 * Filament (UnmatchedWhatsappMessageResource) allows manual assignment
 * of each unmatched message to a customer — after which the row can be
 * promoted to a proper whatsapp_thread + whatsapp_message pair.
 *
 * ENCRYPTION
 * ==========
 * Same at-rest encryption strategy as whatsapp_messages.body.
 *
 * NO RLS
 * ======
 * This table is Director-only — the Filament triage queue is behind
 * the admin gate (Director role). No Seller or Distributor has access,
 * so Postgres RLS is not required (access is fully application-controlled).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unmatched_whatsapp_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // E.164 normalised phone (no '+', no spaces) of the sender.
            $table->text('wa_phone');

            // AES-256-GCM encrypted message body (see WhatsappMessage for rationale).
            $table->text('body');

            // WAMID from Meta — used for dedup if Meta retries.
            $table->text('wa_message_id')->unique();

            // Meta message type tag ('text', 'image', etc.).
            $table->text('message_type')->default('text');

            // When the message was received from Meta (extracted from payload timestamp).
            $table->timestampTz('received_at');

            // Director who reviewed / triaged this entry (nullable until actioned).
            $table->foreignUuid('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestampTz('reviewed_at')->nullable();

            $table->timestampsTz();
        });

        Schema::table('unmatched_whatsapp_messages', function (Blueprint $table): void {
            $table->index('wa_phone', 'unmatched_wa_phone_idx');
            $table->index('received_at', 'unmatched_received_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unmatched_whatsapp_messages');
    }
};
