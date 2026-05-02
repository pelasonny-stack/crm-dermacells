<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AiAudit — request/response audit row per AI call (Phase 13 — §11.5).
 *
 * Populated by AskNaturalLanguageQuestion / SuggestNextActionForCustomer
 * use cases after every model invocation. LeakGuard sets leak_detected +
 * leak_details when the response mentions a customer_id different from
 * the one injected into context.
 *
 * @property string  $id
 * @property string  $user_id
 * @property ?string $customer_id
 * @property ?array  $request_payload
 * @property ?array  $response_payload
 * @property bool    $leak_detected
 * @property ?string $leak_details
 * @property \Illuminate\Support\Carbon $created_at
 */
class AiAudit extends Model
{
    use HasUuids;

    protected $table = 'ai_audit';

    public $timestamps = false; // Single-direction created_at, defaulted by DB.

    protected $fillable = [
        'user_id',
        'customer_id',
        'request_payload',
        'response_payload',
        'leak_detected',
        'leak_details',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'request_payload'  => 'array',
            'response_payload' => 'array',
            'leak_detected'    => 'boolean',
            'created_at'       => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
