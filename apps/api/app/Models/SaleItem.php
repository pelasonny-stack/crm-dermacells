<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * SaleItem — one line in a sale (one product, multiple quantities).
 *
 * @property string          $id
 * @property string          $sale_id
 * @property string          $product_id
 * @property int             $quantity_boxes
 * @property int             $quantity_units
 * @property \Brick\Money\Money $unit_price
 * @property \Brick\Money\Money $subtotal
 * @property string|null     $exchange_rate_id
 * @property Carbon|null     $created_at
 * @property Carbon|null     $updated_at
 *
 * @property-read Sale         $sale
 * @property-read Product      $product
 * @property-read ExchangeRate|null $exchangeRate
 */
class SaleItem extends Model
{
    use Auditable, HasUuids;

    protected $fillable = [
        'sale_id',
        'product_id',
        'quantity_boxes',
        'quantity_units',
        'unit_price_amount',
        'unit_price_currency',
        'subtotal_amount',
        'exchange_rate_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity_boxes' => 'integer',
            'quantity_units' => 'integer',
            'unit_price'     => MoneyCast::class . ':unit_price_amount,unit_price_currency',
            'subtotal'       => MoneyCast::class . ':subtotal_amount,unit_price_currency',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** @return BelongsTo<Sale, SaleItem> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<Product, SaleItem> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ExchangeRate, SaleItem> */
    public function exchangeRate(): BelongsTo
    {
        return $this->belongsTo(ExchangeRate::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Total units represented by this line item (boxes × 5 + loose units).
     */
    public function totalUnits(): int
    {
        return ($this->quantity_boxes * 5) + $this->quantity_units;
    }
}
