<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Exchange rate model — §5.3, §16.7.
 *
 * One row per calendar day. The `source` column differentiates between rates
 * pulled from BCRA ('api_bna'), entered manually by a Director
 * ('manual_override'), or copied from the previous day when BCRA was
 * unavailable ('fallback').
 *
 * The `recorded_by` FK is populated for manual_override rows so the audit
 * trail identifies which Director set the rate. For api_bna and fallback rows
 * it is NULL (automated system action).
 *
 * @property string                           $id
 * @property \Illuminate\Support\Carbon       $rate_date         Cast to date (Carbon, time stripped)
 * @property string                           $rate_ars_per_usd  NUMERIC(18,6) — kept as string to avoid float imprecision
 * @property string                           $source            'api_bna' | 'manual_override' | 'fallback'
 * @property string|null                      $recorded_by
 * @property \Illuminate\Support\Carbon|null  $created_at
 */
class ExchangeRate extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'exchange_rates';

    /**
     * No `updated_at` column — rates are immutable once created.
     */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'rate_date',
        'rate_ars_per_usd',
        'source',
        'recorded_by',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate_date'  => 'date',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The Director who manually entered the rate (null for automated rows).
     *
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Scope: return only the most recent rate.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeLatest(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->orderByDesc('rate_date');
    }
}
