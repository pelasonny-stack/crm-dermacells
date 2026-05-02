<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the whatsapp_threads table (Phase 12 — §17).
 *
 * One thread groups all messages exchanged with a single WhatsApp contact.
 * In the typical CRM flow a customer has exactly one phone and therefore one
 * thread, but the schema allows for future multi-number scenarios.
 *
 * customer_id is nullable so that inbound messages from unrecognised numbers
 * can be promoted to a proper thread once the Director triages them and links
 * them to a customer record.
 *
 * Timestamps:
 *   last_message_at   — latest message in either direction
 *   last_inbound_at   — most recent message received from the contact
 *   last_outbound_at  — most recent message sent to the contact
 *
 * These are denormalised for O(1) "last activity" sorting in dashboards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_threads', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Nullable FK — unmatched threads have no customer until triaged.
            $table->foreignUuid('customer_id')
                ->nullable()
                ->constrained('customers')
                ->nullOnDelete();

            // The WhatsApp E.164 phone number of the contact (e.g. "5491112345678").
            // Stored normalised (no +, no spaces) by PhoneNormalizer.
            $table->text('wa_phone');

            // WhatsApp contact ID as returned by the Cloud API (may differ from phone).
            $table->text('wa_contact_id')->nullable();

            // Denormalised timestamps for efficient dashboard queries.
            $table->timestampTz('last_message_at')->nullable();
            $table->timestampTz('last_inbound_at')->nullable();
            $table->timestampTz('last_outbound_at')->nullable();

            $table->timestampsTz();
        });

        // Lookup by customer (customer ficha tab — most common access pattern).
        Schema::table('whatsapp_threads', function (Blueprint $table): void {
            $table->index('customer_id', 'wa_threads_customer_id_idx');
            // Lookup by phone for match-or-create in IngestWhatsappWebhookJob.
            $table->index('wa_phone', 'wa_threads_wa_phone_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_threads');
    }
};
