<?php

use App\Http\Middleware\AdminAccessGate;
use App\Http\Middleware\CheckIdleTimeout;
use App\Http\Middleware\SetPostgresRlsContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust ngrok / Cloudflare / load balancer forwarded headers so
        // url() generates https when behind TLS-terminating proxy.
        $middleware->trustProxies(at: '*', headers:
            \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR
            | \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST
            | \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT
            | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO
            | \Illuminate\Http\Request::HEADER_X_FORWARDED_AWS_ELB
        );

        // Sanctum SPA cookie auth on /api/* — must run before auth middleware.
        $middleware->prependToGroup('api', [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        // Append RLS context middleware to both web and api groups.
        // Runs after auth middleware so Auth::user() is already resolved.
        // If the user is unauthenticated the middleware passes through without
        // opening a transaction — auth middleware upstream rejects those requests.
        $middleware->appendToGroup('web', [
            CheckIdleTimeout::class,
            SetPostgresRlsContext::class,
        ]);

        $middleware->appendToGroup('api', [
            SetPostgresRlsContext::class,
        ]);

        // Named aliases for selective per-route use.
        $middleware->alias([
            'rls'                          => SetPostgresRlsContext::class,
            'admin.gate'                   => AdminAccessGate::class,
            'idle'                         => CheckIdleTimeout::class,
            'idempotent'                   => \App\Http\Middleware\IdempotencyKey::class,
            // Phase 12 — validates Meta webhook HMAC-SHA256 signature.
            'webhook.whatsapp.signature'   => \App\Http\Middleware\WhatsappWebhookSignature::class,
            // Phase 13 — AI Assistant token / global / per-user gate.
            'ai.cap'                       => \App\Http\Middleware\EnforceAiTokenCap::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
