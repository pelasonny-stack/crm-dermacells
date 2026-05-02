<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Distributor stock — per-distributor per-product inventory summary (§8.1).
 *
 * total_received:       cumulative boxes received from central stock.
 * total_redistributed:  cumulative boxes sent to sellers in the zone.
 * reserved:             boxes locked by confirmed sales (Phase 5).
 * available:            boxes available for redistribution or own sales.
 *
 * The application layer keeps these counters consistent via Actions inside
 * DB transactions. No generated column here (unlike central_stock) because
 * the available amount needs to account for reserved stock separately.
 *
 * @property string $id
 * @property string $distributor_id
 * @property string $product_id
 * @property int    $total_received
 * @property int    $total_redistributed
 * @property int    $reserved
 * @property int    $available
 * @property int    $minimum_stock
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read User    $distributor
 * @property-read Product $product
 */
class DistributorStock extends Model
{
    use Auditable, HasUuids;

    protected $table = 'distributor_stock';

    protected $fillable = [
        'distributor_id',
        'product_id',
        'total_received',
        'total_redistributed',
        'reserved',
        'available',
        'minimum_stock',
    ];

    protected function casts(): array
    {
        return [
            'total_received'      => 'integer',
            'total_redistributed' => 'integer',
            'reserved'            => 'integer',
            'available'           => 'integer',
            'minimum_stock'       => 'integer',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'distributor_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Rows where available stock is below the configured minimum.
     */
    public function scopeBelowMinimum(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereColumn('available', '<', 'minimum_stock')
                     ->where('minimum_stock', '>', 0);
    }
}
