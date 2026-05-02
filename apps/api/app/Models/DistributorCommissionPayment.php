<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Audit\Concerns\Auditable;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DistributorCommissionPayment — §9.2
 *
 * One row per (distributor, seller, zone, period_month) computed by
 * ComputeMonthlyCommissionsJob on the 1st of each month.
 *
 * When the Distributor records payment to the Seller, `paid` is set to true
 * and the payment metadata is filled.
 *
 * @property string       $id
 * @property string       $distributor_id
 * @property string       $seller_id
 * @property string       $zone_id
 * @property Carbon       $period_month
 * @property Money|null   $base_amount
 * @property string       $base_amount_amount
 * @property string       $base_amount_currency
 * @property string       $commission_pct       Fraction in [0,1]
 * @property Money|null   $commission_amount
 * @property string       $commission_amount_amount
 * @property string       $commission_amount_currency
 * @property bool         $paid
 * @property Carbon|null  $paid_at
 * @property string|null  $paid_by
 * @property string|null  $payment_reference
 * @property Carbon|null  $created_at
 * @property Carbon|null  $updated_at
 *
 * @property-read User      $distributor
 * @property-read User      $seller
 * @property-read Zone      $zone
 * @property-read User|null $paidBy
 */
class DistributorCommissionPayment extends Model
{
    use Auditable, HasUuids;

    protected $table = 'distributor_commission_payments';

    protected $fillable = [
        'distributor_id',
        'seller_id',
        'zone_id',
        'period_month',
        'base_amount_amount',
        'base_amount_currency',
        'commission_pct',
        'commission_amount_amount',
        'commission_amount_currency',
        'paid',
        'paid_at',
        'paid_by',
        'payment_reference',
    ];

    protected function casts(): array
    {
        return [
            'period_month'     => 'date',
            'base_amount'      => MoneyCast::class . ':base_amount_amount,base_amount_currency',
            'commission_amount' => MoneyCast::class . ':commission_amount_amount,commission_amount_currency',
            'commission_pct'   => 'decimal:4',
            'paid'             => 'boolean',
            'paid_at'          => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** @return BelongsTo<User, DistributorCommissionPayment> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'distributor_id');
    }

    /** @return BelongsTo<User, DistributorCommissionPayment> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    /** @return BelongsTo<Zone, DistributorCommissionPayment> */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /** @return BelongsTo<User, DistributorCommissionPayment> */
    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
