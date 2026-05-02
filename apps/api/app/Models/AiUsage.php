<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * AiUsage — append-only ledger of LLM call metrics (Phase 13 — §11.5).
 *
 * Inserted by the queued {@see \App\Jobs\AI\RecordAiUsage} job after each
 * successful chat()/stream() completion. The {@see \App\Http\Middleware\EnforceAiTokenCap}
 * middleware sums by (user_id, period_month) to gate subsequent requests.
 *
 * total_tokens is a Postgres GENERATED column (read-only from app side).
 *
 * @property int    $id
 * @property string $user_id
 * @property ?string $customer_id
 * @property Carbon $period_month
 * @property string $provider
 * @property string $model
 * @property int    $input_tokens
 * @property int    $cached_input_tokens
 * @property int    $output_tokens
 * @property int    $total_tokens
 * @property string $cost_estimate_usd
 * @property ?string $request_id
 * @property ?int   $latency_ms
 * @property Carbon $created_at
 */
class AiUsage extends Model
{
    protected $table = 'ai_usage';

    public $timestamps = false; // Manually populated created_at; no updated_at.

    protected $fillable = [
        'user_id',
        'customer_id',
        'period_month',
        'provider',
        'model',
        'input_tokens',
        'cached_input_tokens',
        'output_tokens',
        'cost_estimate_usd',
        'request_id',
        'latency_ms',
        'created_at',
    ];

    /**
     * total_tokens is a Postgres GENERATED column — never write it from PHP.
     *
     * @var array<int, string>
     */
    protected $guarded = ['total_tokens'];

    protected function casts(): array
    {
        return [
            'period_month'        => 'date',
            'input_tokens'        => 'integer',
            'cached_input_tokens' => 'integer',
            'output_tokens'       => 'integer',
            'total_tokens'        => 'integer',
            'cost_estimate_usd'   => 'decimal:4',
            'latency_ms'          => 'integer',
            'created_at'          => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
