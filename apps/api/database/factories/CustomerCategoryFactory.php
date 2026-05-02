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
        // Use unique non-seeded codes (E-Z) to avoid colliding with the
        // base seeded categories A/B/C/D in tests that wipe-and-reseed.
        static $offset = 0;
        $code = chr(ord('E') + ($offset % 22));
        $offset++;

        return [
            'code'                   => $code,
            'name'                   => "Test category {$code}",
            'default_frequency_days' => 30,
            'director_only'          => false,
            'is_active'              => true,
        ];
    }

    /** Category D — Distribuidor-cliente — visible only to Directors (§3.3). */
    public function categoryD(): static
    {
        return $this->state(fn () => [
            'code'                   => 'D',
            'name'                   => 'Distribuidor-cliente',
            'default_frequency_days' => 60,
            'director_only'          => true,
        ]);
    }
}
