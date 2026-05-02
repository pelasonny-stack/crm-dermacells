<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PaymentTerm model — condiciones de pago (§3.4, §16.6).
 *
 * Standard terms: Contado (0d), 15d, 30d, 45d, 60d — seeded via PaymentTermSeeder.
 * A payment term cannot be hard-deleted when in use; only deactivated (§16.6).
 *
 * @property string $id
 * @property string $name
 * @property int    $days_to_due
 * @property bool   $is_active
 */
class PaymentTerm extends Model
{
    use Auditable, HasFactory, HasUuids;

    protected $table = 'payment_terms';

    protected $fillable = [
        'name',
        'days_to_due',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'days_to_due' => 'integer',
            'is_active'   => 'boolean',
        ];
    }

    /** All customers whose default payment term is this. */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'default_payment_terms_id');
    }
}
