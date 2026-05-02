<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Audit\Concerns\Auditable;
use App\Enums\PreferredCostModality;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DistributorPreferredCost — §9.1
 *
 * Holds the current preferred cost configuration for one (distributor, product)
 * pair. One active row per pair; historical changes are captured by the
 * AuditObserver.
 *
 * @property string                  $id
 * @property string                  $distributor_id
 * @property string                  $product_id
 * @property PreferredCostModality   $modality
 * @property string                  $value          Raw NUMERIC from DB
 * @property string                  $currency
 * @property string                  $updated_by
 * @property Carbon|null             $updated_at
 *
 * @property-read User     $distributor
 * @property-read Product  $product
 * @property-read User     $updatedBy
 */
class DistributorPreferredCost extends Model
{
    use Auditable, HasUuids;

    /**
     * This table has no created_at — only updated_at (last write wins).
     */
    public $timestamps = false;

    protected $table = 'distributor_preferred_cost';

    protected $fillable = [
        'distributor_id',
        'product_id',
        'modality',
        'value',
        'currency',
        'updated_by',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'modality'   => PreferredCostModality::class,
            'value'      => 'decimal:4',
            'updated_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** @return BelongsTo<User, DistributorPreferredCost> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'distributor_id');
    }

    /** @return BelongsTo<Product, DistributorPreferredCost> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, DistributorPreferredCost> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns true when this config uses the fixed-price modality.
     */
    public function isFixedPrice(): bool
    {
        return $this->modality === PreferredCostModality::FixedPrice;
    }

    /**
     * Returns true when this config uses the discount-percentage modality.
     */
    public function isDiscountPct(): bool
    {
        return $this->modality === PreferredCostModality::DiscountPct;
    }
}
