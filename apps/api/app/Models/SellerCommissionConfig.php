<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * SellerCommissionConfig — §9.2
 *
 * Versioned commission percentage that a Distributor pays a Seller for sales
 * in a specific Zone. Multiple rows can exist per (distributor, seller, zone)
 * tuple; the active row is the one with the greatest effective_from <= target date.
 *
 * @property string       $id
 * @property string       $distributor_id
 * @property string       $seller_id
 * @property string       $commission_pct   NUMERIC(5,4) — fraction in [0,1]
 * @property string       $zone_id
 * @property string       $set_by
 * @property Carbon       $effective_from
 * @property Carbon|null  $created_at
 * @property Carbon|null  $updated_at
 *
 * @property-read User  $distributor
 * @property-read User  $seller
 * @property-read Zone  $zone
 * @property-read User  $setBy
 */
class SellerCommissionConfig extends Model
{
    use Auditable, HasUuids;

    protected $table = 'seller_commissions_config';

    protected $fillable = [
        'distributor_id',
        'seller_id',
        'commission_pct',
        'zone_id',
        'set_by',
        'effective_from',
    ];

    protected function casts(): array
    {
        return [
            'commission_pct' => 'decimal:4',
            'effective_from' => 'date',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** @return BelongsTo<User, SellerCommissionConfig> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'distributor_id');
    }

    /** @return BelongsTo<User, SellerCommissionConfig> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    /** @return BelongsTo<Zone, SellerCommissionConfig> */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /** @return BelongsTo<User, SellerCommissionConfig> */
    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns commission_pct as a float (e.g. 0.15 for 15%).
     */
    public function pctAsFloat(): float
    {
        return (float) $this->commission_pct;
    }
}
