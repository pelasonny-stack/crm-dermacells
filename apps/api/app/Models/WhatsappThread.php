<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WhatsApp conversation thread (Phase 12 — §17).
 *
 * One thread per (customer, wa_phone) pair. In practice, a customer has one
 * phone and therefore one thread. The customer_id is nullable to handle the
 * case where an inbound message arrives from a number not yet linked to any
 * customer — that edge is handled by UnmatchedWhatsappMessage instead.
 *
 * AUDIT
 * =====
 * Threads are auditable (customer linkage changes, reviewed status transitions)
 * but messages within the thread are append-only and not individually audited —
 * they carry their own wa_message_id dedup guarantee.
 *
 * @property string                    $id
 * @property string|null               $customer_id
 * @property string                    $wa_phone
 * @property string|null               $wa_contact_id
 * @property \Carbon\Carbon|null       $last_message_at
 * @property \Carbon\Carbon|null       $last_inbound_at
 * @property \Carbon\Carbon|null       $last_outbound_at
 * @property \Carbon\Carbon|null       $created_at
 * @property \Carbon\Carbon|null       $updated_at
 * @property-read Customer|null        $customer
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WhatsappMessage> $messages
 */
class WhatsappThread extends Model
{
    use Auditable, HasUuids;

    protected $fillable = [
        'customer_id',
        'wa_phone',
        'wa_contact_id',
        'last_message_at',
        'last_inbound_at',
        'last_outbound_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at'  => 'datetime',
            'last_inbound_at'  => 'datetime',
            'last_outbound_at' => 'datetime',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsappMessage::class, 'thread_id')
            ->orderBy('sent_at', 'desc');
    }
}
