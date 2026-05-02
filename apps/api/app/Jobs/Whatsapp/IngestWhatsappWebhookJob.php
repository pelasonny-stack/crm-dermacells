<?php

declare(strict_types=1);

namespace App\Jobs\Whatsapp;

use App\Models\Customer;
use App\Models\UnmatchedWhatsappMessage;
use App\Models\WhatsappMessage;
use App\Models\WhatsappThread;
use App\Support\PhoneNormalizer;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Processes a raw Meta Cloud API webhook payload (Phase 12 — §17).
 *
 * PAYLOAD STRUCTURE
 * =================
 * Meta delivers a nested structure:
 *   entry[].changes[].value.messages[]
 *
 * Each message in messages[] contains:
 *   id        — WAMID (unique message ID)
 *   from      — sender E.164 phone (no "+")
 *   timestamp — Unix timestamp (string)
 *   type      — 'text', 'image', 'document', etc.
 *   text.body — present when type='text'
 *
 * STATUS MESSAGES
 * ===============
 * Meta also sends status updates (delivered, read) in value.statuses[].
 * Phase 12 MVP ignores these — we only process value.messages[].
 *
 * DEDUPLICATION
 * =============
 * The UNIQUE constraint on whatsapp_messages.wa_message_id is the dedup
 * boundary. If Meta re-delivers an event, the DB INSERT will violate the
 * constraint. We catch that silently and skip without failing the job.
 *
 * QUEUE
 * =====
 * Runs on the 'default' Horizon queue. Retries 3 times with backoff [10,30,90]s.
 * The job is idempotent — safe to re-run after transient failures.
 */
class IngestWhatsappWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 90];

    /**
     * @param array<string, mixed> $payload  Raw decoded JSON from Meta webhook.
     */
    public function __construct(
        private readonly array $payload,
    ) {}

    public function handle(): void
    {
        $entries = $this->payload['entry'] ?? [];

        foreach ($entries as $entry) {
            $changes = $entry['changes'] ?? [];

            foreach ($changes as $change) {
                $value    = $change['value'] ?? [];
                $messages = $value['messages'] ?? [];

                foreach ($messages as $message) {
                    $this->processMessage($message);
                }
            }
        }
    }

    /**
     * Process a single message object from the Meta payload.
     *
     * @param array<string, mixed> $message
     */
    private function processMessage(array $message): void
    {
        $waMessageId = $message['id'] ?? null;
        $fromPhone   = $message['from'] ?? null;
        $timestamp   = $message['timestamp'] ?? null;
        $type        = $message['type'] ?? 'text';
        $body        = $message['text']['body'] ?? '';

        if (! $waMessageId || ! $fromPhone) {
            Log::warning('whatsapp.ingest: skipping message with missing id or from', [
                'message' => $message,
            ]);

            return;
        }

        $normalizedPhone = PhoneNormalizer::normalize((string) $fromPhone);
        $sentAt          = $timestamp
            ? Carbon::createFromTimestamp((int) $timestamp)
            : now();

        Log::info('whatsapp.ingest: processing message', [
            'wa_message_id' => $waMessageId,
            'from_phone'    => $normalizedPhone,
            'type'          => $type,
        ]);

        DB::transaction(function () use ($waMessageId, $normalizedPhone, $sentAt, $type, $body): void {
            // Look up customer by normalised phone — scan all customers.
            // For larger datasets this could be indexed; the customers table
            // has phone as a plain text column — a functional index on
            // regexp_replace(phone, '\D', '', 'g') would be ideal (Phase 17 optimization).
            $customer = Customer::all()->first(
                fn (Customer $c) => PhoneNormalizer::equal($c->phone, $normalizedPhone)
            );

            if ($customer !== null) {
                $this->persistToThread($customer, $normalizedPhone, $waMessageId, $body, $type, $sentAt);
            } else {
                $this->persistUnmatched($normalizedPhone, $waMessageId, $body, $type, $sentAt);
            }
        });
    }

    /**
     * Upsert a thread and insert the message for a matched customer.
     */
    private function persistToThread(
        Customer $customer,
        string $normalizedPhone,
        string $waMessageId,
        string $body,
        string $type,
        Carbon $sentAt,
    ): void {
        // Upsert the thread by wa_phone (one thread per phone per customer).
        $thread = WhatsappThread::firstOrCreate(
            ['wa_phone' => $normalizedPhone],
            ['customer_id' => $customer->id],
        );

        // Ensure customer_id is linked if this was a previously unmatched thread.
        if ($thread->customer_id === null) {
            $thread->customer_id = $customer->id;
        }

        // Update denormalised last-activity timestamps.
        $thread->last_message_at  = $sentAt;
        $thread->last_inbound_at  = $sentAt;
        $thread->save();

        // Insert the message — ignore duplicate wa_message_id via unique constraint.
        try {
            WhatsappMessage::create([
                'thread_id'     => $thread->id,
                'wa_message_id' => $waMessageId,
                'direction'     => 'inbound',
                'body'          => $body !== '' ? $body : '[non-text message]',
                'message_type'  => $type,
                'sent_at'       => $sentAt,
                'synced_at'     => now(),
            ]);

            Log::info('whatsapp.ingest: message persisted', [
                'thread_id'      => $thread->id,
                'customer_id'    => $customer->id,
                'wa_message_id'  => $waMessageId,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            Log::info('whatsapp.ingest: duplicate wa_message_id skipped', [
                'wa_message_id' => $waMessageId,
            ]);
        }
    }

    /**
     * Persist to the unmatched queue when no customer matches the phone.
     */
    private function persistUnmatched(
        string $normalizedPhone,
        string $waMessageId,
        string $body,
        string $type,
        Carbon $sentAt,
    ): void {
        try {
            UnmatchedWhatsappMessage::create([
                'wa_phone'      => $normalizedPhone,
                'body'          => $body !== '' ? $body : '[non-text message]',
                'wa_message_id' => $waMessageId,
                'message_type'  => $type,
                'received_at'   => $sentAt,
            ]);

            Log::info('whatsapp.ingest: unmatched message stored', [
                'wa_phone'      => $normalizedPhone,
                'wa_message_id' => $waMessageId,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            Log::info('whatsapp.ingest: duplicate unmatched wa_message_id skipped', [
                'wa_message_id' => $waMessageId,
            ]);
        }
    }
}
