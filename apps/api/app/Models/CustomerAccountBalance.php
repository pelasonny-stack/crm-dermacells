<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CustomerAccountBalance — running dual-currency balance per customer (§7.4).
 *
 * ONE ROW PER CUSTOMER. Upserted (not inserted) by AccountBalanceUpdater inside
 * the same transaction as Payment creation/reversal.
 *
 * NO Auditable trait: this is derived state, not a primary record. The payments
 * table is the source of truth. This model is a read-optimised cache.
 *
 * DUAL CURRENCY
 * =============
 * balance_ars and balance_usd are maintained separately and NEVER converted to
 * each other. Only commission calculations (Phase 9) convert at query time.
 *
 * @property string $id
 * @property string $customer_id
 * @property float  $balance_ars   Accumulated ARS payments (net of reversals)
 * @property float  $balance_usd   Accumulated USD payments (net of reversals)
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @property-read Customer $customer
 */
class CustomerAccountBalance extends Model
{
    use HasUuids;

    // Only updated_at is used (see migration — no created_at column)
    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'balance_ars',
        'balance_usd',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'balance_ars' => 'decimal:4',
            'balance_usd' => 'decimal:4',
            'updated_at'  => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** @return BelongsTo<Customer, CustomerAccountBalance> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
