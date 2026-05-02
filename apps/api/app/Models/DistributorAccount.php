<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DistributorAccount — §9.3
 *
 * One row per Distributor. Holds the aggregated financial snapshot:
 * - balance_ars / balance_usd: saldo a rendir (what they owe Dermacells).
 * - gross_margin_ars / gross_margin_usd: ventas zona − costo preferencial.
 *
 * This is a read-optimised denormalisation. The canonical computation
 * is in RecalculateDistributorAccountJob; this row is always derived,
 * never the source of truth.
 *
 * No created_at — the row exists for the lifetime of the Distributor.
 *
 * @property string       $id
 * @property string       $distributor_id
 * @property string       $balance_ars
 * @property string       $balance_usd
 * @property string       $gross_margin_ars
 * @property string       $gross_margin_usd
 * @property Carbon|null  $last_recalculated_at
 * @property Carbon|null  $updated_at
 *
 * @property-read User    $distributor
 */
class DistributorAccount extends Model
{
    use Auditable, HasUuids;

    protected $table = 'distributor_account';

    public $timestamps = false;

    protected $fillable = [
        'distributor_id',
        'balance_ars',
        'balance_usd',
        'gross_margin_ars',
        'gross_margin_usd',
        'last_recalculated_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'balance_ars'          => 'decimal:4',
            'balance_usd'          => 'decimal:4',
            'gross_margin_ars'     => 'decimal:4',
            'gross_margin_usd'     => 'decimal:4',
            'last_recalculated_at' => 'datetime',
            'updated_at'           => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** @return BelongsTo<User, DistributorAccount> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'distributor_id');
    }
}
