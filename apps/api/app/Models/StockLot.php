<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stock lot — a single importation batch from a supplier.
 *
 * Created by RegisterImportAction (Director only). Linked to StockMovements
 * so the lot chain of custody can be reconstructed from movement records.
 *
 * expiry_date is nullable; when set, LotExpiryAlertJob will notify Directors
 * X days before it is reached (X from config('stock.lot_expiry_alert_days')).
 *
 * @property string      $id
 * @property string      $product_id
 * @property string      $lot_number
 * @property \Illuminate\Support\Carbon $import_date
 * @property string|null $supplier
 * @property int         $quantity_boxes
 * @property \Illuminate\Support\Carbon|null $expiry_date
 * @property string      $registered_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read Product $product
 * @property-read User $registeredBy
 * @property-read \Illuminate\Database\Eloquent\Collection<int, StockMovement> $movements
 */
class StockLot extends Model
{
    use Auditable, HasUuids;

    protected $table = 'stock_lots';

    protected $fillable = [
        'product_id',
        'lot_number',
        'import_date',
        'supplier',
        'quantity_boxes',
        'expiry_date',
        'registered_by',
    ];

    protected function casts(): array
    {
        return [
            'import_date'   => 'date',
            'expiry_date'   => 'date',
            'quantity_boxes' => 'integer',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'lot_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Lots expiring within the given number of days from now.
     */
    public function scopeExpiringWithin(\Illuminate\Database\Eloquent\Builder $query, int $days): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereNotNull('expiry_date')
                     ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays($days)->toDateString()]);
    }
}
