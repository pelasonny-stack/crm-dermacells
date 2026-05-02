<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Enums\IvaCondition;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CustomerBillingEntity model — razón social de facturación (§3.5).
 *
 * A customer may have one or more billing entities. Only one can be primary.
 * The billing entity is chosen at invoice time (Phase 6), not at sale creation.
 *
 * `xubio_cliente_id` is populated lazily by XubioClientResolver on first invoice
 * emission. NULL means not yet registered with Xubio.
 *
 * @property string        $id
 * @property string        $customer_id
 * @property string        $name
 * @property string        $cuit
 * @property IvaCondition  $iva_condition
 * @property bool          $is_primary
 * @property string|null   $xubio_cliente_id
 */
class CustomerBillingEntity extends Model
{
    use Auditable, HasFactory, HasUuids;

    protected $table = 'customer_billing_entities';

    protected $fillable = [
        'customer_id',
        'name',
        'cuit',
        'iva_condition',
        'is_primary',
        'xubio_cliente_id',
    ];

    protected function casts(): array
    {
        return [
            'iva_condition' => IvaCondition::class,
            'is_primary'    => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
