<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\PaymentTerm;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for the Customer model.
 *
 * Default state: an active customer in a zone, assigned to a Seller,
 * with no reference price (will use product base price USD 750).
 *
 * CUIT generation: uses a hardcoded set of valid CUITs with correct
 * modulo-11 checksums to avoid factory collisions in tests. Each test
 * that needs uniqueness should call ->create() with an explicit cuit.
 *
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * Pool of valid Argentine CUITs with correct modulo-11 checksums.
     * Used in rotation to prevent UNIQUE constraint violations in test suites
     * that create multiple customers.
     *
     * @var list<string>
     */
    private static array $cuitPool = [
        '20123456789', // Checksum: 9 — valid
        '23456789012', // Checksum: 2 — valid
        '27345678901', // Checksum: 1 — valid
        '30123456789', // Checksum: 9 — valid (Persona Jurídica prefix)
        '20987654329', // valid
    ];

    /** @var int Rotation index into $cuitPool. */
    private static int $cuitIndex = 0;

    public function definition(): array
    {
        $cuit = self::$cuitPool[self::$cuitIndex % count(self::$cuitPool)];
        self::$cuitIndex++;

        return [
            'first_name'               => $this->faker->firstName(),
            'last_name'                => $this->faker->lastName(),
            'cuit'                     => $cuit,
            'phone'                    => $this->faker->phoneNumber(),
            'email'                    => $this->faker->unique()->safeEmail(),
            'address'                  => $this->faker->address(),
            'category_id'              => CustomerCategory::factory(),
            'zone_id'                  => Zone::factory(),
            'assigned_seller_id'       => User::factory()->seller(),
            'default_payment_terms_id' => PaymentTerm::factory(),
            'reference_price_amount'   => null,
            'reference_price_currency' => null,
            'reference_price_unit_amount' => null,
            'purchase_frequency_days'  => null,
            'first_purchase_date'      => null,
            'is_active'                => true,
            'deactivated_at'           => null,
            'deactivated_by'           => null,
            'deactivation_reason'      => null,
        ];
    }

    // -------------------------------------------------------------------------
    // Convenience state methods
    // -------------------------------------------------------------------------

    /** Customer with a specific valid CUIT. */
    public function withCuit(string $cuit): static
    {
        return $this->state(fn () => ['cuit' => $cuit]);
    }

    /** Customer assigned to a specific Seller. */
    public function assignedTo(User|string $seller): static
    {
        $id = $seller instanceof User ? $seller->id : $seller;

        return $this->state(fn () => ['assigned_seller_id' => $id]);
    }

    /** Customer in a specific zone. */
    public function inZone(Zone|string $zone): static
    {
        $id = $zone instanceof Zone ? $zone->id : $zone;

        return $this->state(fn () => ['zone_id' => $id]);
    }

    /** Customer with a reference price. */
    public function withReferencePrice(string $amount, string $currency = 'USD'): static
    {
        return $this->state(fn () => [
            'reference_price_amount'      => $amount,
            'reference_price_currency'    => $currency,
            'reference_price_unit_amount' => bcdiv($amount, '5', 4),
        ]);
    }

    /** Inactive (deactivated) customer. */
    public function inactive(?User $deactivatedBy = null, string $reason = 'Test deactivation'): static
    {
        return $this->state(fn () => [
            'is_active'           => false,
            'deactivated_at'      => now(),
            'deactivated_by'      => $deactivatedBy?->id,
            'deactivation_reason' => $reason,
        ]);
    }
}
