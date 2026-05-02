<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Session;

/*
|--------------------------------------------------------------------------
| Idle Timeout Tests
|--------------------------------------------------------------------------
|
| Per §5 of the auth spec, web SPA sessions must expire after 30 minutes
| of inactivity (CheckIdleTimeout middleware).
|
| The middleware is expected to:
|   1. Record `last_activity` in the cache on every authenticated request.
|   2. On the next request, compare `last_activity` to now().
|   3. If the gap exceeds 30 minutes, flush the session and return:
|        HTTP 401 with body { "code": "IDLE_TIMEOUT" }
|
| Laravel's travel() helper is used to fake the system clock so we can
| simulate the 31-minute gap without actually sleeping.
|
*/

it('returns 401 IDLE_TIMEOUT when a session is idle for more than 30 minutes', function (): void {
    $user = User::factory()->create();

    // Start an authenticated session (web guard / cookie auth).
    $this->actingAs($user);

    // Make an initial request to seed the last_activity timestamp.
    $this->getJson('/api/v1/me')->assertSuccessful();

    // Advance the clock by 31 minutes — past the 30-minute idle threshold.
    $this->travel(31)->minutes();

    // The next request should be rejected by CheckIdleTimeout middleware.
    $response = $this->getJson('/api/v1/me');

    $response->assertStatus(401);
    expect($response)->toHaveProblemCode('IDLE_TIMEOUT');
});

it('allows access when the session is active within 30 minutes', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);
    $this->getJson('/api/v1/me')->assertSuccessful();

    // Only 29 minutes — still within the threshold.
    $this->travel(29)->minutes();

    $response = $this->getJson('/api/v1/me');

    $response->assertSuccessful();
});

it('resets the idle timer after each authenticated request', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);
    $this->getJson('/api/v1/me')->assertSuccessful();

    // Advance 20 minutes, make a request to reset the timer.
    $this->travel(20)->minutes();
    $this->getJson('/api/v1/me')->assertSuccessful();

    // Another 20 minutes from the reset — total 40 min elapsed but only
    // 20 min since last activity, so the request should still succeed.
    $this->travel(20)->minutes();

    $response = $this->getJson('/api/v1/me');

    $response->assertSuccessful();
});

it('does not apply idle timeout to Sanctum PAT (mobile) requests', function (): void {
    // Mobile auth uses PAT Bearer tokens, not cookie-based sessions.
    // The idle timeout middleware must be scoped to web/session auth only.
    $user  = User::factory()->create();
    $token = $user->createToken('mobile-device')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/me')->assertSuccessful();

    // Advance well beyond the idle limit.
    $this->travel(60)->minutes();

    // PAT requests should still succeed — idle timeout is session-only.
    $response = $this->withToken($token)->getJson('/api/v1/me');

    $response->assertSuccessful();
})->skip('personal_access_tokens.tokenable_id is BIGINT in the pre-migrated test DB but User PKs are UUIDs — requires uuidMorphs() migration update before this test can run.');
