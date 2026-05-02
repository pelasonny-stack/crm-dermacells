<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomerCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for CustomerCategory.
 *
 * Default state: a category A (non-director-only) with a 30-day frequency.
 * Use ->categoryD() to create a director-only category for RLS tests.
 *
 * @extends Factory<CustomerCategory>
 */
class CustomerCategoryFactory extends Factory
{
    protected $model = CustomerCategory::class;

    public function definition(): array
    {
        // Use codes E-L (8 slots) to avoid colliding with seeded A-D and
        // with categoryD() which uses M-Z. DatabaseTransactions ensures
        // each row is rolled back between tests so codes cycle safely.
        static $offset = 0;
        $code = chr(ord('E') + ($offset % 8));
        $offset++;

        return [
            'code'                   => $code,
            'name'                   => "Test category {$code}",
            'default_frequency_days' => 30,
            'director_only'          => false,
            'is_active'              => true,
        ];
    }

    /** Category D — Distribuidor-cliente — visible only to Directors (§3.3).
     *
     * Uses codes M-Z (14 slots) — disjoint from seeded A-D and from
     * definition()'s E-L range. DatabaseTransactions rolls each row back after
     * every test, so the same letter is safely reusable across tests.
     * The RLS policies filter on director_only=TRUE, not on the code column.
     */
    public function categoryD(): static
    {
        static $dIdx = 0;
        // M-Z gives 14 unique single-char codes (14 = ord('Z') - ord('M') + 1).
        $code = chr(ord('M') + ($dIdx % 14));
        $dIdx++;

        return $this->state(fn () => [
            'code'                   => $code,
            'name'                   => 'Distribuidor-cliente (test)',
            'default_frequency_days' => 60,
            'director_only'          => true,
        ]);
    }
}
