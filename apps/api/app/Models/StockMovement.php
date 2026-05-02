<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Observers\StockMovementObserver;
use App\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stock movement — append-only ledger entry.
 *
 * This model is intentionally NOT using the Auditable trait because
 * StockMovement is already an audit artefact: it is immutable by design
 * (app_role has only INSERT on stock_movements, no UPDATE or DELETE).
 *
 * Instead, StockMovementObserver is registered in bootStockMovement() and
 * only handles the `created` event — the PLAN.md spec for append-only
 * audit via custom observer.
 *
 * created_at is stored as TIMESTAMPTZ; there is no updated_at column on this
 * table ($timestamps = false). The model sets only created_at manually.
 *
 * @property string                $id
 * @property StockMovementType     $movement_type
 * @property string                $product_id
 * @property string|null           $from_entity_type
 * @property string|null           $from_entity_id
 * @property string|null           $to_entity_type
 * @property string|null           $to_entity_id
 * @property int                   $quantity_boxes
 * @property int                   $quantity_units
 * @property string|null           $lot_id
 * @property string|null           $reference_doc
 * @property string|null           $sale_id
 * @property string                $created_by
 * @property \Illuminate\Support\Carbon $created_at
 *
 * @property-read Product       $product
 * @property-read StockLot|null $lot
 * @property-read User          $createdBy
 */
class StockMovement extends Model
{
    use HasUuids;

    protected $table = 'stock_movements';

    /**
     * Disable automatic updated_at — the table has no updated_at column.
     */
    public $timestamps = false;

    /**
     * Manually manage created_at so it is set on INSERT.
     */
    public static $createdAtColumn = 'created_at';

    protected $fillable = [
        'movement_type',
        'product_id',
        'from_entity_type',
        'from_entity_id',
        'to_entity_type',
        'to_entity_id',
        'quantity_boxes',
        'quantity_units',
        'lot_id',
        'reference_doc',
        'sale_id',
        'created_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'movement_type'  => StockMovementType::class,
            'quantity_boxes' => 'integer',
            'quantity_units' => 'integer',
            'created_at'     => 'datetime',
        ];
    }

    /**
     * Register the StockMovementObserver (CREATE-only audit).
     */
    protected static function bootStockMovement(): void
    {
        static::observe(StockMovementObserver::class);
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Ensure created_at is populated before INSERT when not explicitly set.
     */
    protected static function booted(): void
    {
        static::creating(function (self $movement): void {
            $movement->created_at ??= now();
        });
    }
}
