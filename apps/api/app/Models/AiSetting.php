<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AiSetting — single-row global configuration for the AI Assistant module
 * (Phase 13 — §11).
 *
 * The table is constrained at the DB level to a single row whose primary
 * key is the all-ones UUID. {@see current()} performs the find-or-create
 * once per request and is the only intended entry point.
 *
 * api_key_encrypted is the result of Crypt::encryptString() — never echo
 * it back to a UI. The Filament page uses a write-only password input.
 *
 * @property string  $id
 * @property bool    $global_enabled
 * @property ?string $provider                  'openai' | 'anthropic' | null
 * @property ?string $model
 * @property ?string $endpoint
 * @property ?string $api_key_encrypted
 * @property int     $monthly_token_cap_default
 * @property string  $monthly_usd_cap_default
 * @property ?string $updated_by
 */
class AiSetting extends Model
{
    public const SINGLETON_ID = '00000000-0000-0000-0000-000000000001';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false; // Only updated_at is tracked, manually.

    protected $fillable = [
        'id',
        'global_enabled',
        'provider',
        'model',
        'endpoint',
        'api_key_encrypted',
        'monthly_token_cap_default',
        'monthly_usd_cap_default',
        'updated_by',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'global_enabled'            => 'boolean',
            'monthly_token_cap_default' => 'integer',
            'monthly_usd_cap_default'   => 'decimal:2',
            'updated_at'                => 'datetime',
        ];
    }

    /**
     * Returns the singleton settings row, creating it lazily if absent.
     *
     * The first-ever call seeds a disabled row so the cap-check middleware
     * has something to read; downstream calls are cheap PK lookups.
     */
    public static function current(): self
    {
        return self::firstOrCreate(
            ['id' => self::SINGLETON_ID],
            [
                'global_enabled'            => false,
                'monthly_token_cap_default' => 1_000_000,
                'monthly_usd_cap_default'   => '100.00',
                'updated_at'                => now(),
            ],
        );
    }

    /** Director who last modified the settings (null on initial seed). */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
