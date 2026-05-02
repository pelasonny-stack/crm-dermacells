<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Audit\Concerns\Auditable;
use App\Enums\PartialReturnStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * PartialReturn — §5.8 devolucion parcial de producto.
 *
 * @property string               $id
 * @property string               $sale_id
 * @property string               $sale_item_id
 * @property string               $initiated_by
 * @property string|null          $confirmed_by
 * @property PartialReturnStatus  $status
 * @property int                  $quantity_boxes
 * @property int                  $quantity_units
 * @property \Brick\Money\Money   $refund
 * @property string               $refund_currency
 * @property string|null          $reason
 * @property Carbon               $initiated_at
 * @property Carbon|null          $confirmed_at
 * @property Carbon|null          $created_at
 * @property Carbon|null          $updated_at
 *
 * @property-read Sale      $sale
 * @property-read SaleItem  $saleItem
 * @property-read User      $initiatedByUser
 * @property-read User|null $confirmedByUser
 */
class PartialReturn extends Model
{
    use Auditable, HasUuids;

    protected $fillable = [
        'sale_id',
        'sale_item_id',
        'initiated_by',
        'confirmed_by',
        'status',
        'quantity_boxes',
        'quantity_units',
        'refund_amount',
        'refund_currency',
        'reason',
        'initiated_at',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'status'         => PartialReturnStatus::class,
            'quantity_boxes' => 'integer',
            'quantity_units' => 'integer',
            'refund'         => MoneyCast::class . ':refund_amount,refund_currency',
            'initiated_at'   => 'datetime',
            'confirmed_at'   => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** @return BelongsTo<Sale, PartialReturn> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<SaleItem, PartialReturn> */
    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    /** @return BelongsTo<User, PartialReturn> */
    public function initiatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    /** @return BelongsTo<User, PartialReturn> */
    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function isPending(): bool
    {
        return $this->status === PartialReturnStatus::PendingDirectorConfirmation;
    }

    public function isApplied(): bool
    {
        return $this->status === PartialReturnStatus::Applied;
    }
}
