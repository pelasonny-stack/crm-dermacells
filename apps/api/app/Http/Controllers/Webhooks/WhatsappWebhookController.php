<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\Whatsapp\IngestWhatsappWebhookJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Handles incoming Meta Cloud API WhatsApp webhook events (Phase 12 — §17).
 *
 * ROUTES (registered outside the v1 auth group — public, no Sanctum):
 *   GET  /api/webhooks/whatsapp  → verify()   hub challenge response
 *   POST /api/webhooks/whatsapp  → receive()  event ingestion
 *
 * VERIFY (GET)
 * ============
 * Meta sends a one-time GET with query params:
 *   hub.mode          = 'subscribe'
 *   hub.verify_token  = the token configured in the Meta App Dashboard
 *   hub.challenge     = a random string Meta expects echoed back
 *
 * We validate hub.verify_token against config('services.whatsapp.verify_token')
 * and echo hub.challenge as plain text (200). Wrong token → 403.
 *
 * RECEIVE (POST)
 * ==============
 * Meta delivers events as JSON. The signature is already validated by the
 * WhatsappWebhookSignature middleware before this method runs. We dispatch
 * a queued job immediately and respond 200 — Meta considers any non-200
 * response a failure and will retry with exponential back-off, so we must
 * respond 200 even if the job later fails (it has its own retry logic).
 */
class WhatsappWebhookController extends Controller
{
    /**
     * GET /api/webhooks/whatsapp — Meta hub challenge verification.
     */
    public function verify(Request $request): Response|JsonResponse
    {
        $mode        = $request->query('hub_mode', $request->query('hub.mode'));
        $verifyToken = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge   = $request->query('hub_challenge', $request->query('hub.challenge'));

        $configuredToken = config('services.whatsapp.verify_token');

        if ($mode === 'subscribe' && $verifyToken === $configuredToken) {
            return response((string) $challenge, 200)
                ->header('Content-Type', 'text/plain');
        }

        return response()->json([
            'type'   => 'https://dermacells.com/errors/webhook-verify-failed',
            'title'  => 'Hub verification failed: invalid verify_token.',
            'status' => 403,
            'code'   => 'WHATSAPP_VERIFY_TOKEN_MISMATCH',
        ], 403);
    }

    /**
     * POST /api/webhooks/whatsapp — ingest a Meta event payload.
     *
     * Dispatches IngestWhatsappWebhookJob and always returns 200 so Meta does
     * not retry the delivery. If the job fails (DB error, dedup violation, etc.)
     * Horizon will retry according to the job's own retry configuration.
     */
    public function receive(Request $request): Response
    {
        $payload = $request->all();

        IngestWhatsappWebhookJob::dispatch($payload);

        return response('', 200);
    }
}
