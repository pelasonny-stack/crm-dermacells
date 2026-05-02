<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Sentry DSN
    |--------------------------------------------------------------------------
    |
    | The DSN tells the SDK where to send events. Populated at runtime from
    | Secrets Manager via SecretsServiceProvider. Leave blank to disable Sentry
    | locally without triggering SDK errors.
    |
    */
    'dsn' => env('SENTRY_DSN', ''),

    /*
    |--------------------------------------------------------------------------
    | Release tracking
    |--------------------------------------------------------------------------
    |
    | Injected by the CI/CD pipeline as the Git commit SHA:
    |   SENTRY_RELEASE=$(git rev-parse --short HEAD)
    |
    */
    'release' => env('SENTRY_RELEASE', null),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    */
    'environment' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Sample Rates
    |--------------------------------------------------------------------------
    |
    | error_types_sample_rate : 10% in production (errors are low-volume,
    |   but we want statistical significance without saturating the quota)
    | traces_sample_rate      : 5% of requests traced (p95 latency visibility)
    | profiles_sample_rate    : 1% of traces profiled (CPU + memory flamegraphs)
    |
    | In staging, set all to 1.0 via SENTRY_TRACES_SAMPLE_RATE=1.0
    |
    */
    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.05),

    'profiles_sample_rate' => (float) env('SENTRY_PROFILES_SAMPLE_RATE', 0.01),

    /*
    |--------------------------------------------------------------------------
    | Error Sampling
    |--------------------------------------------------------------------------
    |
    | Sample errors at 10% in production. All errors flow in staging.
    |
    */
    'send_default_pii' => false, // Never send PII (GDPR / Argentine data protection)

    /*
    |--------------------------------------------------------------------------
    | Breadcrumbs
    |--------------------------------------------------------------------------
    */
    'breadcrumbs' => [
        // Laravel log messages
        'logs'                    => true,
        'logs_level'              => env('SENTRY_BREADCRUMBS_LOG_LEVEL', 'debug'),
        // Database queries (caution: may expose query params — PII risk)
        'sql_queries'             => env('SENTRY_BREADCRUMBS_SQL', false),
        'sql_bindings'            => false,   // Never send SQL bindings (potential PII)
        'queue_info'              => true,
        'command_info'            => true,
        'http_client_requests'    => true,    // Track outbound HTTP (Xubio, BCRA, AI)
        'cache'                   => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracing
    |--------------------------------------------------------------------------
    */
    'tracing' => [
        'queue_job_transactions'            => true,
        'queue_jobs'                        => true,
        'sql_queries'                       => true,
        'sql_origin'                        => true,  // trace query origin file
        'views'                             => false, // Blade views not used (headless API)
        'http_client_requests'              => true,
        'redis_commands'                    => true,
        'missing_routes'                    => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignored exceptions
    |--------------------------------------------------------------------------
    |
    | Business-logic exceptions that should not pollute the error stream.
    | These are expected application states, not bugs.
    |
    */
    'ignore_exceptions' => [
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Auth\Access\AuthorizationException::class,
        \Illuminate\Database\Eloquent\ModelNotFoundException::class,
        \Illuminate\Http\Exceptions\ThrottleRequestsException::class,
        \Illuminate\Session\TokenMismatchException::class,
        \Illuminate\Validation\ValidationException::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
        // Business exceptions (expected flows, not bugs)
        \App\Exceptions\StockInsufficientException::class,
        \App\Exceptions\CustomerHasPendingObligationsException::class,
        \App\Exceptions\InvalidSaleTransitionException::class,
        \App\Exceptions\OperationBlockedByPendingAuthorizationException::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Before-send hook (PII scrubbing)
    |--------------------------------------------------------------------------
    |
    | Defined in App\Providers\SentryServiceProvider::boot() via
    | \Sentry\configureScope(). This config key is informational only.
    |
    */
    'before_send' => null, // Implemented in SentryServiceProvider

];
