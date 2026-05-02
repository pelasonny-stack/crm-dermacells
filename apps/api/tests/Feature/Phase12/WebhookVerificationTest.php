<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;

/*
|--------------------------------------------------------------------------
| WebhookVerificationTest — Phase 12
|--------------------------------------------------------------------------
|
| Covers the GET /api/webhooks/whatsapp hub.challenge flow.
|
| Meta sends:
|   ?hub.mode=subscribe
|   &hub.verify_token=<configured_token>
|   &hub.challenge=<random_string>
|
| Expected behaviour:
|   - Matching token   → 200 with hub.challenge as plain-text body
|   - Wrong token      → 403
|   - Missing token    → 403
|
*/

beforeEach(function (): void {
    Config::set('services.whatsapp.verify_token', 'test-verify-secret');
    Config::set('services.whatsapp.app_secret', 'test-app-secret');
});

it('responds with hub.challenge when verify_token matches', function (): void {
    $response = $this->get('/api/webhooks/whatsapp?' . http_build_query([
        'hub.mode'         => 'subscribe',
        'hub.verify_token' => 'test-verify-secret',
        'hub.challenge'    => 'abc123challenge',
    ]));

    $response->assertStatus(200);
    $response->assertSee('abc123challenge', false);
});

it('returns 403 when verify_token does not match', function (): void {
    $response = $this->get('/api/webhooks/whatsapp?' . http_build_query([
        'hub.mode'         => 'subscribe',
        'hub.verify_token' => 'wrong-token',
        'hub.challenge'    => 'abc123challenge',
    ]));

    $response->assertStatus(403);
    $response->assertJson(['code' => 'WHATSAPP_VERIFY_TOKEN_MISMATCH']);
});

it('returns 403 when verify_token is absent', function (): void {
    $response = $this->get('/api/webhooks/whatsapp?' . http_build_query([
        'hub.mode'      => 'subscribe',
        'hub.challenge' => 'abc123challenge',
    ]));

    $response->assertStatus(403);
});

it('returns 403 when hub.mode is not subscribe', function (): void {
    $response = $this->get('/api/webhooks/whatsapp?' . http_build_query([
        'hub.mode'         => 'unsubscribe',
        'hub.verify_token' => 'test-verify-secret',
        'hub.challenge'    => 'abc123challenge',
    ]));

    $response->assertStatus(403);
});
