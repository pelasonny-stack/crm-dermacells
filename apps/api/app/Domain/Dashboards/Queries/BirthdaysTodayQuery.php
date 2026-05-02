<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Queries;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Returns customer contacts whose birthday falls on today (year-agnostic).
 *
 * Uses EXTRACT(month) + EXTRACT(day) to match regardless of birth year.
 * RLS ensures the Vendedor only sees contacts belonging to their own customers.
 *
 * Joins to customers to expose the customer's name and assigned seller_id
 * so the Distributor / Director can filter further if needed.
 */
final class BirthdaysTodayQuery
{
    public function get(string $sellerId): Collection
    {
        $today = now();
        $month = $today->month;
        $day   = $today->day;

        return DB::table('customer_contacts')
            ->join('customers', 'customers.id', '=', 'customer_contacts.customer_id')
            ->where('customers.seller_id', $sellerId)
            ->whereNotNull('customer_contacts.birthday')
            ->whereRaw('EXTRACT(month FROM customer_contacts.birthday) = ?', [$month])
            ->whereRaw('EXTRACT(day FROM customer_contacts.birthday) = ?', [$day])
            ->get([
                'customer_contacts.id',
                'customer_contacts.first_name',
                'customer_contacts.last_name',
                'customer_contacts.phone',
                'customer_contacts.email',
                'customers.id as customer_id',
                'customers.first_name as customer_first_name',
                'customers.last_name as customer_last_name',
            ]);
    }
}
