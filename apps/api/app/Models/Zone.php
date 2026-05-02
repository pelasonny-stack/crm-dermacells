<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Zone model — created in Phase 1 migration, model stub created in Phase 2.
 *
 * A zone with distributor_id = NULL is a "zona directa" (§2.3).
 *
 * @property string      $id
 * @property string      $name
 * @property string|null $distributor_id
 * @property bool        $is_active
 */
class Zone extends Model
{
    use Auditable, HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'distributor_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** The Distributor user assigned to this zone (null = zona directa). */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'distributor_id');
    }

    /** All customers in this zone. */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
