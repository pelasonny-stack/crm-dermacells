<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * XubioApiLog — append-only HTTP request/response audit (Phase 6).
 *
 * Persisted by XubioClient on every call. Pruned after 90 days by
 * PruneXubioApiLogJob.
 *
 * No HasUuids (PK is BIGSERIAL). No Auditable (this IS the audit).
 * No updated_at column (insert-only).
 *
 * @property int          $id
 * @property string       $request_id
 * @property string       $endpoint
 * @property string       $method
 * @property array|null   $payload_json
 * @property array|null   $response_json
 * @property int|null     $status_code
 * @property int|null     $latency_ms
 * @property Carbon       $created_at
 */
class XubioApiLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'xubio_api_log';

    protected $fillable = [
        'request_id',
        'endpoint',
        'method',
        'payload_json',
        'response_json',
        'status_code',
        'latency_ms',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_json'  => 'array',
            'response_json' => 'array',
            'status_code'   => 'integer',
            'latency_ms'    => 'integer',
            'created_at'    => 'datetime',
        ];
    }
}
