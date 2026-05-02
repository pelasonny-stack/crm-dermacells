<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * ModelPricing — versioned price list per (provider, model)
 * (Phase 13).
 *
 * Resolution: {@see activeFor()} returns the row whose effective_from is
 * the maximum date <= today for the given (provider, model). Used by
 * RecordAiUsage to compute cost_estimate_usd at insert time.
 *
 * @property string  $id
 * @property string  $provider
 * @property string  $model
 * @property string  $input_per_1k
 * @property ?string $cached_input_per_1k
 * @property string  $output_per_1k
 * @property string  $currency
 * @property \Illuminate\Support\Carbon $effective_from
 */
class ModelPricing extends Model
{
    use HasUuids;

    protected $table = 'model_pricing';

    protected $fillable = [
        'provider',
        'model',
        'input_per_1k',
        'cached_input_per_1k',
        'output_per_1k',
        'currency',
        'effective_from',
    ];

    protected function casts(): array
    {
        return [
            'input_per_1k'         => 'decimal:6',
            'cached_input_per_1k'  => 'decimal:6',
            'output_per_1k'        => 'decimal:6',
            'effective_from'       => 'date',
        ];
    }

    /**
     * Most recent active price for a (provider, model) pair, or null
     * if no price has been declared yet (in which case cost_estimate_usd
     * remains zero — Director sees pricing not configured warning).
     */
    public static function activeFor(string $provider, string $model): ?self
    {
        return static::query()
            ->where('provider', $provider)
            ->where('model', $model)
            ->where('effective_from', '<=', now()->toDateString())
            ->orderByDesc('effective_from')
            ->first();
    }
}
