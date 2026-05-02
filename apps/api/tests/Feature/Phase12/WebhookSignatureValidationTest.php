<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| WebhookSignatureValidationTest — Phase 12
|--------------------------------------------------------------------------
|
| Covers the POST /api/webhooks/whatsapp HMAC-SHA256 signature validation.
|
| Meta computes:  sha256=HMAC_SHA256(raw_body, APP_SECRET)
| and sets:       X-Hub-Signature-256: sha256=<hex>
|
| Expected behaviour:
|   - Correct signature  → 200 (job dispatched)
|   - Wrong signature    → 403
|   - Missing header     → 403
|
*/

beforeEach(function (): void {
    Config::set('services.whatsapp.app_secret', 'test-app-secret');
    Config::set('services.whatsapp.verify_token', 'test-verify-token');

    Queue::fake();
});

/**
 * Build a valid X-Hub-Signature-256 header for a given body.
 */
function makeSignature(string $body, string $secret = 'test-app-secret'): string
{
    return 'sha256=' . hash_hmac('sha256', $body, $secret);
}

it('returns 200 and dispatches job when signature is valid', function (): void {
    $body = json_encode([
        'object' => 'whatsapp_business_account',
        'entry'  => [],
    ]);

    $serverVars = [
        'HTTP_X_HUB_SIGNATURE_256' => makeSignature($body),
        'CONTENT_TYPE'             => 'application/json',
        'CONTENT_LENGTH'           => strlen($body),
    ];

    $response = $this->call('POST', '/api/webhooks/whatsapp', [], [], [], $serverVars, $body);

    $response->assertStatus(200);

    Queue::assertPushed(\App\Jobs\Whatsapp\IngestWhatsappWebhookJob::class);
});

it('returns 403 when signature header is missing', function (): void {
    $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => []]);

    $response = $this->withHeaders([
        'Content-Type' => 'application/json',
    ])->call('POST', '/api/webhooks/whatsapp', [], [], [], [], $body);

    $response->assertStatus(403);
    $response->assertJson(['code' => 'WHATSAPP_SIGNATURE_INVALID']);

    Queue::assertNothingPushed();
});

it('returns 403 when signature header uses wrong secret', function (): void {
    $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => []]);

    $response = $this->withHeaders([
        'X-Hub-Signature-256' => makeSignature($body, 'wrong-secret'),
        'Content-Type'        => 'application/json',
    ])->call('POST', '/api/webhooks/whatsapp', [], [], [], [], $body);

    $response->assertStatus(403);

    Queue::assertNothingPushed();
});

it('returns 403 when signature header is malformed (no sha256= prefix)', function (): void {
    $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => []]);

    $response = $this->withHeaders([
        'X-Hub-Signature-256' => hash_hmac('sha256', $body, 'test-app-secret'),  // no prefix
        'Content-Type'        => 'application/json',
    ])->call('POST', '/api/webhooks/whatsapp', [], [], [], [], $body);

    $response->assertStatus(403);
});
