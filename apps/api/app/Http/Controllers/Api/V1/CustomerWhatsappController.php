<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\WhatsappThread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer WhatsApp thread and message endpoints (Phase 12 — §17).
 *
 * ROUTES (registered in routes/api.php under auth:sanctum + idle):
 *   GET /api/v1/customers/{customer}/whatsapp/threads
 *   GET /api/v1/customers/{customer}/whatsapp/messages?cursor=...
 *
 * ACCESS CONTROL
 * ==============
 * RLS on whatsapp_threads and whatsapp_messages is enforced at the Postgres
 * layer by SetPostgresRlsContext middleware. Controllers do not need to
 * add additional visibility checks — the ORM will simply return 0 rows
 * for threads the authenticated user cannot see.
 *
 * We still fetch the Customer model to trigger 404 on unknown IDs and to
 * scope the thread lookup to the correct customer.
 *
 * PAGINATION
 * ==========
 * /messages uses cursor pagination (built-in Laravel cursorPaginate) so
 * the client can scroll backwards through the history efficiently without
 * OFFSET scans on potentially large tables.
 */
class CustomerWhatsappController extends Controller
{
    /**
     * GET /api/v1/customers/{customer}/whatsapp/threads
     *
     * Returns all WhatsApp threads for a customer. In most cases this is a
     * single-element array (one phone, one thread), but the schema allows
     * multiple threads if the customer contacts from different numbers.
     */
    public function threads(Request $request, Customer $customer): JsonResponse
    {
        $threads = WhatsappThread::where('customer_id', $customer->id)
            ->orderByDesc('last_message_at')
            ->get()
            ->map(fn (WhatsappThread $thread) => [
                'id'               => $thread->id,
                'wa_phone'         => $thread->wa_phone,
                'wa_contact_id'    => $thread->wa_contact_id,
                'last_message_at'  => $thread->last_message_at?->toIso8601String(),
                'last_inbound_at'  => $thread->last_inbound_at?->toIso8601String(),
                'last_outbound_at' => $thread->last_outbound_at?->toIso8601String(),
                // Deep-link for outbound contact (MVP — no template API yet).
                'wa_deeplink'      => 'https://wa.me/' . $thread->wa_phone,
            ]);

        return response()->json([
            'data' => $threads,
            'meta' => [
                'customer_id' => $customer->id,
                'total'       => $threads->count(),
            ],
        ]);
    }

    /**
     * GET /api/v1/customers/{customer}/whatsapp/messages?cursor=...
     *
     * Cursor-paginated message history across all threads of a customer.
     * Messages are ordered newest-first (sent_at DESC) to match the
     * typical chat-scroll UX.
     *
     * Query params:
     *   cursor   — opaque cursor from previous response (optional)
     *   per_page — items per page, default 25, max 100
     */
    public function messages(Request $request, Customer $customer): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);

        // Collect thread IDs for this customer.
        // RLS ensures the customer itself is accessible; threads further filter
        // by customer_id — defense-in-depth on top of the Postgres policy.
        $threadIds = WhatsappThread::where('customer_id', $customer->id)
            ->pluck('id');

        if ($threadIds->isEmpty()) {
            return response()->json([
                'data'     => [],
                'meta'     => ['customer_id' => $customer->id],
                'links'    => ['next' => null, 'prev' => null],
            ]);
        }

        $paginator = \App\Models\WhatsappMessage::whereIn('thread_id', $threadIds)
            ->orderByDesc('sent_at')
            ->cursorPaginate($perPage);

        $items = collect($paginator->items())->map(fn (\App\Models\WhatsappMessage $msg) => [
            'id'            => $msg->id,
            'thread_id'     => $msg->thread_id,
            'direction'     => $msg->direction,
            'body'          => $msg->body,  // decrypted via cast
            'media_url'     => $msg->media_url,
            'message_type'  => $msg->message_type,
            'sent_at'       => $msg->sent_at->toIso8601String(),
        ]);

        return response()->json([
            'data'  => $items,
            'meta'  => ['customer_id' => $customer->id],
            'links' => [
                'next' => $paginator->nextPageUrl(),
                'prev' => $paginator->previousPageUrl(),
            ],
        ]);
    }
}
