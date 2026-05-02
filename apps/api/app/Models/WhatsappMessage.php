<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Individual WhatsApp message within a thread (Phase 12 — §17).
 *
 * APPEND-ONLY
 * ===========
 * Messages are never updated or deleted after insertion. The UNIQUE constraint
 * on wa_message_id ensures Meta webhook retries don't create duplicates.
 * Auditable is intentionally NOT applied — the append-only constraint
 * guarantees immutability more reliably than an audit observer.
 *
 * ENCRYPTION
 * ==========
 * The `body` attribute uses Laravel's built-in `encrypted` cast, which applies
 * AES-256-GCM encryption via the Crypt facade using APP_KEY. The raw column
 * value is the ciphertext. Eloquent automatically encrypts on write and
 * decrypts on read — no application code changes needed.
 *
 * @property string               $id
 * @property string               $thread_id
 * @property string               $wa_message_id
 * @property string               $direction       'inbound' | 'outbound'
 * @property string               $body            (decrypted plaintext via cast)
 * @property string|null          $media_url
 * @property string               $message_type
 * @property \Carbon\Carbon       $sent_at
 * @property \Carbon\Carbon       $synced_at
 * @property-read WhatsappThread  $thread
 */
class WhatsappMessage extends Model
{
    use HasUuids;

    /**
     * No updated_at — this model is append-only.
     * created_at is mapped to synced_at semantically but we keep Eloquent's
     * standard timestamp disabled so the schema owns the DEFAULT now().
     */
    public $timestamps = false;

    protected $fillable = [
        'thread_id',
        'wa_message_id',
        'direction',
        'body',
        'media_url',
        'message_type',
        'sent_at',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            // AES-256-GCM encryption at rest via Laravel Crypt facade.
            'body'       => 'encrypted',
            'sent_at'    => 'datetime',
            'synced_at'  => 'datetime',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    public function thread(): BelongsTo
    {
        return $this->belongsTo(WhatsappThread::class, 'thread_id');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    public function isInbound(): bool
    {
        return $this->direction === 'inbound';
    }

    public function isOutbound(): bool
    {
        return $this->direction === 'outbound';
    }
}
