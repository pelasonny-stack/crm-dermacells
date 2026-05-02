<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    public function definition(): array
    {
        return [
            'user_id'      => User::factory(),
            'fcm_token'    => $this->faker->sha256(),
            'platform'     => $this->faker->randomElement(['ios', 'android', 'web']),
            'device_name'  => $this->faker->optional()->words(3, true),
            'last_seen_at' => now(),
        ];
    }

    public function ios(): static
    {
        return $this->state(['platform' => 'ios']);
    }

    public function android(): static
    {
        return $this->state(['platform' => 'android']);
    }
}
