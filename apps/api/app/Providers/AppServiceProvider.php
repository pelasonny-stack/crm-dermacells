<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Payments\Observers\PaymentObserver;
use App\Models\Alert;
use App\Models\PartialReturn;
use App\Models\Payment;
use App\Models\Sale;
use App\Observers\Dashboards\AlertDashboardObserver;
use App\Observers\Dashboards\PaymentDashboardObserver;
use App\Observers\Dashboards\SaleDashboardObserver;
use App\Observers\PartialReturnBillingObserver;
use App\Services\Auth\SocialiteRegistrar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Registers the SocialiteEventListener so the Microsoft provider
        // (and any future socialiteproviders/* package) becomes available
        // via Socialite::driver('microsoft'). PKCE for Google is handled
        // inline by SocialiteRegistrar::driver().
        SocialiteRegistrar::boot();

        // Audit observer wiring: applied via the Auditable trait per
        // model rather than here. Apply
        // `\App\Domain\Audit\Concerns\Auditable` to the User model (and
        // every future model added in later phases that touches sensitive
        // data — Customer, Sale, Invoice, Payment, etc.). The User model lives
        // at `app/Models/User.php`.
        // Example for the User model — uncomment once the trait is added:
        //   use \App\Domain\Audit\Concerns\Auditable;

        // Phase 7 — Payments: keeps customer_account_balances in sync on
        // Payment creation and reversal (§7.4, §7.6).
        Payment::observe(PaymentObserver::class);

        // Phase 6 — Billing: detects PartialReturn transitions to
        // AwaitingCreditNote and dispatches the PartialReturnAwaitingCreditNote
        // event so OnPartialReturnAwaitingCreditNote can trigger
        // IssueXubioCreditNoteJob without modifying Phase 5 actions.
        PartialReturn::observe(PartialReturnBillingObserver::class);

        // Phase 14 — Dashboard real-time broadcasts via Reverb.
        // These observers ADD broadcast events without touching Phase 5/7/10 code.
        Sale::observe(SaleDashboardObserver::class);
        Payment::observe(PaymentDashboardObserver::class);
        Alert::observe(AlertDashboardObserver::class);

        // -------------------------------------------------------------------
        // Phase 13 — Anthropic HTTP macro for AnthropicHttpClient
        // -------------------------------------------------------------------
        //
        // Convenience macro that returns a PendingRequest pre-configured with
        // the Anthropic base URL + JSON accept header. AnthropicHttpClient does
        // its own header injection (api key, anthropic-version, prompt-caching
        // beta) per request because they depend on per-call AiSetting state,
        // but tests can use Http::fake('anthropic.*' => ...) thanks to the
        // base_url binding registered here.
        //
        Http::macro('anthropic', function (): PendingRequest {
            return Http::baseUrl((string) config('services.anthropic.base_url'))
                ->acceptJson();
        });
    }
}
