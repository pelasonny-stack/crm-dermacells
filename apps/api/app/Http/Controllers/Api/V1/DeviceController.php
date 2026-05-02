<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * FCM device registration controller — Phase 14 (§15 mobile scaffold).
 *
 * POST /api/v1/devices   — Register or touch a device's FCM token (idempotent).
 * DELETE /api/v1/devices/{device} — Unregister a device.
 *
 * Idempotency: if the (user_id, fcm_token) pair already exists, the existing
 * record's last_seen_at is updated and a 200 OK is returned. A fresh
 * registration returns 201 Created.
 */
final class DeviceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fcm_token'   => ['required', 'string', 'max:255'],
            'platform'    => ['required', 'string', Rule::in(['ios', 'android', 'web'])],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $userId = (string) $request->user()->getKey();

        // Attempt to find existing registration for this user+token pair.
        $existing = Device::where('user_id', $userId)
            ->where('fcm_token', $validated['fcm_token'])
            ->first();

        if ($existing !== null) {
            // Touch last_seen_at and update device_name if provided.
            $existing->update([
                'last_seen_at' => now(),
                'device_name'  => $validated['device_name'] ?? $existing->device_name,
            ]);

            return response()->json([
                'id'          => $existing->getKey(),
                'registered'  => false, // existing device updated
                'last_seen_at' => $existing->last_seen_at,
            ], 200);
        }

        // New device registration.
        $device = Device::create([
            'user_id'     => $userId,
            'fcm_token'   => $validated['fcm_token'],
            'platform'    => $validated['platform'],
            'device_name' => $validated['device_name'] ?? null,
            'last_seen_at' => now(),
        ]);

        return response()->json([
            'id'          => $device->getKey(),
            'registered'  => true,
            'last_seen_at' => $device->last_seen_at,
        ], 201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $device = Device::where('id', $id)
            ->where('user_id', (string) $request->user()->getKey())
            ->firstOrFail();

        $device->delete();

        return response()->json(null, 204);
    }
}
