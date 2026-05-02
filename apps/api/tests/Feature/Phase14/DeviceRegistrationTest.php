<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Device;
use App\Models\User;


/**
 * Phase 14 — FCM device registration tests.
 *
 * Validates:
 *   - POST /api/v1/devices with a new FCM token returns 201 Created.
 *   - POST /api/v1/devices with an existing token returns 200 (idempotent, touch last_seen_at).
 *   - DELETE /api/v1/devices/{id} removes the registration.
 *   - A user cannot delete another user's device.
 */
describe('Device registration', function (): void {
    it('registers a new device and returns 201', function (): void {
        $user = User::factory()->create(['role' => UserRole::Seller]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/devices', [
                'fcm_token'   => 'test-fcm-token-abc123',
                'platform'    => 'ios',
                'device_name' => 'iPhone 15 Pro',
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['id', 'registered', 'last_seen_at']);
        $response->assertJsonPath('registered', true);

        expect(Device::where('user_id', $user->getKey())
            ->where('fcm_token', 'test-fcm-token-abc123')
            ->exists()
        )->toBeTrue();
    });

    it('returns 200 idempotent when same token is re-registered', function (): void {
        $user = User::factory()->create(['role' => UserRole::Seller]);

        // First registration.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/devices', [
                'fcm_token' => 'same-token-xyz',
                'platform'  => 'android',
            ])
            ->assertStatus(201);

        // Second registration — same token.
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/devices', [
                'fcm_token' => 'same-token-xyz',
                'platform'  => 'android',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('registered', false);

        // Only one record should exist.
        expect(Device::where('user_id', $user->getKey())
            ->where('fcm_token', 'same-token-xyz')
            ->count()
        )->toBe(1);
    });

    it('unregisters a device with DELETE and returns 204', function (): void {
        $user   = User::factory()->create(['role' => UserRole::Seller]);
        $device = Device::factory()->create(['user_id' => $user->getKey()]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/devices/' . $device->getKey())
            ->assertStatus(204);

        expect(Device::find($device->getKey()))->toBeNull();
    });

    it('cannot delete another user\'s device (404)', function (): void {
        $userA = User::factory()->create(['role' => UserRole::Seller]);
        $userB = User::factory()->create(['role' => UserRole::Seller]);

        $deviceB = Device::factory()->create(['user_id' => $userB->getKey()]);

        // userA trying to delete userB's device.
        $this->actingAs($userA, 'sanctum')
            ->deleteJson('/api/v1/devices/' . $deviceB->getKey())
            ->assertStatus(404);
    });

    it('validates required fields on POST /devices', function (): void {
        $user = User::factory()->create(['role' => UserRole::Seller]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/devices', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fcm_token', 'platform']);
    });

    it('validates platform enum on POST /devices', function (): void {
        $user = User::factory()->create(['role' => UserRole::Seller]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/devices', [
                'fcm_token' => 'some-token',
                'platform'  => 'blackberry', // invalid
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['platform']);
    });
});
