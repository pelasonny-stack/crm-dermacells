<?php

declare(strict_types=1);

namespace App\Domain\AI\Tools;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tool: query_customers_by_inactivity (Phase 13 — §11.5).
 *
 * Lists customers whose most recent delivered sale is older than
 * `min_days_inactive`. Scope:
 *   - Seller: only customers assigned to caller (assigned_seller_id = caller.id)
 *   - Distributor: customers in the caller's zone
 *   - Director: all customers
 */
final class QueryCustomersByInactivityTool implements LLMTool
{
    public function name(): string
    {
        return 'query_customers_by_inactivity';
    }

    public function description(): string
    {
        return 'Lists customers (within the caller\'s RBAC scope) whose last '
             . 'delivered sale is older than `min_days_inactive` days. Returns '
             . 'customer_id and last_delivered_date.';
    }

    public function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'min_days_inactive' => [
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => 3650,
                    'description' => 'Minimum days since last delivered sale.',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => 200,
                    'description' => 'Maximum number of rows to return.',
                ],
            ],
            'required' => ['min_days_inactive'],
        ];
    }

    public function execute(array $args, User $caller): array
    {
        $minDays = (int) ($args['min_days_inactive'] ?? 30);
        $limit   = max(1, min(200, (int) ($args['limit'] ?? 50)));
        $cutoff  = now()->subDays($minDays)->toDateString();

        if (! Schema::hasTable('customers')) {
            return ['rows' => [], 'count' => 0];
        }

        $query = DB::table('customers as c')
            ->leftJoin(DB::raw(<<<SQL
                (SELECT customer_id, MAX(sale_date) as last_sale_date
                   FROM sales
                  WHERE status = 'delivered'
                  GROUP BY customer_id) ls
            SQL), 'ls.customer_id', '=', 'c.id')
            ->where(function ($q) use ($cutoff): void {
                $q->whereNull('ls.last_sale_date')
                  ->orWhere('ls.last_sale_date', '<', $cutoff);
            })
            ->where('c.is_active', true);

        $this->scopeForCaller($query, $caller);

        $rows = $query->orderBy('ls.last_sale_date', 'asc')
            ->limit($limit)
            ->get(['c.id as customer_id', 'ls.last_sale_date'])
            ->map(static fn ($r) => [
                'customer_id'       => (string) $r->customer_id,
                'last_sale_date'    => $r->last_sale_date,
            ])->all();

        return ['rows' => $rows, 'count' => count($rows)];
    }

    /**
     * Apply RBAC scoping to the inactivity query.
     */
    private function scopeForCaller($query, User $caller): void
    {
        $role = $caller->getAttribute('role');
        $role = $role instanceof UserRole ? $role : UserRole::from((string) $role);

        match ($role) {
            UserRole::Director    => null,
            UserRole::Distributor => $query->where('c.zone_id', function ($q) use ($caller) {
                $q->select('id')->from('zones')->where('distributor_id', $caller->getKey())->limit(1);
            }),
            UserRole::Seller      => $query->where('c.assigned_seller_id', $caller->getKey()),
        };
    }
}
