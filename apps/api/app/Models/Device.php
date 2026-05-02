<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FCM device registration — Phase 14 (§15 mobile scaffold).
 *
 * @property string                       $id
 * @property string                       $user_id
 * @property string                       $fcm_token
 * @property string                       $platform   ios|android|web
 * @property string|null                  $device_name
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Device extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'fcm_token',
        'platform',
        'device_name',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
