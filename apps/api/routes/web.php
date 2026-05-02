<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\OAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| OAuth (web channel)
|--------------------------------------------------------------------------
|
| The React PWA bounces the browser through these two endpoints to start
| and complete the Google or Microsoft OAuth dance. Sanctum SPA cookie
| auth picks up from there for subsequent API calls.
|
| The mobile app uses POST /api/v1/auth/oauth/{provider} (see api.php)
| with `X-Client-Type: mobile` — it does NOT touch these web routes.
|
*/

Route::get('/auth/{provider}/redirect', [OAuthController::class, 'redirect'])
    ->whereIn('provider', ['google', 'microsoft'])
    ->name('auth.oauth.redirect');

Route::get('/auth/{provider}/callback', [OAuthController::class, 'callback'])
    ->whereIn('provider', ['google', 'microsoft'])
    ->name('auth.oauth.callback');

Route::post('/auth/logout', [OAuthController::class, 'logout'])
    ->middleware('auth')
    ->name('auth.logout');

/*
|--------------------------------------------------------------------------
| Dev-only login (LOCAL ENVIRONMENT ONLY)
|--------------------------------------------------------------------------
|
| Bypass OAuth in local dev so /admin (Filament) is reachable without a
| Google/Microsoft tenant. Auths as the seeded Director user. Returns 404
| in any environment other than 'local'.
|
*/
Route::get('/ping', fn () => 'pong');

if (app()->environment('local')) {
    Route::get('/dev-login', function () {
        // RLS hides users from app_role until GUCs are set. Pre-login lookup
        // bypasses via the migration_role connection (BYPASSRLS).
        $user = \App\Models\User::on('pgsql_migration')
            ->where('email', 'dev@dermacells.local')
            ->firstOrFail();

        // Re-fetch on the default connection so subsequent reads work under
        // the post-login RLS context.
        $user->setConnection(config('database.default'));

        // remember=false — users table has no remember_token column (OAuth-only).
        \Illuminate\Support\Facades\Auth::login($user, false);
        session(['last_activity' => now()->toIso8601String()]);

        return redirect('/admin');
    })->name('dev.login')->withoutMiddleware([\App\Http\Middleware\SetPostgresRlsContext::class, \App\Http\Middleware\CheckIdleTimeout::class]);
}
