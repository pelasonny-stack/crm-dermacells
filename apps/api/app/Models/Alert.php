<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alert — append-only row representing one alert dispatched to one target user.
 *
 * Alerts are created by AlertDispatcher. They are NOT auditable because
 * they are append-only operational records (the table itself is the log).
 *
 * Fan-out: when an event affects multiple recipients (e.g. "client inactive"
 * notifies Seller + Distributor), AlertDispatcher inserts one row per target
 * and fires one Notification per target.
 *
 * Idempotency: the dispatcher checks (alert_type, target_user_id, reference_entity_id)
 * within a 24h window before inserting to prevent duplicate alerts.
 *
 * @property string              $id
 * @property string              $alert_type
 * @property string              $target_user_id
 * @property string|null         $reference_entity_type
 * @property string|null         $reference_entity_id
 * @property array|null          $payload_json
 * @property string              $severity           ('info'|'warning'|'critical')
 * @property bool                $delivered
 * @property \Carbon\Carbon|null $delivered_at
 * @property \Carbon\Carbon|null $read_at
 * @property \Carbon\Carbon      $created_at
 *
 * @property-read User           $targetUser
 */
class Alert extends Model
{
    /** @use HasFactory<AlertFactory> */
    use HasFactory, HasUuids;

    // Append-only — we never update created_at, and updated_at is not needed.
    const UPDATED_AT = null;

    protected $table = 'alerts';

    protected $fillable = [
        'alert_type',
        'target_user_id',
        'reference_entity_type',
        'reference_entity_id',
        'payload_json',
        'severity',
        'delivered',
        'delivered_at',
        'read_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'delivered'    => 'boolean',
            'delivered_at' => 'datetime',
            'read_at'      => 'datetime',
            'created_at'   => 'datetime',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /** @return BelongsTo<User, Alert> */
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function markRead(): void
    {
        if ($this->read_at === null) {
            $this->update(['read_at' => now()]);
        }
    }

    public function markDelivered(): void
    {
        if (! $this->delivered) {
            $this->update([
                'delivered'    => true,
                'delivered_at' => now(),
            ]);
        }
    }

    // =========================================================================
    // Query scopes
    // =========================================================================

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     */
    public function scopeUnread(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereNull('read_at');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     */
    public function scopeUndelivered(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('delivered', false);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     */
    public function scopeForUser(\Illuminate\Database\Eloquent\Builder $query, string $userId): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('target_user_id', $userId);
    }
}
