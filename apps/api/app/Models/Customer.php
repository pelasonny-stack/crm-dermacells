<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Audit\Concerns\Auditable;
use Brick\Money\Money;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Customer model — central entity of §3.
 *
 * REFERENCE PRICE
 * ===============
 * `reference_price` is a compound Money cast over the columns
 * `reference_price_amount` (NUMERIC 18,4) and `reference_price_currency`
 * (CHAR 3, default 'USD'). The cast uses MoneyCast which returns a
 * Brick\Money\Money instance or null when no price is configured (in which
 * case the sale defaults to the product's base price of USD 750).
 *
 * `reference_price_unit` follows the same pattern for the per-unit price
 * (= reference_price / 5, editable independently per §3.1).
 *
 * DEACTIVATION
 * ============
 * Customers are never hard-deleted. `is_active = false` plus `deactivated_at`,
 * `deactivated_by`, and `deactivation_reason` support the Director-forced
 * deactivation flow (§3.10). The blocking check (pending balance, open sales,
 * overdue collections) is enforced in DeactivateCustomerAction before setting
 * these fields.
 *
 * SCOPES
 * ======
 * - active()         — filters is_active = true
 * - inZone($id)      — filters zone_id = $id (Distributor scope)
 * - assignedTo($id)  — filters assigned_seller_id = $id (Seller scope)
 *
 * NOTE: RLS already enforces visibility at the Postgres layer. These scopes
 * add explicit, readable filtering at the Eloquent layer for clarity and
 * testability when the GUCs are set to director scope.
 *
 * @property string               $id
 * @property string               $first_name
 * @property string               $last_name
 * @property string               $cuit
 * @property string               $phone
 * @property string               $email
 * @property string               $address
 * @property string               $category_id
 * @property string|null          $zone_id
 * @property string               $assigned_seller_id
 * @property string               $default_payment_terms_id
 * @property Money|null           $reference_price
 * @property Money|null           $reference_price_unit
 * @property int|null             $purchase_frequency_days
 * @property \Carbon\Carbon|null  $first_purchase_date
 * @property bool                 $is_active
 * @property \Carbon\Carbon|null  $deactivated_at
 * @property string|null          $deactivated_by
 * @property string|null          $deactivation_reason
 * @property \Carbon\Carbon|null  $created_at
 * @property \Carbon\Carbon|null  $updated_at
 *
 * @method static Builder|Customer active()
 * @method static Builder|Customer inZone(string $zoneId)
 * @method static Builder|Customer assignedTo(string $userId)
 */
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use Auditable, HasFactory, HasUuids;

    protected $fillable = [
        'first_name',
        'last_name',
        'cuit',
        'phone',
        'email',
        'address',
        'category_id',
        'zone_id',
        'assigned_seller_id',
        'default_payment_terms_id',
        'reference_price_amount',
        'reference_price_currency',
        'reference_price_unit_amount',
        'purchase_frequency_days',
        'first_purchase_date',
        'is_active',
        'deactivated_at',
        'deactivated_by',
        'deactivation_reason',
    ];

    protected function casts(): array
    {
        return [
            // Compound Money casts (see MoneyCast docblock for usage)
            'reference_price'      => MoneyCast::class . ':reference_price_amount,reference_price_currency',
            'reference_price_unit' => MoneyCast::class . ':reference_price_unit_amount,reference_price_currency',

            'purchase_frequency_days' => 'integer',
            'first_purchase_date'     => 'date',
            'is_active'               => 'boolean',
            'deactivated_at'          => 'datetime',
        ];
    }

    // =========================================================================
    // Relationships
    // =========================================================================

    public function category(): BelongsTo
    {
        return $this->belongsTo(CustomerCategory::class, 'category_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'zone_id');
    }

    /** The Seller (or Director with can_sell) assigned to this customer. */
    public function assignedSeller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_seller_id');
    }

    public function defaultPaymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class, 'default_payment_terms_id');
    }

    /** Director who deactivated this customer (null when active). */
    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by');
    }

    public function billingEntities(): HasMany
    {
        return $this->hasMany(CustomerBillingEntity::class, 'customer_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class, 'customer_id');
    }

    public function scheduledActions(): HasMany
    {
        return $this->hasMany(ScheduledAction::class, 'customer_id');
    }

    // =========================================================================
    // Query scopes
    // =========================================================================

    /** Scope: only active customers (is_active = true). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Scope: customers in a specific zone (Distributor dashboard queries). */
    public function scopeInZone(Builder $query, string $zoneId): Builder
    {
        return $query->where('zone_id', $zoneId);
    }

    /** Scope: customers assigned to a specific Seller. */
    public function scopeAssignedTo(Builder $query, string $userId): Builder
    {
        return $query->where('assigned_seller_id', $userId);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** Full name for display / notifications. */
    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
