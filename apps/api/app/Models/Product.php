<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Audit\Concerns\Auditable;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Product model — §5.1, §16.4.
 *
 * The four LiveCells product lines (Dermal, Pink, Capillary, Biomask) plus
 * any future additions configured by the Director. Products are presented as
 * boxes of `units_per_box` units (default 5). Price is stored as a compound
 * (amount NUMERIC 18,4 + currency CHAR 3) pair hydrated by MoneyCast.
 *
 * @property string      $id
 * @property string      $name
 * @property int         $units_per_box
 * @property Money|null  $base_price          Compound cast from base_price_amount + base_price_currency
 * @property bool        $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Product extends Model
{
    use Auditable, HasFactory, HasUuids;

    protected $table = 'products';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'units_per_box',
        'base_price_amount',
        'base_price_currency',
        'is_active',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'base_price'    => MoneyCast::class . ':base_price_amount,base_price_currency',
            'units_per_box' => 'integer',
            'is_active'     => 'boolean',
        ];
    }

    /**
     * Scope a query to only include active products.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true);
    }
}
