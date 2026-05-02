<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inbound WhatsApp message whose sender phone matched no customer (Phase 12).
 *
 * PURPOSE
 * =======
 * When IngestWhatsappWebhookJob receives a message from a number that does not
 * match any customer.phone, it writes to this table instead of
 * whatsapp_threads / whatsapp_messages. A Director reviews and resolves via
 * the Filament UnmatchedWhatsappMessageResource triage queue.
 *
 * Possible resolutions:
 *  - "Assign to customer" action: create/update the WhatsappThread for that
 *    customer and migrate this message into whatsapp_messages.
 *  - Mark as reviewed without assignment (spam / wrong number).
 *
 * ENCRYPTION
 * ==========
 * Same at-rest strategy as WhatsappMessage — `encrypted` cast on `body`.
 *
 * NO RLS
 * ======
 * Director-only via application-layer AdminAccessGate. Postgres RLS is not
 * applied (see migration 2026_05_12_000003 comment).
 *
 * @property string               $id
 * @property string               $wa_phone
 * @property string               $body           (decrypted plaintext via cast)
 * @property string               $wa_message_id
 * @property string               $message_type
 * @property \Carbon\Carbon       $received_at
 * @property string|null          $reviewed_by
 * @property \Carbon\Carbon|null  $reviewed_at
 * @property \Carbon\Carbon|null  $created_at
 * @property \Carbon\Carbon|null  $updated_at
 * @property-read User|null       $reviewer
 */
class UnmatchedWhatsappMessage extends Model
{
    use HasUuids;

    protected $fillable = [
        'wa_phone',
        'body',
        'wa_message_id',
        'message_type',
        'received_at',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'body'        => 'encrypted',
            'received_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    public function isReviewed(): bool
    {
        return $this->reviewed_at !== null;
    }
}
