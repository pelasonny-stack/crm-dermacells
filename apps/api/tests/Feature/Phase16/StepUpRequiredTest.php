<?php

declare(strict_types=1);

use App\Http\Middleware\AdminStepUpRequired;
use App\Models\User;
use Illuminate\Support\Facades\Config;

/*
|--------------------------------------------------------------------------
| StepUpRequiredTest — Phase 16
|--------------------------------------------------------------------------
|
| Verifies AdminStepUpRequired middleware behaviour:
|   - Missing session flag → redirect to step-up route
|   - Expired flag (>5min) → redirect
|   - Valid flag (<5min) → request passes through
|   - Local env → bypass entirely
|
| The middleware is tested in isolation via Laravel's middleware test helpers.
|
*/

beforeEach(function (): void {
    Config::set('app.admin_ip_allowlist', ['127.0.0.1']);
});

it('allows request when admin_step_up_at is within TTL', function (): void {
    $director = User::factory()->director()->create();

    $response = $this->actingAs($director)
        ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->withSession([AdminStepUpRequired::SESSION_KEY => time()])
        ->get('/admin');

    // With valid step-up flag, the request should not be a step-up redirect.
    // (AdminAccessGate may still run — we only assert it's not a step-up 302.)
    expect($response->status())->not->toBe(302);
});

it('redirects to step-up when session flag is absent', function (): void {
    // Test the middleware in isolation (not full stack).
    $middleware = new AdminStepUpRequired();

    $request = \Illuminate\Http\Request::create('/admin/some-page', 'GET');
    $request->setLaravelSession(app('session')->driver());

    // No step-up key in session.
    $request->session()->flush();

    // Set environment to non-local to activate middleware.
    app()->detectEnvironment(fn () => 'production');

    $response = $middleware->handle($request, fn ($req) => response('ok', 200));

    // In non-local env without step-up, should redirect.
    expect($response->getStatusCode())->toBe(302);

    // Reset env.
    app()->detectEnvironment(fn () => config('app.env', 'testing'));
});

it('bypasses step-up check in local environment', function (): void {
    $middleware = new AdminStepUpRequired();

    $request = \Illuminate\Http\Request::create('/admin/some-page', 'GET');
    $request->setLaravelSession(app('session')->driver());
    $request->session()->flush();

    // Force local env.
    app()->detectEnvironment(fn () => 'local');

    $response = $middleware->handle($request, fn ($req) => response('ok', 200));

    expect($response->getStatusCode())->toBe(200);

    app()->detectEnvironment(fn () => config('app.env', 'testing'));
});

it('redirects when step-up is older than 5 minutes', function (): void {
    $middleware = new AdminStepUpRequired();

    $request = \Illuminate\Http\Request::create('/admin/some-page', 'GET');
    $request->setLaravelSession(app('session')->driver());

    // Step-up 6 minutes ago (expired).
    $request->session()->put(AdminStepUpRequired::SESSION_KEY, time() - 360);

    app()->detectEnvironment(fn () => 'production');

    $response = $middleware->handle($request, fn ($req) => response('ok', 200));

    expect($response->getStatusCode())->toBe(302);

    app()->detectEnvironment(fn () => config('app.env', 'testing'));
});

it('does not redirect for step-up page itself to avoid infinite loop', function (): void {
    $middleware = new AdminStepUpRequired();

    $request = \Illuminate\Http\Request::create('/admin/step-up', 'GET');
    $request->setLaravelSession(app('session')->driver());
    $request->session()->flush();

    app()->detectEnvironment(fn () => 'production');

    $response = $middleware->handle($request, fn ($req) => response('ok', 200));

    // step-up page itself must not be redirected.
    expect($response->getStatusCode())->toBe(200);

    app()->detectEnvironment(fn () => config('app.env', 'testing'));
});
