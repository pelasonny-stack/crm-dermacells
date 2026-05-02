<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Services;

use App\Data\Dashboards\DirectorDashboardData;
use App\Data\Dashboards\DistributorDashboardData;
use App\Data\Dashboards\SellerDashboardData;
use App\Domain\Dashboards\Queries\AiUsageMonthQuery;
use App\Domain\Dashboards\Queries\BirthdaysTodayQuery;
use App\Domain\Dashboards\Queries\DuePaymentsWithinHoursQuery;
use App\Domain\Dashboards\Queries\IntegrationStatusQuery;
use App\Domain\Dashboards\Queries\MonthlyCommissionQuery;
use App\Domain\Dashboards\Queries\MonthlySalesProgressQuery;
use App\Domain\Dashboards\Queries\PortfolioHealthQuery;
use App\Domain\Dashboards\Queries\RankingDistributorsQuery;
use App\Domain\Dashboards\Queries\StockSnapshotQuery;
use App\Domain\Dashboards\Queries\TodayAlertsQuery;
use App\Domain\Dashboards\Queries\ZoneRankingQuery;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DashboardAssembler — Phase 14 thin orchestrator (§14).
 *
 * Switches on the user's role and delegates all data fetching to dedicated
 * query services. The assembler itself contains no SQL — it only wires
 * query results into the matching DTO.
 *
 * Each assembleFor* method is kept to ~30 lines; heavy aggregations live in
 * the Query classes under App\Domain\Dashboards\Queries.
 */
final class DashboardAssembler
{
    public function __construct(
        private readonly TodayAlertsQuery          $alertsQuery,
        private readonly BirthdaysTodayQuery       $birthdaysQuery,
        private readonly DuePaymentsWithinHoursQuery $duePaymentsQuery,
        private readonly MonthlyCommissionQuery    $commissionQuery,
        private readonly MonthlySalesProgressQuery $salesProgressQuery,
        private readonly StockSnapshotQuery        $stockQuery,
        private readonly ZoneRankingQuery          $zoneRankingQuery,
        private readonly RankingDistributorsQuery  $distRankingQuery,
        private readonly PortfolioHealthQuery      $portfolioQuery,
        private readonly IntegrationStatusQuery    $integrationQuery,
        private readonly AiUsageMonthQuery         $aiUsageQuery,
    ) {}

    /**
     * Resolves the correct DTO based on the user's role.
     *
     * @return SellerDashboardData|DistributorDashboardData|DirectorDashboardData
     */
    public function assembleForUser(User $user): SellerDashboardData|DistributorDashboardData|DirectorDashboardData
    {
        return match ($user->role) {
            UserRole::Seller      => $this->assembleForSeller($user),
            UserRole::Distributor => $this->assembleForDistributor($user),
            UserRole::Director    => $this->assembleForDirector($user),
        };
    }

    // =========================================================================
    // Role-specific assemblers
    // =========================================================================

    private function assembleForSeller(User $user): SellerDashboardData
    {
        $sellerId  = (string) $user->getKey();
        $thisMonth = Carbon::now();
        $priorMonth = Carbon::now()->subMonth();

        $alerts       = $this->alertsQuery->get($sellerId);
        $birthdays    = $this->birthdaysQuery->get($sellerId);
        $duePayments  = $this->duePaymentsQuery->get($sellerId, 48);
        $commission   = $this->commissionQuery->get($user, $thisMonth);
        $sales        = $this->salesProgressQuery->forSeller($sellerId, $thisMonth);
        $salesPrior   = $this->salesProgressQuery->forSeller($sellerId, $priorMonth);
        $stock        = $this->stockQuery->forSeller($sellerId);
        $semaphore    = $this->buildSellerSemaphore($sellerId);
        $goal         = $this->sellerGoal($sellerId, $thisMonth);
        $pendingAuths = $this->sellerPendingAuthorizations($sellerId);
        $confirmedPending = $this->confirmedPendingSeller($sellerId);
        $aiRec        = $this->aiRecommendation($sellerId);

        return new SellerDashboardData(
            today_alerts:                    $alerts->map(fn ($r) => (array) $r)->toArray(),
            today_birthdays:                 $birthdays->map(fn ($r) => (array) $r)->toArray(),
            due_payments_48h:                $duePayments->map(fn ($r) => (array) $r)->toArray(),
            ai_recommendation:               $aiRec,
            month_collected_usd:             $commission->accumulated_usd->getAmount()->__toString(),
            month_collected_ars:             $commission->commission_ars->getAmount()->__toString(),
            commission_accumulated_usd:      $commission->commission_usd->getAmount()->__toString(),
            commission_accumulated_ars:      $commission->commission_ars->getAmount()->__toString(),
            commission_tier_rate:            $commission->tier_rate->__toString(),
            sales_month_count:               $sales['count'],
            sales_month_amount:              $sales['amount'],
            sales_prior_month_count:         $salesPrior['count'],
            sales_prior_month_amount:        $salesPrior['amount'],
            pending_collections_semaphore:   $semaphore,
            goal_boxes_target:               $goal['target'],
            goal_boxes_achieved:             $sales['boxes'],
            stock_per_product:               $stock->map(fn ($r) => (array) $r)->toArray(),
            confirmed_pending_delivery_count: $confirmedPending,
            my_pending_authorizations:       $pendingAuths,
        );
    }

    private function assembleForDistributor(User $user): DistributorDashboardData
    {
        $distId = (string) $user->getKey();
        $stock  = $this->stockQuery->forDistributor($distId);
        $rank   = $this->zoneRankingQuery->forDistributor($distId);
        $evol6m = $this->zoneSalesEvolution6m($distId);
        $cobros6m = $this->zoneCobrosEvolution6m($distId);
        $account  = $this->distributorAccount($distId);
        $settlements = $this->lastSettlements($distId);
        $cobrosModal = $this->cobrosModality($distId);
        $urgentClients = $this->zoneUrgentCustomers($distId);
        $lowStock = $this->zoneSellersLowStock($distId);
        $overdueZone = $this->zoneOverduePayments($distId);
        $balanceEvol = $this->distributorBalanceEvolution6m($distId);

        $zoneSales = $this->zoneMonthlySales($distId);

        return new DistributorDashboardData(
            zone_urgent_customers:   $urgentClients,
            zone_sellers_low_stock:  $lowStock,
            zone_overdue_payments:   $overdueZone,
            zone_sales_month_boxes:  $zoneSales['boxes'],
            zone_sales_month_amount: $zoneSales['amount'],
            seller_ranking:          $rank->map(fn ($r) => (array) $r)->toArray(),
            cobros_by_modality:      $cobrosModal,
            zone_sales_evolution_6m: $evol6m,
            zone_cobros_evolution_6m: $cobros6m,
            saldo_a_rendir_ars:      $account['saldo_ars'],
            saldo_a_rendir_usd:      $account['saldo_usd'],
            margin_bruto_mes_ars:    $account['margin_ars'],
            margin_bruto_mes_usd:    $account['margin_usd'],
            commissions_to_pay_ars:  $account['commissions_ars'],
            commissions_to_pay_usd:  $account['commissions_usd'],
            last_settlements:        $settlements,
            balance_evolution_6m:    $balanceEvol,
            own_stock_per_product:   $stock['own']->map(fn ($r) => (array) $r)->toArray(),
            sellers_stock_in_zone:   $stock['sellers']->map(fn ($r) => (array) $r)->toArray(),
            zone_stock_alerts:       $this->zoneStockAlerts($distId),
        );
    }

    private function assembleForDirector(User $user): DirectorDashboardData
    {
        $now        = Carbon::now();
        $priorMonth = Carbon::now()->subMonth();

        $pulse      = $this->directorPulseToday();
        $sales      = $this->salesProgressQuery->global($now);
        $priorSales = $this->salesProgressQuery->global($priorMonth);
        $sellerRank = $this->zoneRankingQuery->global();
        $distRank   = $this->distRankingQuery->get();
        $portfolio  = $this->portfolioQuery->evolution();
        $zonePen    = $this->portfolioQuery->zonePenetration();
        $atRisk     = $this->portfolioQuery->atRiskCustomers();
        $top10      = $this->portfolioQuery->top10Customers();
        $central    = $this->stockQuery->central();
        $critical   = $this->stockQuery->criticalActors();
        $integr     = $this->integrationQuery->get();
        $ai         = $this->aiUsageQuery->get($now);
        $alerts     = $this->alertsQuery->get((string) $user->getKey(), 50);
        $critAlerts = collect($alerts)->where('severity', 'critical')->values();
        $recentAuths = $this->recentAuthorizations();
        $goalSema   = $this->goalSemaphore($now);
        $settlements = $this->settlementStatusPerZone();
        $cobrosEvol = $this->globalCobrosEvolution6m();
        $overdueInfo = $this->globalOverdueInfo();
        $topOverdue = $this->topOverdueCustomers();
        $pendingAuths = $this->countPendingAuthorizations();
        $confirmedPending = $this->globalConfirmedPending();
        $staleDrafts = $this->staleDraftsCount();

        return new DirectorDashboardData(
            today_sales_count:                $pulse['sales_count'],
            today_sales_boxes:                $pulse['sales_boxes'],
            today_sales_amount:               $pulse['sales_amount'],
            today_cobros_ars:                 $pulse['cobros_ars'],
            today_cobros_usd:                 $pulse['cobros_usd'],
            pending_authorizations_count:     $pendingAuths,
            critical_alerts:                  $critAlerts->map(fn ($r) => (array) $r)->toArray(),
            month_sales_amount:               $sales['amount'],
            prior_month_sales_amount:         $priorSales['amount'],
            month_sales_boxes:                $sales['boxes'],
            prior_month_sales_boxes:          $priorSales['boxes'],
            seller_ranking_global:            $sellerRank->map(fn ($r) => (array) $r)->toArray(),
            distributor_ranking:              $distRank->map(fn ($r) => (array) $r)->toArray(),
            goal_semaphore:                   $goalSema,
            portfolio_evolution_12m:          $portfolio->map(fn ($r) => (array) $r)->toArray(),
            zone_penetration:                 $zonePen->map(fn ($r) => (array) $r)->toArray(),
            at_risk_customers:                $atRisk->map(fn ($r) => (array) $r)->toArray(),
            top10_customers:                  $top10->map(fn ($r) => (array) $r)->toArray(),
            central_stock:                    $central->map(fn ($r) => (array) $r)->toArray(),
            critical_stock_actors:            $critical->map(fn ($r) => (array) $r)->toArray(),
            confirmed_pending_delivery_global: $confirmedPending,
            stale_drafts_count:               $staleDrafts,
            settlement_status_per_zone:       $settlements,
            global_overdue_amount:            $overdueInfo['amount'],
            global_overdue_customers_count:   $overdueInfo['customers'],
            cobros_evolution_6m:              $cobrosEvol,
            top_overdue_customers:            $topOverdue,
            ai_active:                        $ai['active'],
            ai_tokens_month:                  $ai['tokens'],
            ai_cost_month_usd:                $ai['cost_usd'],
            integration_status:               $integr,
            recent_authorizations:            $recentAuths,
        );
    }

    // =========================================================================
    // Private helpers (all use raw DB facade, no Eloquent hydration)
    // =========================================================================

    /**
     * Reads today's aggregation from mv_director_pulse_today.
     * Falls back to live query if MV is empty (first run).
     *
     * @return array{sales_count:int, sales_boxes:int, sales_amount:string, cobros_ars:string, cobros_usd:string}
     */
    private function directorPulseToday(): array
    {
        try {
            $row = DB::table('mv_director_pulse_today')
                ->where('pulse_date', now()->toDateString())
                ->first();

            if ($row) {
                return [
                    'sales_count'   => (int) $row->sales_count,
                    'sales_boxes'   => (int) $row->sales_boxes,
                    'sales_amount'  => (string) $row->sales_amount,
                    'cobros_ars'    => (string) $row->cobros_ars,
                    'cobros_usd'    => (string) $row->cobros_usd,
                ];
            }
        } catch (\Throwable) {
            // MV not available yet.
        }

        // Live fallback
        $today = now()->toDateString();

        $sales = DB::table('sales')
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.status', 'delivered')
            ->whereDate('sales.delivered_at', $today)
            ->selectRaw('
                COUNT(DISTINCT sales.id) AS cnt,
                SUM(sale_items.boxes) AS boxes,
                SUM(sales.total_amount) AS amount
            ')
            ->first();

        $cobros = DB::table('payments')
            ->where('reversed', false)
            ->whereDate('payment_date', $today)
            ->selectRaw('
                SUM(CASE WHEN currency = \'ARS\' THEN amount ELSE 0 END) AS ars,
                SUM(CASE WHEN currency = \'USD\' THEN amount ELSE 0 END) AS usd
            ')
            ->first();

        return [
            'sales_count'  => (int) ($sales->cnt ?? 0),
            'sales_boxes'  => (int) ($sales->boxes ?? 0),
            'sales_amount' => number_format((float) ($sales->amount ?? 0), 4, '.', ''),
            'cobros_ars'   => number_format((float) ($cobros->ars ?? 0), 4, '.', ''),
            'cobros_usd'   => number_format((float) ($cobros->usd ?? 0), 4, '.', ''),
        ];
    }

    /** @return array{target:int, achieved:int} */
    private function sellerGoal(string $sellerId, Carbon $month): array
    {
        $row = DB::table('seller_monthly_goals')
            ->where('seller_id', $sellerId)
            ->where('period_month', $month->copy()->startOfMonth()->toDateString())
            ->first(['goal_boxes']);

        return ['target' => (int) ($row?->goal_boxes ?? 0), 'achieved' => 0];
    }

    /** @return array{al_dia:int, proximo_a_vencer:int, vencido:int} */
    private function buildSellerSemaphore(string $sellerId): array
    {
        $now    = now();
        $in48h  = now()->addHours(48);

        $rows = DB::table('sales')
            ->where('seller_id', $sellerId)
            ->whereIn('status', ['confirmed', 'delivered'])
            ->whereNotNull('due_date')
            ->whereRaw(
                'total_amount > COALESCE((
                    SELECT SUM(p.amount) FROM payments p
                    WHERE p.sale_id = sales.id AND p.reversed = false AND p.currency = sales.currency
                ), 0)'
            )
            ->selectRaw("
                SUM(CASE WHEN due_date > ? THEN 1 ELSE 0 END) AS al_dia,
                SUM(CASE WHEN due_date BETWEEN ? AND ? THEN 1 ELSE 0 END) AS proximo,
                SUM(CASE WHEN due_date < ? THEN 1 ELSE 0 END) AS vencido
            ", [$in48h, $now, $in48h, $now])
            ->first();

        return [
            'al_dia'           => (int) ($rows->al_dia ?? 0),
            'proximo_a_vencer' => (int) ($rows->proximo ?? 0),
            'vencido'          => (int) ($rows->vencido ?? 0),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function sellerPendingAuthorizations(string $sellerId): array
    {
        return DB::table('authorization_requests')
            ->where('requester_id', $sellerId)
            ->where('status', 'pending')
            ->get(['id', 'type', 'current_value', 'proposed_value', 'created_at'])
            ->map(fn ($r) => (array) $r)
            ->toArray();
    }

    private function confirmedPendingSeller(string $sellerId): int
    {
        return (int) DB::table('sales')
            ->where('seller_id', $sellerId)
            ->where('status', 'confirmed')
            ->count();
    }

    private function aiRecommendation(string $sellerId): ?string
    {
        // Read from cache set by GenerateDailyDigestForAllSellers (Phase 13).
        $cacheKey = "daily_digest.seller.{$sellerId}." . now()->toDateString();

        return \Illuminate\Support\Facades\Cache::get($cacheKey);
    }

    /** @return array<int,array<string,mixed>> */
    private function zoneSalesEvolution6m(string $distId): array
    {
        return DB::table('sales')
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->join('zones', 'zones.id', '=', 'sales.zone_id')
            ->where('zones.distributor_id', $distId)
            ->where('sales.status', 'delivered')
            ->where('sales.delivered_at', '>=', now()->subMonths(6)->startOfMonth())
            ->selectRaw("
                TO_CHAR(DATE_TRUNC('month', sales.delivered_at), 'YYYY-MM') AS month,
                SUM(sale_items.boxes) AS boxes,
                SUM(sales.total_amount) AS amount
            ")
            ->groupByRaw("DATE_TRUNC('month', sales.delivered_at)")
            ->orderByRaw("DATE_TRUNC('month', sales.delivered_at) ASC")
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();
    }

    /** @return array<int,array<string,mixed>> */
    private function zoneCobrosEvolution6m(string $distId): array
    {
        return DB::table('payments')
            ->join('sales', 'sales.id', '=', 'payments.sale_id')
            ->join('zones', 'zones.id', '=', 'sales.zone_id')
            ->where('zones.distributor_id', $distId)
            ->where('payments.reversed', false)
            ->where('payments.payment_date', '>=', now()->subMonths(6)->startOfMonth()->toDateString())
            ->selectRaw("
                TO_CHAR(DATE_TRUNC('month', payments.payment_date::date), 'YYYY-MM') AS month,
                SUM(CASE WHEN payments.currency = 'ARS' THEN payments.amount ELSE 0 END) AS ars,
                SUM(CASE WHEN payments.currency = 'USD' THEN payments.amount ELSE 0 END) AS usd
            ")
            ->groupByRaw("DATE_TRUNC('month', payments.payment_date::date)")
            ->orderByRaw("DATE_TRUNC('month', payments.payment_date::date) ASC")
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();
    }

    /** @return array{saldo_ars:string, saldo_usd:string, margin_ars:string, margin_usd:string, commissions_ars:string, commissions_usd:string} */
    private function distributorAccount(string $distId): array
    {
        $account = DB::table('distributor_account')
            ->where('distributor_id', $distId)
            ->first([
                'balance_ars',
                'balance_usd',
                'margin_ars',
                'margin_usd',
                'commissions_pending_ars',
                'commissions_pending_usd',
            ]);

        return [
            'saldo_ars'       => number_format((float) ($account?->balance_ars ?? 0), 4, '.', ''),
            'saldo_usd'       => number_format((float) ($account?->balance_usd ?? 0), 4, '.', ''),
            'margin_ars'      => number_format((float) ($account?->margin_ars ?? 0), 4, '.', ''),
            'margin_usd'      => number_format((float) ($account?->margin_usd ?? 0), 4, '.', ''),
            'commissions_ars' => number_format((float) ($account?->commissions_pending_ars ?? 0), 4, '.', ''),
            'commissions_usd' => number_format((float) ($account?->commissions_pending_usd ?? 0), 4, '.', ''),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function lastSettlements(string $distId): array
    {
        return DB::table('distributor_settlements')
            ->where('distributor_id', $distId)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'amount', 'currency', 'status', 'created_at', 'confirmed_at'])
            ->map(fn ($r) => (array) $r)
            ->toArray();
    }

    /** @return array<int,array<string,mixed>> */
    private function cobrosModality(string $distId): array
    {
        $start = now()->startOfMonth()->toDateString();
        $end   = now()->endOfMonth()->toDateString();

        return DB::table('payments')
            ->join('sales', 'sales.id', '=', 'payments.sale_id')
            ->join('zones', 'zones.id', '=', 'sales.zone_id')
            ->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->where('zones.distributor_id', $distId)
            ->whereBetween('payments.payment_date', [$start, $end])
            ->where('payments.reversed', false)
            ->selectRaw('
                payment_methods.code AS modality,
                SUM(CASE WHEN payments.currency = \'ARS\' THEN payments.amount ELSE 0 END) AS ars,
                SUM(CASE WHEN payments.currency = \'USD\' THEN payments.amount ELSE 0 END) AS usd,
                COUNT(*) AS transactions
            ')
            ->groupBy('payment_methods.code')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();
    }

    /** @return array<int,array<string,mixed>> */
    private function zoneUrgentCustomers(string $distId): array
    {
        return DB::table('purchase_evolution_metrics')
            ->join('customers', 'customers.id', '=', 'purchase_evolution_metrics.customer_id')
            ->join('zones', 'zones.id', '=', 'customers.zone_id')
            ->where('zones.distributor_id', $distId)
            ->whereIn('purchase_evolution_metrics.evolution_state', ['inactive', 'decreasing'])
            ->orderByRaw(
                "CASE purchase_evolution_metrics.evolution_state WHEN 'inactive' THEN 1 ELSE 2 END ASC"
            )
            ->limit(5)
            ->get([
                'customers.id',
                'customers.first_name',
                'customers.last_name',
                'purchase_evolution_metrics.evolution_state',
                'purchase_evolution_metrics.days_since_last_purchase',
            ])
            ->map(fn ($r) => (array) $r)
            ->toArray();
    }

    /** @return array<int,array<string,mixed>> */
    private function zoneSellersLowStock(string $distId): array
    {
        return DB::table('seller_stock')
            ->join('zones', function ($join) use ($distId): void {
                $join->on('zones.distributor_id', DB::raw("'{$distId}'"))
                    ->whereColumn('seller_stock.zone_id', 'zones.id');
            })
            ->join('users', 'users.id', '=', 'seller_stock.seller_id')
            ->join('products', 'products.id', '=', 'seller_stock.product_id')
            ->whereRaw('(seller_stock.boxes - seller_stock.reserved) < seller_stock.minimum_stock')
            ->get([
                'users.full_name as seller_name',
                'seller_stock.seller_id',
                'products.name as product_name',
                DB::raw('(seller_stock.boxes - seller_stock.reserved) AS available'),
                'seller_stock.minimum_stock',
            ])
            ->map(fn ($r) => (array) $r)
            ->toArray();
    }

    /** @return array<int,array<string,mixed>> */
    private function zoneOverduePayments(string $distId): array
    {
        return DB::table('sales')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->join('zones', 'zones.id', '=', 'sales.zone_id')
            ->where('zones.distributor_id', $distId)
            ->whereIn('sales.status', ['confirmed', 'delivered'])
            ->where('sales.due_date', '<', now())
            ->whereRaw(
                'sales.total_amount > COALESCE((
                    SELECT SUM(p.amount) FROM payments p
                    WHERE p.sale_id = sales.id AND p.reversed = false AND p.currency = sales.currency
                ), 0)'
            )
            ->get([
                'sales.id',
                'sales.due_date',
                'sales.total_amount',
                'sales.currency',
                'customers.first_name',
                'customers.last_name',
            ])
            ->map(fn ($r) => (array) $r)
            ->toArray();
    }

    /** @return array<int,array<string,mixed>> */
    private function distributorBalanceEvolution6m(string $distId): array
    {
        // Reads from distributor_settlements history to compute 6-month balance trajectory.
        return DB::table('distributor_settlements')
            ->where('distributor_id', $distId)
            ->where('created_at', '>=', now()->subMonths(6)->startOfMonth())
            ->selectRaw("
                TO_CHAR(DATE_TRUNC('month', created_at), 'YYYY-MM') AS month,
                SUM(CASE WHEN currency = 'ARS' THEN amount ELSE 0 END) AS saldo_ars,
                SUM(CASE WHEN currency = 'USD' THEN amount ELSE 0 END) AS saldo_usd
            ")
            ->groupByRaw("DATE_TRUNC('month', created_at)")
            ->orderByRaw("DATE_TRUNC('month', created_at) ASC")
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();
    }

    /** @return array{boxes:int, amount:string} */
    private function zoneMonthlySales(string $distId): array
    {
        $start = now()->startOfMonth()->toDateString();
        $end   = now()->endOfMonth()->toDateString();

        $row = DB::table('sales')
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->join('zones', 'zones.id', '=', 'sales.zone_id')
            ->where('zones.distributor_id', $distId)
            ->where('sales.status', 'delivered')
            ->whereBetween('sales.delivered_at', [$start . ' 00:00:00', $end . ' 23:59:59'])
            ->selectRaw('SUM(sale_items.boxes) AS boxes, SUM(sales.total_amount) AS amount')
            ->first();

        return [
            'boxes'  => (int) ($row?->boxes ?? 0),
            'amount' => number_format((float) ($row?->amount ?? 0), 4, '.', ''),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function zoneStockAlerts(string $distId): array
    {
        return DB::table('alerts')
            ->join('users', 'users.id', '=', 'alerts.target_user_id')
            ->where('users.id', $distId)
            ->where('alerts.alert_type', 'stock_below_minimum')
            ->whereNull('alerts.read_at')
            ->get(['alerts.id', 'alerts.payload_json', 'alerts.created_at'])
            ->map(fn ($r) => (array) $r)
            ->toArray();
    }

    /** @return array<int,array<string,mixed>> */
    private function recentAuthorizations(): array
    {
        return DB::table('authorization_requests')
            ->join('users', 'users.id', '=', 'authorization_requests.requester_id')
            ->orderByDesc('authorization_requests.created_at')
            ->limit(10)
            ->get([
                'authorization_requests.id',
                'authorization_requests.type',
                'authorization_requests.status',
                'authorization_requests.current_value',
                'authorization_requests.proposed_value',
                'users.full_name as requester_name',
                'authorization_requests.created_at',
                'authorization_requests.resolved_at',
            ])
            ->map(fn ($r) => (array) $r)
            ->toArray();
    }

    /** @return array<int,array<string,mixed>> */
    private function goalSemaphore(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth()->toDateString();
        $end   = $month->copy()->endOfMonth()->toDateString();

        return DB::table('seller_monthly_goals')
            ->join('users', 'users.id', '=', 'seller_monthly_goals.seller_id')
            ->leftJoin(
                DB::raw('(
                    SELECT sales.seller_id, SUM(si.boxes) AS achieved
                    FROM sales
                    JOIN sale_items si ON si.sale_id = sales.id
                    WHERE sales.status = \'delivered\'
                    AND sales.delivered_at BETWEEN \'' . $start . ' 00:00:00\' AND \'' . $end . ' 23:59:59\'
                    GROUP BY sales.seller_id
                ) achieved_cte'),
                'achieved_cte.seller_id',
                '=',
                'seller_monthly_goals.seller_id'
            )
            ->where('seller_monthly_goals.period_month', $month->copy()->startOfMonth()->toDateString())
            ->selectRaw('
                seller_monthly_goals.seller_id,
                users.full_name AS seller_name,
                seller_monthly_goals.goal_boxes AS target,
                COALESCE(achieved_cte.achieved, 0) AS achieved,
                CASE
                    WHEN COALESCE(achieved_cte.achieved, 0) >= seller_monthly_goals.goal_boxes THEN \'on_track\'
                    WHEN COALESCE(achieved_cte.achieved, 0) >= seller_monthly_goals.goal_boxes * 0.75 THEN \'at_risk\'
                    ELSE \'below\'
                END AS semaphore_status
            ')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();
    }

    /** @return array<int,array<string,mixed>> */
    private function settlementStatusPerZone(): array
    {
        return DB::table('distributor_account')
            ->join('users', 'users.id', '=', 'distributor_account.distributor_id')
            ->get([
                'distributor_account.distributor_id',
                'users.full_name as distributor_name',
                'distributor_account.balance_ars',
                'distributor_account.balance_usd',
            ])
            ->map(fn ($r) => (array) $r)
            ->toArray();
    }

    /** @return array<int,array<string,mixed>> */
    private function globalCobrosEvolution6m(): array
    {
        return DB::table('payments')
            ->where('reversed', false)
            ->where('payment_date', '>=', now()->subMonths(6)->startOfMonth()->toDateString())
            ->selectRaw("
                TO_CHAR(DATE_TRUNC('month', payment_date::date), 'YYYY-MM') AS month,
                SUM(CASE WHEN currency = 'ARS' THEN amount ELSE 0 END) AS ars,
                SUM(CASE WHEN currency = 'USD' THEN amount ELSE 0 END) AS usd
            ")
            ->groupByRaw("DATE_TRUNC('month', payment_date::date)")
            ->orderByRaw("DATE_TRUNC('month', payment_date::date) ASC")
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();
    }

    /** @return array{amount:string, customers:int} */
    private function globalOverdueInfo(): array
    {
        $row = DB::table('sales')
            ->whereIn('status', ['confirmed', 'delivered'])
            ->where('due_date', '<', now())
            ->whereRaw(
                'total_amount > COALESCE((
                    SELECT SUM(p.amount) FROM payments p
                    WHERE p.sale_id = sales.id AND p.reversed = false AND p.currency = sales.currency
                ), 0)'
            )
            ->selectRaw('
                COUNT(DISTINCT customer_id) AS customers,
                SUM(total_amount) AS amount
            ')
            ->first();

        return [
            'amount'    => number_format((float) ($row->amount ?? 0), 4, '.', ''),
            'customers' => (int) ($row->customers ?? 0),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function topOverdueCustomers(): array
    {
        return DB::table('sales')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->whereIn('sales.status', ['confirmed', 'delivered'])
            ->where('sales.due_date', '<', now())
            ->whereRaw(
                'sales.total_amount > COALESCE((
                    SELECT SUM(p.amount) FROM payments p
                    WHERE p.sale_id = sales.id AND p.reversed = false AND p.currency = sales.currency
                ), 0)'
            )
            ->selectRaw('
                customers.id AS customer_id,
                customers.first_name,
                customers.last_name,
                SUM(sales.total_amount) AS overdue_amount,
                MAX(sales.currency) AS currency
            ')
            ->groupBy('customers.id', 'customers.first_name', 'customers.last_name')
            ->orderByDesc('overdue_amount')
            ->limit(10)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();
    }

    private function countPendingAuthorizations(): int
    {
        return (int) DB::table('authorization_requests')
            ->where('status', 'pending')
            ->count();
    }

    private function globalConfirmedPending(): int
    {
        return (int) DB::table('sales')
            ->where('status', 'confirmed')
            ->count();
    }

    private function staleDraftsCount(): int
    {
        $staleDays = (int) DB::table('configurations')
            ->where('key', 'draft_stale_days')
            ->value('value') ?: 30;

        return (int) DB::table('sales')
            ->where('status', 'draft')
            ->where('updated_at', '<', now()->subDays($staleDays))
            ->count();
    }
}
