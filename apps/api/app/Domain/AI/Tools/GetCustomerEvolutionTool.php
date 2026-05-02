<?php

declare(strict_types=1);

namespace App\Domain\AI\Tools;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tool: get_customer_evolution (Phase 13 — §11.5).
 *
 * Returns the purchase_evolution_metrics rows (Phase 10) for a single
 * customer, scoped by RBAC. Returns an empty list if the metrics table
 * has not been populated yet.
 */
final class GetCustomerEvolutionTool implements LLMTool
{
    public function name(): string
    {
        return 'get_customer_evolution';
    }

    public function description(): string
    {
        return 'Returns the purchase evolution metrics for a single customer. The '
             . 'caller MUST have RBAC visibility on that customer; otherwise an '
             . 'empty result is returned.';
    }

    public function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'customer_id' => [
                    'type'        => 'string',
                    'pattern'     => '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$',
                    'description' => 'UUID v4 of the customer.',
                ],
            ],
            'required' => ['customer_id'],
        ];
    }

    public function execute(array $args, User $caller): array
    {
        $customerId = (string) ($args['customer_id'] ?? '');

        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $customerId)) {
            return ['rows' => []];
        }

        if (! Schema::hasTable('customers')) {
            return ['rows' => []];
        }

        // Check RBAC visibility before reading metrics.
        $visible = $this->customerVisibleToCaller($customerId, $caller);
        if (! $visible) {
            return ['rows' => [], 'denied' => true];
        }

        if (! Schema::hasTable('purchase_evolution_metrics')) {
            return ['rows' => []];
        }

        $rows = DB::table('purchase_evolution_metrics')
            ->where('customer_id', $customerId)
            ->orderByDesc('last_purchase_date')
            ->get(['product_id', 'last_purchase_date', 'avg_interval_days', 'last_interval_days', 'purchase_count', 'evolution_state'])
            ->map(static fn ($r) => [
                'product_id'         => (string) $r->product_id,
                'last_purchase_date' => $r->last_purchase_date,
                'avg_interval_days'  => $r->avg_interval_days !== null ? (float) $r->avg_interval_days : null,
                'last_interval_days' => $r->last_interval_days !== null ? (int) $r->last_interval_days : null,
                'purchase_count'     => (int) $r->purchase_count,
                'evolution_state'    => $r->evolution_state,
            ])->all();

        return ['rows' => $rows];
    }

    private function customerVisibleToCaller(string $customerId, User $caller): bool
    {
        $role = $caller->getAttribute('role');
        $role = $role instanceof UserRole ? $role : UserRole::from((string) $role);

        $q = DB::table('customers')->where('id', $customerId);

        match ($role) {
            UserRole::Director    => null,
            UserRole::Distributor => $q->where('zone_id', function ($sub) use ($caller) {
                $sub->select('id')->from('zones')->where('distributor_id', $caller->getKey())->limit(1);
            }),
            UserRole::Seller      => $q->where('assigned_seller_id', $caller->getKey()),
        };

        return $q->exists();
    }
}
