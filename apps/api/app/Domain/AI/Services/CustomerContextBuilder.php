<?php

declare(strict_types=1);

namespace App\Domain\AI\Services;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * CustomerContextBuilder — assembles the per-customer context block injected
 * into LLM prompts (Phase 13 — §11.5).
 *
 * For a (Customer, Seller perspective) pair the builder returns a
 * JSON-serialisable array containing exactly the data the model needs to
 * recommend a next action for THAT customer:
 *
 *   - Customer profile (id, name, contact, category, zone, frequency)
 *   - Last 20 sale_items (delivered sales only, joined on sale_id)
 *   - Evolution metrics from purchase_evolution_metrics (Phase 10)
 *     — gracefully empty if the table is absent (subagent ordering)
 *   - Last 10 WhatsApp messages from the Phase 12 schema
 *     — gracefully empty if whatsapp_messages does not yet exist
 *   - Open scheduled_actions notes (notes the seller has logged)
 *
 * NEVER includes data from other customers — every query is filtered by
 * customer_id. This is the first defence line; LeakGuard is the second
 * (response-side scan).
 */
final class CustomerContextBuilder
{
    /**
     * Builds the context array for a (customer, seller-perspective) pair.
     *
     * @return array<string, mixed>
     */
    public function build(Customer $customer, User $seller): array
    {
        return [
            'customer'             => $this->profile($customer),
            'last_transactions'    => $this->lastTransactions($customer->getKey()),
            'evolution_metrics'    => $this->evolutionMetrics($customer->getKey()),
            'whatsapp_messages'    => $this->lastWhatsappMessages($customer->getKey()),
            'open_notes'           => $this->openScheduledNotes($customer->getKey()),
            'seller_perspective'   => [
                'id'        => $seller->getKey(),
                'full_name' => $seller->getAttribute('full_name'),
                'role'      => $this->roleString($seller),
            ],
        ];
    }

    /**
     * Renders the context as a deterministic JSON string suitable for
     * direct injection into the systemContext field of LLMRequest.
     */
    public function renderJson(Customer $customer, User $seller): string
    {
        $arr = $this->build($customer, $seller);

        // JSON_UNESCAPED_UNICODE keeps Spanish characters readable and the
        // payload smaller than escape sequences. Sorting keys (via PRESERVE
        // here intentionally NOT — order matches build()) keeps the string
        // stable for prompt-cache hits.
        return (string) json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------
    // Internal queries
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function profile(Customer $customer): array
    {
        return [
            'id'                       => $customer->getKey(),
            'first_name'               => $customer->first_name,
            'last_name'                => $customer->last_name,
            'cuit'                     => $customer->cuit ?? null,
            'phone'                    => $customer->phone ?? null,
            'email'                    => $customer->email ?? null,
            'category_id'              => $customer->category_id ?? null,
            'zone_id'                  => $customer->zone_id ?? null,
            'assigned_seller_id'       => $customer->assigned_seller_id ?? null,
            'purchase_frequency_days'  => $customer->purchase_frequency_days ?? null,
            'first_purchase_date'      => optional($customer->first_purchase_date)->toDateString(),
            'is_active'                => (bool) ($customer->is_active ?? true),
        ];
    }

    /**
     * Last 20 sale items joined to delivered sales for this customer.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lastTransactions(string $customerId): array
    {
        if (! Schema::hasTable('sales') || ! Schema::hasTable('sale_items')) {
            return [];
        }

        $rows = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.customer_id', $customerId)
            ->where('s.status', 'delivered')
            ->orderByDesc('s.sale_date')
            ->orderByDesc('si.created_at')
            ->limit(20)
            ->get([
                'si.id',
                'si.product_id',
                'si.quantity_boxes',
                'si.quantity_units',
                'si.unit_price_amount',
                'si.unit_price_currency',
                'si.subtotal_amount',
                's.id as sale_id',
                's.sale_date',
                's.total_amount as sale_total',
                's.total_currency as sale_currency',
            ]);

        return $rows->map(static fn ($r) => [
            'sale_id'             => $r->sale_id,
            'sale_date'           => (string) $r->sale_date,
            'product_id'          => $r->product_id,
            'quantity_boxes'      => (int) $r->quantity_boxes,
            'quantity_units'      => (int) $r->quantity_units,
            'unit_price_amount'   => (string) $r->unit_price_amount,
            'unit_price_currency' => (string) $r->unit_price_currency,
            'subtotal_amount'     => (string) $r->subtotal_amount,
            'sale_total'          => (string) $r->sale_total,
            'sale_currency'       => (string) $r->sale_currency,
        ])->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function evolutionMetrics(string $customerId): array
    {
        if (! Schema::hasTable('purchase_evolution_metrics')) {
            return [];
        }

        $rows = DB::table('purchase_evolution_metrics')
            ->where('customer_id', $customerId)
            ->orderByDesc('last_purchase_date')
            ->get([
                'product_id',
                'last_purchase_date',
                'avg_interval_days',
                'last_interval_days',
                'purchase_count',
                'evolution_state',
                'computed_at',
            ]);

        return $rows->map(static fn ($r) => [
            'product_id'          => $r->product_id,
            'last_purchase_date'  => $r->last_purchase_date,
            'avg_interval_days'   => $r->avg_interval_days !== null ? (float) $r->avg_interval_days : null,
            'last_interval_days'  => $r->last_interval_days !== null ? (int) $r->last_interval_days : null,
            'purchase_count'      => (int) $r->purchase_count,
            'evolution_state'     => $r->evolution_state,
        ])->all();
    }

    /**
     * Last 10 WhatsApp messages — gracefully empty when Phase 12 hasn't
     * landed the messages table yet.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lastWhatsappMessages(string $customerId): array
    {
        if (! Schema::hasTable('whatsapp_threads') || ! Schema::hasTable('whatsapp_messages')) {
            return [];
        }

        $rows = DB::table('whatsapp_messages as m')
            ->join('whatsapp_threads as t', 't.id', '=', 'm.thread_id')
            ->where('t.customer_id', $customerId)
            ->orderByDesc('m.created_at')
            ->limit(10)
            ->get([
                'm.id',
                'm.direction',
                'm.body',
                'm.created_at',
            ]);

        return $rows->map(static fn ($r) => [
            'id'         => $r->id,
            'direction'  => $r->direction,
            'body'       => $r->body,
            'created_at' => (string) $r->created_at,
        ])->all();
    }

    /**
     * Open notes from scheduled_actions (unresolved). These are the
     * Seller-authored hints carried in the customer ficha.
     *
     * @return array<int, array<string, mixed>>
     */
    private function openScheduledNotes(string $customerId): array
    {
        if (! Schema::hasTable('scheduled_actions')) {
            return [];
        }

        $rows = DB::table('scheduled_actions')
            ->where('customer_id', $customerId)
            ->where('is_resolved', false)
            ->orderBy('scheduled_date')
            ->limit(20)
            ->get(['id', 'scheduled_date', 'note']);

        return $rows->map(static fn ($r) => [
            'id'             => $r->id,
            'scheduled_date' => (string) $r->scheduled_date,
            'note'           => $r->note,
        ])->all();
    }

    private function roleString(User $user): string
    {
        $r = $user->getAttribute('role');
        return is_object($r) ? (string) $r->value : (string) $r;
    }
}
