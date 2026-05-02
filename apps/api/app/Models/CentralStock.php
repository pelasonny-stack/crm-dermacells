<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Central stock record — one row per product representing bodega Dermacells.
 *
 * `available` is a Postgres GENERATED ALWAYS AS (total_imported - total_dispatched)
 * STORED column. It is read-only at the application layer; mutating it directly
 * will throw a DB error. Always update total_imported or total_dispatched instead.
 *
 * @property string  $id
 * @property string  $product_id
 * @property int     $total_imported
 * @property int     $total_dispatched
 * @property int     $available          generated; read-only
 * @property int     $minimum_stock
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read Product $product
 */
class CentralStock extends Model
{
    use Auditable, HasUuids;

    protected $table = 'central_stock';

    protected $fillable = [
        'product_id',
        'total_imported',
        'total_dispatched',
        'minimum_stock',
    ];

    protected function casts(): array
    {
        return [
            'total_imported'   => 'integer',
            'total_dispatched' => 'integer',
            'available'        => 'integer',
            'minimum_stock'    => 'integer',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

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
        return $query->whereRaw('available < minimum_stock')
                     ->where('minimum_stock', '>', 0);
    }
}
