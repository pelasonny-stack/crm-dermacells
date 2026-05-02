<?php

declare(strict_types=1);

namespace App\Services\Meta;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use LogicException;

/**
 * Thin client for Meta's WhatsApp Business Cloud API (Phase 12 — §17).
 *
 * BASE URL
 * ========
 * Defaults to https://graph.facebook.com/v20.0 — overridable via
 * WHATSAPP_GRAPH_BASE_URL for tests and future API version bumps.
 *
 * OUTBOUND MVP NOTE
 * =================
 * Outbound message sending (sendMessage) is scaffolded but NOT implemented
 * for Phase 12 MVP. The method throws a LogicException so callers fail fast
 * with a meaningful message rather than a 404 from Meta.
 *
 * WEBHOOK SIGNATURE VERIFICATION
 * ===============================
 * Meta signs each POST webhook with HMAC-SHA256 of the raw request body,
 * using the App Secret as the key. The signature is delivered in the
 * X-Hub-Signature-256 header as "sha256=<hex>". The middleware
 * WhatsappWebhookSignature calls verifyWebhookSignature() and rejects
 * payloads that fail.
 *
 * DEPENDENCY INJECTION
 * ====================
 * Bind this class as a singleton in AppServiceProvider:
 *   $this->app->singleton(MetaClient::class);
 * It is stateless and safe to share across requests.
 */
class MetaClient
{
    private string $baseUrl;
    private string $phoneNumberId;
    private string $accessToken;

    public function __construct()
    {
        $this->baseUrl       = rtrim((string) config('services.whatsapp.graph_base_url'), '/');
        $this->phoneNumberId = (string) config('services.whatsapp.phone_number_id');
        $this->accessToken   = (string) config('services.whatsapp.access_token');
    }

    /**
     * Verify the X-Hub-Signature-256 header against the raw request body.
     *
     * Uses HMAC-SHA256 with WHATSAPP_APP_SECRET as the key and compares
     * using hash_equals() to prevent timing attacks.
     *
     * Config is read lazily here (not cached in constructor) so that
     * test-time Config::set() overrides take effect without needing to
     * re-bind the singleton.
     *
     * Returns false if the header is absent or the signature does not match.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        $header = $request->header('X-Hub-Signature-256', '');

        if (! str_starts_with((string) $header, 'sha256=')) {
            return false;
        }

        $receivedHex = substr((string) $header, strlen('sha256='));

        // Read app_secret lazily so Config::set() in tests takes effect.
        $appSecret = (string) config('services.whatsapp.app_secret');

        $expectedHex = hash_hmac('sha256', $request->getContent(), $appSecret);

        return hash_equals($expectedHex, $receivedHex);
    }

    /**
     * Send an outbound text or template message to a WhatsApp number.
     *
     * NOT IMPLEMENTED in Phase 12 MVP. The CRM only ingests inbound messages
     * for now. Outbound (including template approval workflow) is deferred to
     * a future phase.
     *
     * @throws LogicException always — placeholder for Phase 13+
     */
    public function sendMessage(string $toPhone, string $body): never
    {
        throw new LogicException(
            'WhatsApp outbound messaging is not implemented in Phase 12 MVP. ' .
            'Use the wa.me deep-link from the customer ficha for manual outbound contact.'
        );
    }

    /**
     * Retrieve the download URL for a media attachment.
     *
     * Meta returns a short-lived URL in the webhook payload for media
     * messages (image, document, audio). This method fetches the actual
     * download URL by querying the media ID endpoint.
     *
     * @param string $mediaId  The media ID from the webhook payload.
     * @return string          The download URL (expires after 5 minutes).
     *
     * @throws \Illuminate\Http\Client\RequestException on HTTP error.
     */
    public function getMediaUrl(string $mediaId): string
    {
        $response = Http::withToken($this->accessToken)
            ->get("{$this->baseUrl}/{$mediaId}")
            ->throw();

        return (string) $response->json('url');
    }
}
