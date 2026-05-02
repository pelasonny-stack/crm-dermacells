<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EvolutionState;
use Database\Factories\PurchaseEvolutionMetricFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PurchaseEvolutionMetric — nightly-computed evolution state per (customer, product).
 *
 * This is derived/computed state: rows are created or upserted by
 * EvolutionEngine::recompute() each night. They are NOT auditable because
 * they are not business mutations — they are the output of an analytic job.
 *
 * @property string             $id
 * @property string             $customer_id
 * @property string             $product_id
 * @property \Carbon\Carbon|null $last_purchase_date
 * @property float|null         $avg_interval_days
 * @property int|null           $last_interval_days
 * @property int                $purchase_count
 * @property EvolutionState     $evolution_state
 * @property \Carbon\Carbon     $computed_at
 *
 * @property-read Customer $customer
 * @property-read Product  $product
 */
class PurchaseEvolutionMetric extends Model
{
    /** @use HasFactory<PurchaseEvolutionMetricFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $table = 'purchase_evolution_metrics';

    protected $fillable = [
        'customer_id',
        'product_id',
        'last_purchase_date',
        'avg_interval_days',
        'last_interval_days',
        'purchase_count',
        'evolution_state',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_purchase_date' => 'date',
            'avg_interval_days'  => 'float',
            'last_interval_days' => 'integer',
            'purchase_count'     => 'integer',
            'evolution_state'    => EvolutionState::class,
            'computed_at'        => 'datetime',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    /** @return BelongsTo<Customer, PurchaseEvolutionMetric> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Product, PurchaseEvolutionMetric> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // =========================================================================
    // Query scopes
    // =========================================================================

    /**
     * Scope: metrics in a specific evolution state.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     */
    public function scopeInState(\Illuminate\Database\Eloquent\Builder $query, EvolutionState $state): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('evolution_state', $state->value);
    }

    /**
     * Days since last purchase (calculated at query time from computed_at context).
     * Returns null when last_purchase_date is null.
     */
    public function daysSinceLastPurchase(): ?int
    {
        return $this->last_purchase_date
            ? (int) $this->last_purchase_date->diffInDays(now(), true)
            : null;
    }
}
