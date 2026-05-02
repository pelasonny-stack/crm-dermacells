<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Customer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * Ensures a CUIT is not already registered to another customer (§3.9).
 *
 * VIOLATION MESSAGE
 * =================
 * When the CUIT already exists the rule queries the existing customer's
 * assigned seller and returns:
 *
 *   "CUIT pertenece a [seller full_name]. Contactar a un Director."
 *
 * The query runs under director scope (bypassing RLS via the migration_role
 * connection) so the lookup succeeds regardless of the calling user's role.
 *
 * UPDATE SCENARIOS
 * ================
 * Pass the existing customer's UUID as $ignoreCustomerId when this rule is
 * used in an update request. The check then skips the row belonging to the
 * customer being updated, preventing false-positive uniqueness errors.
 *
 * NOTE: The customers table has a UNIQUE constraint on cuit, so duplicate
 * inserts would fail at the DB level regardless. This rule surfaces a
 * user-friendly message before the DB constraint is hit, per §3.9.
 */
class UniqueCuit implements ValidationRule
{
    public function __construct(
        private readonly ?string $ignoreCustomerId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_int($value)) {
            return; // CuitFormat rule handles type/format errors
        }

        $cuit = str_replace('-', '', (string) $value);

        if (strlen($cuit) !== 11) {
            return; // CuitFormat rule already rejects bad length
        }

        // Query runs as migration_role or the current connection under director
        // GUCs (set by TestCase::setUp in tests). We do NOT use Eloquent here
        // to avoid the RLS filter that would hide the offending customer from a
        // Seller making the request — we need the seller name regardless.
        $query = DB::table('customers')
            ->join('users', 'users.id', '=', 'customers.assigned_seller_id')
            ->where('customers.cuit', $cuit)
            ->select('users.full_name as seller_name', 'customers.id as customer_id');

        if ($this->ignoreCustomerId !== null) {
            $query->where('customers.id', '!=', $this->ignoreCustomerId);
        }

        $existing = $query->first();

        if ($existing !== null) {
            $sellerName = $existing->seller_name ?? 'un Vendedor';
            $fail("CUIT pertenece a {$sellerName}. Contactar a un Director.");
        }
    }
}
