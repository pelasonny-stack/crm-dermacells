<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Represents the three operational roles in CRM Dermacells.
 *
 * - director:     Full access. Manages configuration, approves authorizations,
 *                 views all data across zones. Does NOT earn commissions (§2.4).
 * - distributor:  Zone owner. Manages stock distribution, settlements, and
 *                 vendor assignments within their zone.
 * - seller:       Field agent. Creates sales and collections for assigned
 *                 customers. Visibility scoped by RLS to their own data.
 */
enum UserRole: string
{
    case Director = 'director';
    case Distributor = 'distributor';
    case Seller = 'seller';

    /**
     * Returns true when the role is {@see self::Director}.
     */
    public function isDirector(): bool
    {
        return $this === self::Director;
    }

    /**
     * Returns true when the role is {@see self::Distributor}.
     */
    public function isDistributor(): bool
    {
        return $this === self::Distributor;
    }

    /**
     * Returns true when the role is {@see self::Seller}.
     */
    public function isSeller(): bool
    {
        return $this === self::Seller;
    }

    /**
     * Sanctum token abilities granted to this role on issuance.
     * Phase 1: coarse-grained '*' until per-domain abilities are mapped.
     *
     * @return array<int, string>
     */
    public function abilities(): array
    {
        return match ($this) {
            self::Director    => ['*'],
            self::Distributor => ['zone:*', 'sales:read', 'sales:write', 'payments:*', 'commissions:read'],
            self::Seller      => ['sales:own', 'payments:own', 'customers:own', 'commissions:own'],
        };
    }
}
