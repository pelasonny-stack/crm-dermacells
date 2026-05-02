<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ScheduledAction model — acción futura programada (§3.8).
 *
 * Differentiates a customer "in follow-up" from one inactive by abandonment.
 * While a pending action exists, the customer appears as "en seguimiento
 * programado" in dashboards and lists. The purchase-evolution inactivity
 * alerts still fire but are tagged with that label (no suppression).
 *
 * On `scheduled_date`, the `customers:dispatch-birthday-alerts` command
 * (which also handles scheduled actions) fires a ScheduledActionDue push to
 * the assigned Seller.
 *
 * @property string               $id
 * @property string               $customer_id
 * @property string               $created_by
 * @property \Carbon\Carbon       $scheduled_date
 * @property string               $note
 * @property bool                 $is_resolved
 * @property \Carbon\Carbon|null  $resolved_at
 *
 * @method static Builder|ScheduledAction pending()
 * @method static Builder|ScheduledAction dueOn(\Carbon\Carbon $date)
 */
class ScheduledAction extends Model
{
    use Auditable, HasFactory, HasUuids;

    protected $table = 'scheduled_actions';

    protected $fillable = [
        'customer_id',
        'created_by',
        'scheduled_date',
        'note',
        'is_resolved',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'is_resolved'    => 'boolean',
            'resolved_at'    => 'datetime',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // =========================================================================
    // Query scopes
    // =========================================================================

    /** Scope: only unresolved (pending) actions. */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('is_resolved', false);
    }

    /** Scope: actions due on a specific date (for the daily dispatch command). */
    public function scopeDueOn(Builder $query, \Carbon\Carbon $date): Builder
    {
        return $query->where('scheduled_date', $date->toDateString());
    }

    // =========================================================================
    // Lifecycle helpers
    // =========================================================================

    /**
     * Mark this action as resolved.
     * Persists immediately — call inside a transaction if batching with other writes.
     */
    public function resolve(): void
    {
        $this->update([
            'is_resolved' => true,
            'resolved_at' => now(),
        ]);
    }
}
