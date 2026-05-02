<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Audit\Concerns\Auditable;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Commission tier model — §12.2, §16.5.
 *
 * One row per tier per effective_from date. The CommissionCalculatorService
 * (Phase 9) resolves the active tier set for a given calculation date by
 * selecting rows WHERE effective_from <= target_date ORDER BY effective_from
 * DESC, tier_order ASC.
 *
 * The `threshold` Money value represents the lower floor of the tier:
 *   tier 1: threshold = USD 0    → 10% on monthly collected amount
 *   tier 2: threshold = USD 7500 → 12% on the full amount
 *   tier 3: threshold = USD 11250 → 15% on the full amount
 *
 * Per §12.2 the highest tier reached applies over 100% of the collected
 * amount (not marginal/progressive). The Auditable trait logs all changes
 * to this sensitive configuration table.
 *
 * @property string                           $id
 * @property int                              $tier_order
 * @property Money|null                       $threshold          Compound: threshold_amount + threshold_currency
 * @property string                           $rate_pct           NUMERIC(5,4) — e.g. 0.1000 = 10%
 * @property \Illuminate\Support\Carbon       $effective_from
 * @property string|null                      $created_by
 * @property \Illuminate\Support\Carbon|null  $created_at
 * @property \Illuminate\Support\Carbon|null  $updated_at
 */
class CommissionTier extends Model
{
    use Auditable, HasFactory, HasUuids;

    protected $table = 'commission_scale';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tier_order',
        'threshold_amount',
        'threshold_currency',
        'rate_pct',
        'effective_from',
        'created_by',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'threshold'      => MoneyCast::class . ':threshold_amount,threshold_currency',
            'tier_order'     => 'integer',
            'effective_from' => 'date',
        ];
    }

    /**
     * The Director who configured this tier.
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope: tiers effective on or before a given date, ordered for resolution.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @param  \Illuminate\Support\Carbon|\DateTimeInterface|string  $date
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeActiveOn(\Illuminate\Database\Eloquent\Builder $query, mixed $date): \Illuminate\Database\Eloquent\Builder
    {
        return $query
            ->where('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->orderBy('tier_order');
    }
}
