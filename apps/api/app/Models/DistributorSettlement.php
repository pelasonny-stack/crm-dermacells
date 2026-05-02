<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Audit\Concerns\Auditable;
use App\Enums\SettlementStatus;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DistributorSettlement — §9.4
 *
 * A rendición submitted by a Distributor and confirmed (or rejected) by a Director.
 * Only confirmed settlements reduce the distributor_account balance.
 *
 * @property string            $id
 * @property string            $distributor_id
 * @property string            $amount_amount
 * @property string            $amount_currency
 * @property Money|null        $amount            (via MoneyCast)
 * @property string|null       $payment_method_id  Deferred — Phase 7 link
 * @property string|null       $reference
 * @property Carbon            $submitted_at
 * @property string|null       $confirmed_by
 * @property Carbon|null       $confirmed_at
 * @property SettlementStatus  $status
 * @property string|null       $notes
 * @property Carbon|null       $created_at
 * @property Carbon|null       $updated_at
 *
 * @property-read User          $distributor
 * @property-read User|null     $confirmedBy
 */
class DistributorSettlement extends Model
{
    use Auditable, HasUuids;

    protected $table = 'distributor_settlements';

    protected $fillable = [
        'distributor_id',
        'amount_amount',
        'amount_currency',
        'payment_method_id',
        'reference',
        'submitted_at',
        'confirmed_by',
        'confirmed_at',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount'       => MoneyCast::class . ':amount_amount,amount_currency',
            'status'       => SettlementStatus::class,
            'submitted_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** @return BelongsTo<User, DistributorSettlement> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'distributor_id');
    }

    /** @return BelongsTo<User, DistributorSettlement> */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function isPending(): bool
    {
        return $this->status === SettlementStatus::Pending;
    }

    public function isConfirmed(): bool
    {
        return $this->status === SettlementStatus::Confirmed;
    }

    public function isRejected(): bool
    {
        return $this->status === SettlementStatus::Rejected;
    }
}
