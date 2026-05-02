<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Configuration — generic key/value store for Director-editable system settings.
 *
 * Keys are unique. Values are stored as TEXT; callers cast as needed:
 *   Configuration::getValue('stock_minimum_default', 5) → (int) 5
 *
 * @property string      $id
 * @property string      $key
 * @property string|null $value
 * @property string|null $description
 * @property string      $group_name
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property-read User|null $createdByUser
 * @property-read User|null $updatedByUser
 */
class Configuration extends Model
{
    use Auditable, HasUuids;

    protected $table = 'configurations';

    protected $fillable = [
        'key',
        'value',
        'description',
        'group_name',
        'created_by',
        'updated_by',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** @return BelongsTo<User, Configuration> */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, Configuration> */
    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // -------------------------------------------------------------------------
    // Static helpers
    // -------------------------------------------------------------------------

    /**
     * Retrieve a typed configuration value by key.
     *
     * @template T
     * @param  T  $default
     * @return T|string|null
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        /** @var Configuration|null $config */
        $config = static::where('key', $key)->first();

        if ($config === null || $config->value === null) {
            return $default;
        }

        // Auto-cast: if the default is an int, return an int.
        if (is_int($default)) {
            return (int) $config->value;
        }

        if (is_float($default)) {
            return (float) $config->value;
        }

        if (is_bool($default)) {
            return filter_var($config->value, FILTER_VALIDATE_BOOLEAN);
        }

        return $config->value;
    }

    /**
     * Set a configuration value by key. Creates if not present.
     */
    public static function setValue(string $key, string $value, ?string $updatedBy = null): static
    {
        /** @var static $config */
        $config = static::firstOrNew(['key' => $key]);
        $config->value      = $value;
        $config->updated_by = $updatedBy ?? auth()->id();
        $config->save();

        return $config;
    }
}
