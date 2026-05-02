<?php

declare(strict_types=1);

namespace App\Domain\Audit\Concerns;

use App\Domain\Audit\Observers\AuditObserver;

/**
 * Apply this trait to any Eloquent model whose mutations must land in
 * `audit_log` per §16.13.
 *
 * Usage:
 *
 * ```php
 * class User extends Authenticatable
 * {
 *     use \App\Domain\Audit\Concerns\Auditable;
 *     // ...
 * }
 * ```
 *
 * Laravel auto-discovers the `bootAuditable()` static method via the
 * standard `bootTrait` convention and calls it once per model lifetime,
 * which registers the {@see AuditObserver} for the consuming class.
 *
 * Reminder for Phase 1 follow-up: apply this trait to the User model
 * (and to every model added in later phases that touches sensitive
 * data — Customer, Sale, Invoice, Payment, etc.). The User model lives
 * at `app/Models/User.php`.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::observe(AuditObserver::class);
    }
}
