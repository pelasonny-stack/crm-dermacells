<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Google OAuth (Socialite — built-in driver)
    |--------------------------------------------------------------------------
    |
    | Hosted-domain (`hd`) allowlist lives in config/auth.php under
    | 'tenants.google'. PKCE is supported via setPkceCode() on the driver.
    |
    */
    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Microsoft OAuth (socialiteproviders/microsoft)
    |--------------------------------------------------------------------------
    |
    | Tenant id (`tid`) allowlist lives in config/auth.php under
    | 'tenants.microsoft'. The `tenant` key below sets the authorize-URL
    | tenant segment ('common' for multi-tenant; a GUID for a single tenant).
    |
    | See https://socialiteproviders.com/Microsoft/ for the full key list.
    |
    */
    'microsoft' => [
        'client_id'     => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect'      => env('MICROSOFT_REDIRECT_URI'),
        'tenant'        => env('MICROSOFT_TENANT', 'common'),
    ],

    /*
    |--------------------------------------------------------------------------
    | BCRA Estadísticas Cambiarias API (Phase 2 — §16.7)
    |--------------------------------------------------------------------------
    |
    | Public API — no authentication required. Provides the USD dólar vendedor
    | (sell) rate updated at each BNA daily closing (~17:30-18:00 ART).
    |
    | The base URL is configurable so it can be pointed at a mock server in
    | tests or changed if BCRA migrates the endpoint in the future.
    |
    | See: https://estadisticas-cambiarias.bcra.apidocs.ar/
    |
    */
    'bcra' => [
        'base_url' => env('BCRA_BASE_URL', 'https://api.bcra.gob.ar/estadisticascambiarias/v1.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Business — Meta Cloud API (Phase 12 — §17)
    |--------------------------------------------------------------------------
    |
    | Meta Cloud API direct integration (not Twilio, not 360dialog).
    |
    | PRE-REQUISITE (OUT OF SCOPE for code): Meta Business Verification must be
    | completed and the phone number approved before these credentials are live.
    | See README for the Meta Business Verification flow and lead times.
    |
    | WHATSAPP_PHONE_NUMBER_ID      — numeric ID of the WhatsApp Business phone
    | WHATSAPP_BUSINESS_ACCOUNT_ID  — WABA ID from Meta Business Suite
    | WHATSAPP_ACCESS_TOKEN         — permanent/system-user token (not page token)
    | WHATSAPP_VERIFY_TOKEN         — random secret set in Meta App Dashboard
    |                                  to authenticate the webhook GET challenge
    | WHATSAPP_APP_SECRET           — App Secret from Meta Developer Console,
    |                                  used for HMAC-SHA256 webhook signature
    | WHATSAPP_GRAPH_BASE_URL       — override to point at a mock server in tests
    |
    */
    'whatsapp' => [
        'phone_number_id'      => env('WHATSAPP_PHONE_NUMBER_ID'),
        'business_account_id'  => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
        'access_token'         => env('WHATSAPP_ACCESS_TOKEN'),
        'verify_token'         => env('WHATSAPP_VERIFY_TOKEN'),
        'app_secret'           => env('WHATSAPP_APP_SECRET'),
        'graph_base_url'       => env('WHATSAPP_GRAPH_BASE_URL', 'https://graph.facebook.com/v20.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Xubio API (Phase 6 — §6 Facturación)
    |--------------------------------------------------------------------------
    |
    | OAuth2 client_credentials flow. Token TTL 3600s (refreshed proactively
    | at 50min by App\Services\Xubio\TokenManager).
    |
    | When XUBIO_CLIENT_ID is left empty, App\Services\Xubio\XubioClient
    | enters MOCK MODE and returns deterministic fake CAEs without hitting
    | the network — useful for local dev and panel demos.
    |
    | See https://xubio.com/API/documentation/index.html
    |
    */
    'xubio' => [
        'base_url'   => env('XUBIO_BASE_URL', 'https://xubio.com/API/1.1'),
        'token_url'  => env('XUBIO_TOKEN_URL'),
        'client_id'  => env('XUBIO_CLIENT_ID'),
        'secret'     => env('XUBIO_CLIENT_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Assistant providers (Phase 13 — §11)
    |--------------------------------------------------------------------------
    |
    | Both keys are FALLBACKS. The runtime AI key is read from the encrypted
    | column ai_settings.api_key_encrypted (set via the Filament panel).
    | These env keys are only consulted if no encrypted key is configured —
    | useful for local dev and CI fixtures.
    |
    */
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
    ],
    'anthropic' => [
        'key'      => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
    ],

];
