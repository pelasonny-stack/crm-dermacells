<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PaymentMethod — lookup table for the five payment media (§7.1).
 *
 * Seeded by PaymentMethodSeeder. The `code` field is the stable identifier
 * used in application logic (e.g. CashDestinationResolver checks code = 'cash').
 *
 * @property string $id
 * @property string $code              e.g. 'transfer_dermacells', 'cash', 'check'
 * @property string $name              Human-readable label
 * @property bool   $requires_reference     transfers need a reference number
 * @property bool   $requires_installments  credit_card needs installment count
 * @property bool   $requires_check_fields  check needs number/bank/due_date
 * @property bool   $is_active
 */
class PaymentMethod extends Model
{
    use HasUuids;

    // Payment methods are static config — no Auditable needed (seeded, not user-edited)
    protected $fillable = [
        'code',
        'name',
        'requires_reference',
        'requires_installments',
        'requires_check_fields',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'requires_reference'    => 'boolean',
            'requires_installments' => 'boolean',
            'requires_check_fields' => 'boolean',
            'is_active'             => 'boolean',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** @return HasMany<Payment, PaymentMethod> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function isCash(): bool
    {
        return $this->code === 'cash';
    }

    public function isTransfer(): bool
    {
        return in_array($this->code, ['transfer_dermacells', 'transfer_distributor'], true);
    }

    public function isCheck(): bool
    {
        return $this->code === 'check';
    }

    public function isCreditCard(): bool
    {
        return $this->code === 'credit_card';
    }
}
