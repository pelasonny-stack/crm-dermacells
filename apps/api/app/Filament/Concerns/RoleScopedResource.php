<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Enums\UserRole;

/**
 * RoleScopedResource trait — convenience helpers for Filament resources that
 * need to gate sidebar visibility by the authenticated user's role.
 *
 * Usage — in any Resource class:
 *
 *   use RoleScopedResource;
 *
 *   protected static array $visibleToRoles = [
 *       UserRole::Director,
 *       UserRole::Distributor,
 *   ];
 *
 * canViewAny() then returns true only when the current user holds one of
 * those roles. The property is intentionally static so it can be declared at
 * class-definition time without instantiation overhead.
 *
 * Roles catalogue (see App\Enums\UserRole):
 *   Director     — full access; all resources visible.
 *   Distributor  — zone owner; commercial + distributor-finance + stock views.
 *   Seller       — field agent; own sales, payments, customers, alerts.
 */
trait RoleScopedResource
{
    /**
     * Override in each Resource to declare which roles may see this resource
     * in the sidebar and call canViewAny() on it.
     *
     * @var array<int, UserRole>
     */
    protected static array $visibleToRoles = [
        UserRole::Director,
        UserRole::Distributor,
        UserRole::Seller,
    ];

    /**
     * Filament resource gate — hides the nav item and blocks index access for
     * roles not listed in $visibleToRoles.
     */
    public static function canViewAny(): bool
    {
        $role = auth()->user()?->role;

        if ($role === null) {
            return false;
        }

        return in_array($role, static::$visibleToRoles, true);
    }
}
