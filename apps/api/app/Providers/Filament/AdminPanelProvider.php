<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Filament admin panel configuration.
 *
 * Authentication is handled externally via Sanctum + OAuth (Google/Microsoft).
 * Filament's built-in login page is disabled; the AdminAccessGate middleware
 * (provided by security-engineer) enforces role=director and IP allowlist
 * before any panel route is served.
 *
 * Primary colour is Amber to visually distinguish the admin panel from the
 * end-user interface.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // Filament default login page is disabled (null = no login route).
            // Authentication flows through OAuth → Sanctum → session.
            // The AdminAccessGate middleware (security-engineer) performs
            // role=director enforcement and IP allowlist check.
            ->login(null)
            ->colors([
                // Amber differentiates the admin panel visually.
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            // discoverWidgets auto-discovers all Phase 14 widgets from app/Filament/Widgets/.
            // Each widget guards itself with canView() returning Director-only access.
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                Widgets\FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                // RLS context must be set before AdminAccessGate's Auth::user()
                // call (User::find under app_role would be hidden by RLS).
                \App\Http\Middleware\SetPostgresRlsContext::class,
                // Director-only gate + IP allowlist enforced here.
                // File: app/Http/Middleware/AdminAccessGate.php (security-engineer)
                \App\Http\Middleware\AdminAccessGate::class,
                // Step-up re-OAuth: requires re-auth within last 5min for admin access.
                // Skipped in APP_ENV=local. Does not rewrite AdminAccessGate.
                \App\Http\Middleware\AdminStepUpRequired::class,
            ])
            ->authMiddleware([]);
    }
}
