<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Meta\MetaClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware: validate Meta webhook HMAC-SHA256 signature (Phase 12).
 *
 * Applies to POST /webhooks/whatsapp only. GET (hub verification) does not
 * carry a signature header and must bypass this middleware — the route
 * applies it selectively (only to the POST route in the route group).
 *
 * IMPORTANT: Laravel reads the raw request body lazily. If another middleware
 * or service provider reads $request->getContent() before this middleware,
 * the body is cached and remains available. The signature is over the raw body
 * as received from Meta, so do NOT decode JSON before this middleware fires.
 * The route group in api.php ensures this middleware runs first.
 *
 * On signature failure: returns 403 with a structured JSON error body so
 * Meta's webhook testing tool shows a clear rejection reason.
 */
class WhatsappWebhookSignature
{
    public function __construct(
        private readonly MetaClient $client,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // GET requests (hub verification challenge) carry no signature — pass through.
        if ($request->isMethod('GET')) {
            return $next($request);
        }

        if (! $this->client->verifyWebhookSignature($request)) {
            return response()->json([
                'type'   => 'https://dermacells.com/errors/webhook-signature-invalid',
                'title'  => 'Webhook signature validation failed.',
                'status' => 403,
                'code'   => 'WHATSAPP_SIGNATURE_INVALID',
            ], 403, [], JSON_UNESCAPED_UNICODE);
        }

        return $next($request);
    }
}
