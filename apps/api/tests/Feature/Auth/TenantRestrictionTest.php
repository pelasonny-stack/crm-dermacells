<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/*
|--------------------------------------------------------------------------
| Tenant Restriction Tests (HD / TID allowlist)
|--------------------------------------------------------------------------
|
| Google Workspace accounts carry an `hd` (hosted domain) claim in the
| ID token. The OAuthController must reject any callback where `hd` is
| not in the GOOGLE_HD_ALLOWLIST env variable.
|
| This prevents personal Gmail accounts (@gmail.com, hd=null) and
| foreign-domain accounts from logging in, even if they happen to have
| an email that is pre-registered.
|
| GOOGLE_HD_ALLOWLIST is set to "allowed.com" in phpunit.xml.
|
*/

function makeTenantSocialiteUser(string $email, ?string $hd): SocialiteUser
{
    $mock = Mockery::mock(SocialiteUser::class);
    $mock->allows('getId')->andReturn('sub-' . md5($email));
    $mock->allows('getEmail')->andReturn($email);
    $mock->allows('getName')->andReturn('Test User');
    $mock->allows('token')->andReturn('fake-token');
    $mock->allows('offsetGet')->with('hd')->andReturn($hd);
    $mock->allows('getRaw')->andReturn(['email' => $email, 'hd' => $hd]);

    return $mock;
}

function mockTenantDriver(SocialiteUser $socialiteUser): void
{
    $driverMock = Mockery::mock('Laravel\Socialite\Contracts\Provider');
    $driverMock->allows('user')->andReturn($socialiteUser);
    // stateless() may be called by SocialiteRegistrar — support fluent chain.
    $driverMock->allows('stateless')->andReturnSelf();

    Socialite::shouldReceive('driver')
        ->with('google')
        ->andReturn($driverMock);
}

// ---------------------------------------------------------------------------

it('rejects a callback from a non-allowlisted hosted domain', function (): void {
    // Pre-register the email so that the HD check is truly the blocker,
    // not the "not pre-registered" check.
    User::factory()->create(['email' => 'rogue@rogue.com']);

    $socialiteUser = makeTenantSocialiteUser('rogue@rogue.com', 'rogue.com');
    mockTenantDriver($socialiteUser);

    $response = $this->get('/auth/google/callback');

    $response->assertStatus(403);
    $response->assertJson(['code' => 'OAUTH_HD_NOT_ALLOWED']);
});

it('rejects a callback with a null hosted domain (personal Gmail)', function (): void {
    User::factory()->create(['email' => 'personal@gmail.com']);

    $socialiteUser = makeTenantSocialiteUser('personal@gmail.com', null);
    mockTenantDriver($socialiteUser);

    $response = $this->get('/auth/google/callback');

    $response->assertStatus(403);
    $response->assertJson(['code' => 'OAUTH_HD_NOT_ALLOWED']);
});

it('accepts a callback from an allowlisted hosted domain', function (): void {
    $user = User::factory()->create([
        'email'          => 'valid@allowed.com',
        'oauth_provider' => 'google',
        'oauth_sub'      => 'sub-' . md5('valid@allowed.com'),
    ]);

    $socialiteUser = makeTenantSocialiteUser('valid@allowed.com', 'allowed.com');
    mockTenantDriver($socialiteUser);

    $response = $this->get('/auth/google/callback');

    // A 200 (web redirect) or a 200 with token (mobile) both signal success.
    // We accept any 2xx here; the exact shape is covered in OAuthLoginTest.
    $response->assertSuccessful();
});
