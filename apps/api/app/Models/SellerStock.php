<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Seller stock — per-seller per-product inventory (§8.2).
 *
 * boxes:           full boxes held by the seller.
 * loose_units:     partial box units; constrained 0–4 (5 = 1 full box).
 * reserved_boxes:  boxes locked by confirmed sales (Phase 5).
 * reserved_units:  units locked by confirmed sales (Phase 5).
 * minimum_stock:   configurable low-stock alert threshold per seller per product.
 *
 * @property string $id
 * @property string $seller_id
 * @property string $product_id
 * @property int    $boxes
 * @property int    $loose_units
 * @property int    $reserved_boxes
 * @property int    $reserved_units
 * @property int    $minimum_stock
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read User    $seller
 * @property-read Product $product
 */
class SellerStock extends Model
{
    use Auditable, HasUuids;

    protected $table = 'seller_stock';

    protected $fillable = [
        'seller_id',
        'product_id',
        'boxes',
        'loose_units',
        'reserved_boxes',
        'reserved_units',
        'minimum_stock',
    ];

    protected function casts(): array
    {
        return [
            'boxes'          => 'integer',
            'loose_units'    => 'integer',
            'reserved_boxes' => 'integer',
            'reserved_units' => 'integer',
            'minimum_stock'  => 'integer',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // -------------------------------------------------------------------------
    // Computed helpers
    // -------------------------------------------------------------------------

    /**
     * Available boxes (not reserved). Does not include loose_units in the
     * count to keep the type consistent with Phase 5 reservation logic.
     */
    public function availableBoxes(): int
    {
        return max(0, $this->boxes - $this->reserved_boxes);
    }

    /**
     * Available loose units (not reserved).
     */
    public function availableUnits(): int
    {
        return max(0, $this->loose_units - $this->reserved_units);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Rows where boxes (not reserved) is below minimum_stock.
     */
    public function scopeBelowMinimum(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereRaw('(boxes - reserved_boxes) < minimum_stock')
                     ->where('minimum_stock', '>', 0);
    }
}
