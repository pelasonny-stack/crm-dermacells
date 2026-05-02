<?php

declare(strict_types=1);

namespace App\Domain\AI\Tools;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tool: get_monthly_sales (Phase 13 — §11.5).
 *
 * Returns the sum of sales totals (delivered status) for the caller's
 * scope in a given month — used by the model to answer questions like
 * "¿cuál fue mi mejor mes del año?".
 */
final class GetMonthlySalesTool implements LLMTool
{
    public function name(): string
    {
        return 'get_monthly_sales';
    }

    public function description(): string
    {
        return 'Returns the sum of delivered sales totals for the caller\'s RBAC scope '
             . 'in a given (year, month). Currency is USD-equivalent if available, '
             . 'otherwise raw currency totals are returned per row.';
    }

    public function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'year'  => ['type' => 'integer', 'minimum' => 2000, 'maximum' => 2100],
                'month' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12],
            ],
            'required' => ['year', 'month'],
        ];
    }

    public function execute(array $args, User $caller): array
    {
        $year  = (int) ($args['year'] ?? Carbon::now()->year);
        $month = (int) ($args['month'] ?? Carbon::now()->month);

        if (! Schema::hasTable('sales')) {
            return ['totals' => [], 'year' => $year, 'month' => $month];
        }

        $start = Carbon::createFromDate($year, $month, 1)->startOfMonth()->toDateString();
        $end   = Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();

        $query = DB::table('sales')
            ->whereBetween('sale_date', [$start, $end])
            ->where('status', 'delivered');

        $this->scopeForCaller($query, $caller);

        $rows = $query->groupBy('total_currency')
            ->orderBy('total_currency')
            ->get([DB::raw('total_currency'), DB::raw('SUM(total_amount) as total')])
            ->map(static fn ($r) => [
                'currency' => (string) $r->total_currency,
                'total'    => (string) $r->total,
            ])->all();

        return [
            'totals' => $rows,
            'year'   => $year,
            'month'  => $month,
        ];
    }

    private function scopeForCaller($query, User $caller): void
    {
        $role = $caller->getAttribute('role');
        $role = $role instanceof UserRole ? $role : UserRole::from((string) $role);

        match ($role) {
            UserRole::Director    => null,
            UserRole::Distributor => $query->where('zone_id', function ($q) use ($caller) {
                $q->select('id')->from('zones')->where('distributor_id', $caller->getKey())->limit(1);
            }),
            UserRole::Seller      => $query->where('seller_id', $caller->getKey()),
        };
    }
}
