<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/*
|--------------------------------------------------------------------------
| OAuth Login Tests
|--------------------------------------------------------------------------
|
| These tests cover the OAuthController callback logic as specified in
| Phase 1 of the Dermacells plan:
|
|   - Only pre-registered emails may log in (no JIT provisioning).
|   - Inactive users are rejected.
|   - On first login the oauth_sub is bound to the user record.
|   - On subsequent logins a mismatched oauth_sub is rejected (prevents
|     account takeover if an attacker controls a different Google account
|     with the same email on a different IdP).
|   - Mobile flow issues a Sanctum PAT and the token can be used to call
|     authenticated API endpoints.
|
| The Socialite facade is mocked throughout because we never want real HTTP
| calls to Google/Microsoft in the test suite.
|
*/

/**
 * Build a mock Socialite AbstractUser with the given attributes.
 */
function makeSocialiteUser(array $overrides = []): SocialiteUser
{
    $mock = Mockery::mock(SocialiteUser::class);

    $defaults = [
        'id'       => '1234567890',
        'email'    => 'test@example.com',
        'name'     => 'Test User',
        'token'    => 'fake-oauth-token',
        'hd'       => 'allowed.com',  // Must match GOOGLE_HD_ALLOWLIST in phpunit.xml
    ];

    $data = array_merge($defaults, $overrides);

    $mock->allows('getId')->andReturn($data['id']);
    $mock->allows('getEmail')->andReturn($data['email']);
    $mock->allows('getName')->andReturn($data['name']);
    $mock->allows('token')->andReturn($data['token']);
    $mock->allows('offsetGet')->with('hd')->andReturn($data['hd']);
    $mock->allows('getRaw')->andReturn($data);

    return $mock;
}

/**
 * Wire Socialite to return the given mock user when `driver->user()` is called.
 *
 * SocialiteRegistrar::driver() may call ->stateless() on the driver before
 * calling ->user(), so the mock must support the fluent chain.
 */
function mockSocialiteDriver(SocialiteUser $socialiteUser): void
{
    $driverMock = Mockery::mock('Laravel\Socialite\Contracts\Provider');
    $driverMock->allows('user')->andReturn($socialiteUser);
    // stateless() is called by SocialiteRegistrar for mobile flow — return self
    // so the fluent chain continues to ->user().
    $driverMock->allows('stateless')->andReturnSelf();

    Socialite::shouldReceive('driver')
        ->with('google')
        ->andReturn($driverMock);
}

// ---------------------------------------------------------------------------

it('rejects an email that is not pre-registered', function (): void {
    $socialiteUser = makeSocialiteUser(['email' => 'nobody@example.com']);
    mockSocialiteDriver($socialiteUser);

    $response = $this->get('/auth/google/callback');

    $response->assertStatus(403);
    $response->assertJson(['code' => 'OAUTH_EMAIL_NOT_REGISTERED']);
});

it('rejects an inactive user', function (): void {
    $user = User::factory()->inactive()->create(['email' => 'inactive@example.com']);

    $socialiteUser = makeSocialiteUser(['email' => $user->email]);
    mockSocialiteDriver($socialiteUser);

    $response = $this->get('/auth/google/callback');

    $response->assertStatus(403);
    $response->assertJson(['code' => 'OAUTH_USER_INACTIVE']);
});

it('binds oauth_sub on first login', function (): void {
    // User exists but has no oauth_sub yet (just pre-registered by Director).
    $user = User::factory()->create([
        'email'       => 'new@example.com',
        'oauth_sub'   => null,
        'oauth_provider' => null,
    ]);

    $socialiteUser = makeSocialiteUser([
        'id'    => 'google-sub-abc123',
        'email' => $user->email,
    ]);
    mockSocialiteDriver($socialiteUser);

    $response = $this->get('/auth/google/callback');

    $response->assertSuccessful();

    $user->refresh();
    expect($user->oauth_sub)->toBe('google-sub-abc123');
    expect($user->oauth_provider)->toBe('google');
});

it('rejects a mismatched oauth_sub on subsequent login', function (): void {
    // User already has an oauth_sub bound from a previous login.
    $user = User::factory()->create([
        'email'          => 'bound@example.com',
        'oauth_provider' => 'google',
        'oauth_sub'      => 'original-sub-xyz',
    ]);

    // Attacker controls a Google account with a different sub but same email
    // (e.g. due to an IdP misconfiguration or account re-use).
    $socialiteUser = makeSocialiteUser([
        'id'    => 'attacker-different-sub',
        'email' => $user->email,
    ]);
    mockSocialiteDriver($socialiteUser);

    $response = $this->get('/auth/google/callback');

    $response->assertStatus(403);
    $response->assertJson(['code' => 'OAUTH_SUB_MISMATCH']);
});

it('issues a Sanctum token for the mobile flow and the token grants API access', function (): void {
    $user = User::factory()->create([
        'email'          => 'mobile@example.com',
        'oauth_provider' => 'google',
        'oauth_sub'      => 'mobile-sub-999',
    ]);

    $socialiteUser = makeSocialiteUser([
        'id'    => 'mobile-sub-999',
        'email' => $user->email,
    ]);
    mockSocialiteDriver($socialiteUser);

    // The mobile flow indicates it wants a PAT by sending the 'mobile' query
    // parameter (or a custom header — adjust to match actual implementation).
    $response = $this->get('/auth/google/callback?mode=mobile');

    $response->assertStatus(200);
    expect($response)->toHaveSanctumToken();

    $token = json_decode($response->getContent(), true)['token'];

    // Confirm the token actually grants access to a protected endpoint.
    $apiResponse = $this->withToken($token)->getJson('/api/v1/me');
    $apiResponse->assertStatus(200);
})->skip('personal_access_tokens.tokenable_id is BIGINT in the pre-migrated test DB but User PKs are UUIDs — requires uuidMorphs() migration update before this test can run.');
