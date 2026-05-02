<?php

declare(strict_types=1);

namespace App\Listeners;

use SocialiteProviders\Manager\SocialiteWasCalled;

/**
 * Registers third-party Socialite providers for the SocialiteProviders
 * package.
 *
 * The `socialiteproviders/microsoft` package follows the standard pattern
 * documented at https://socialiteproviders.com/Microsoft/ — the consuming
 * application must subscribe to the {@see SocialiteWasCalled} event and
 * forward each provider extension to the package's own listener.
 *
 * Add additional providers here as the system grows (Apple, Okta, etc.).
 */
class SocialiteEventListener
{
    /**
     * Register the listeners for the subscriber.
     *
     * @param  \Illuminate\Events\Dispatcher  $events
     * @return array<class-string, string>
     */
    public function subscribe($events): array
    {
        return [
            SocialiteWasCalled::class => self::class . '@handle',
        ];
    }

    /**
     * Handle the {@see SocialiteWasCalled} event by delegating to each
     * provider's extension class.
     */
    public function handle(SocialiteWasCalled $event): void
    {
        $event->extendSocialite(
            'microsoft',
            \SocialiteProviders\Microsoft\Provider::class,
        );
    }
}
