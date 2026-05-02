<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AiUserOverride — per-user activation and cap overrides for the AI
 * Assistant module (Phase 13 — §11.1).
 *
 * - enabled = false on a row WITH global_enabled = true → user blocked (403).
 * - monthly_token_cap / monthly_usd_cap NULL → fall back to AiSetting defaults.
 *
 * @property string $user_id
 * @property bool   $enabled
 * @property ?int   $monthly_token_cap
 * @property ?string $monthly_usd_cap
 */
class AiUserOverride extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'enabled',
        'monthly_token_cap',
        'monthly_usd_cap',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled'           => 'boolean',
            'monthly_token_cap' => 'integer',
            'monthly_usd_cap'   => 'decimal:2',
            'updated_at'        => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
