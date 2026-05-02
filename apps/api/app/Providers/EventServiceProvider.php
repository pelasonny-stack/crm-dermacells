<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\PartialReturnAwaitingCreditNote;
use App\Listeners\OnPartialReturnAwaitingCreditNote;
use App\Listeners\SocialiteEventListener;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

/**
 * Wires application event listeners — primarily the
 * {@see SocialiteWasCalled} hook used by the SocialiteProviders package
 * to register custom OAuth providers (Microsoft, Apple, etc.).
 *
 * This provider is bootstrapped via Laravel 12 auto-discovery
 * (`config/app.php` providers array merging from `bootstrap/providers.php`).
 * If the project uses the new `bootstrap/app.php` style, add this class
 * to `bootstrap/providers.php` — the laravel-specialist agent owns that
 * registration.
 */
class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        SocialiteWasCalled::class => [
            SocialiteEventListener::class . '@handle',
        ],

        // Phase 6 — Billing
        PartialReturnAwaitingCreditNote::class => [
            OnPartialReturnAwaitingCreditNote::class,
        ],
    ];

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * Disabled — listeners are registered explicitly in `$listen` to avoid
     * surprise behaviour from filesystem scanning.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
