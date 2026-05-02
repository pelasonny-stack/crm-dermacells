<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * IdempotencyKey — Stripe-style deduplication record (Phase 0 spec).
 *
 * Created by IdempotencyKey middleware on first request, then updated
 * with response_body and response_status after the action completes.
 * Subsequent requests with the same (user_id, key) and matching body hash
 * receive the cached response without re-executing the action.
 *
 * No HasUuids trait: the PK is a BIGSERIAL; the idempotency key itself
 * is stored in the `key` column as a UUID string.
 *
 * No Auditable trait: this table is infrastructure, not business data.
 *
 * @property int         $id
 * @property string      $user_id
 * @property string      $key         UUID v4 string from request header
 * @property string|null $request_path
 * @property string|null $request_body_hash  SHA-256 hex (64 chars)
 * @property string|null $response_body
 * @property int|null    $response_status
 * @property Carbon|null $created_at
 * @property Carbon|null $expires_at
 *
 * @property-read User $user
 */
class IdempotencyKey extends Model
{
    /**
     * This model has no updated_at column.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'key',
        'request_path',
        'request_body_hash',
        'response_body',
        'response_status',
        'created_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'expires_at'      => 'datetime',
            'created_at'      => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** @return BelongsTo<User, IdempotencyKey> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function hasResponse(): bool
    {
        return $this->response_body !== null && $this->response_status !== null;
    }
}
