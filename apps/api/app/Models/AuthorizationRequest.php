<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Enums\AuthorizationType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * AuthorizationRequest — tracks price / TC modification requests (§13).
 *
 * A Seller or Distributor creates a request whenever they want to deviate from
 * the system-suggested price or exchange rate. Any Director may resolve it.
 * While pending, the linked sale cannot be confirmed (§13.2).
 *
 * Status state machine:
 *   pending → approved   (Director calls AuthorizationService::approve)
 *   pending → rejected   (Director calls AuthorizationService::reject)
 *   Final states: approved / rejected (immutable after resolution)
 *
 * @property string                  $id
 * @property AuthorizationType       $type
 * @property string|null             $sale_id
 * @property string                  $requested_by
 * @property string                  $current_value
 * @property string                  $proposed_value
 * @property string|null             $value_currency
 * @property string                  $reason
 * @property string                  $status
 * @property string|null             $resolved_by
 * @property Carbon|null             $resolved_at
 * @property string|null             $rejection_reason
 * @property Carbon|null             $expires_at
 * @property Carbon|null             $created_at
 * @property Carbon|null             $updated_at
 *
 * @property-read User               $requester
 * @property-read User|null          $resolver
 * @property-read Sale|null          $sale
 */
class AuthorizationRequest extends Model
{
    use Auditable, HasUuids;

    protected $fillable = [
        'type',
        'sale_id',
        'requested_by',
        'current_value',
        'proposed_value',
        'value_currency',
        'reason',
        'status',
        'resolved_by',
        'resolved_at',
        'rejection_reason',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'type'        => AuthorizationType::class,
            'resolved_at' => 'datetime',
            'expires_at'  => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** @return BelongsTo<User, AuthorizationRequest> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, AuthorizationRequest> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** @return BelongsTo<Sale, AuthorizationRequest> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    // -------------------------------------------------------------------------
    // Status helpers
    // -------------------------------------------------------------------------

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }
}
