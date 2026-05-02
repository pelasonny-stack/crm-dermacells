<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the whatsapp_messages table (Phase 12 — §17).
 *
 * ENCRYPTION
 * ==========
 * The `body` column stores AES-256-GCM ciphertext produced by Laravel's
 * built-in `Crypt` facade (APP_KEY). The Eloquent model applies the
 * `encrypted` cast so that application code always sees plaintext.
 * Direct SQL queries — e.g. from a compromised read replica — will see
 * only the ciphertext.
 *
 * APPEND-ONLY
 * ===========
 * Messages are never updated or deleted. The UNIQUE constraint on
 * wa_message_id is the deduplication boundary: re-delivery of the same
 * Meta webhook event is silently ignored via INSERT ... ON CONFLICT DO NOTHING.
 *
 * INDEX STRATEGY
 * ==============
 * (thread_id, sent_at DESC) covers the primary access pattern: paginated
 * message history for a given thread in reverse-chronological order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('thread_id')
                ->constrained('whatsapp_threads')
                ->cascadeOnDelete();

            // Unique message ID assigned by Meta Cloud API (WAMID).
            // Used for deduplication — retry-safe insertion.
            $table->text('wa_message_id')->unique();

            // 'inbound' = received from customer; 'outbound' = sent by CRM.
            $table->enum('direction', ['inbound', 'outbound']);

            // Encrypted ciphertext. Eloquent cast 'encrypted' decrypts on access.
            $table->text('body');

            // Optional — populated when message_type is 'image', 'document', etc.
            $table->text('media_url')->nullable();

            // Meta message type: 'text', 'image', 'document', 'audio', 'video', etc.
            $table->text('message_type')->default('text');

            // Timestamp from the Meta payload (when the message was actually sent/received).
            $table->timestampTz('sent_at');

            // When this row was inserted into the local DB.
            $table->timestampTz('synced_at')->useCurrent();
        });

        Schema::table('whatsapp_messages', function (Blueprint $table): void {
            // Primary access pattern: paginated history for a thread.
            $table->index(['thread_id', 'sent_at'], 'wa_messages_thread_sent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
