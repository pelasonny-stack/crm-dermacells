<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CustomerCategory model — categories A–D (§3.3).
 *
 * Category D has director_only = true and is invisible to Sellers and
 * Distributors at the RLS layer (policies in migration 000004/000006).
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property int    $default_frequency_days
 * @property bool   $director_only
 * @property bool   $is_active
 */
class CustomerCategory extends Model
{
    use Auditable, HasFactory, HasUuids;

    protected $table = 'customer_categories';

    protected $fillable = [
        'code',
        'name',
        'default_frequency_days',
        'director_only',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'director_only' => 'boolean',
            'is_active'     => 'boolean',
        ];
    }

    /** All customers in this category. */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'category_id');
    }
}
