<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Read-only Eloquent wrapper for the audit_log partitioned table.
 *
 * The table is append-only and HMAC-chained; this model is used only for
 * Filament table rendering (which requires an Eloquent Builder).
 *
 * Actual columns (from migration 2026_05_02_000001):
 *   id             BIGSERIAL
 *   occurred_at    TIMESTAMPTZ
 *   actor_user_id  UUID (nullable)
 *   actor_role     TEXT (nullable)
 *   section        TEXT (nullable)
 *   entity_type    TEXT (nullable)
 *   entity_id      TEXT (nullable)
 *   field_name     TEXT (nullable)
 *   old_value      TEXT (nullable)
 *   new_value      TEXT (nullable)
 *   ip_address     INET (nullable)
 *   session_id     TEXT (nullable)
 *   prev_hash      CHAR(64)
 *   row_hash       CHAR(64)
 *
 * @property int                    $id
 * @property \Carbon\Carbon         $occurred_at
 * @property string|null            $actor_user_id
 * @property string|null            $actor_role
 * @property string|null            $section
 * @property string|null            $entity_type
 * @property string|null            $entity_id
 * @property string|null            $field_name
 * @property string|null            $old_value
 * @property string|null            $new_value
 * @property string|null            $ip_address
 * @property string|null            $session_id
 * @property string                 $row_hash
 */
class AuditLog extends Model
{
    protected $table = 'audit_log';

    public $timestamps = false;

    public $incrementing = true;

    protected $keyType = 'int';

    protected $primaryKey = 'id';

    /** No mutations allowed — table is append-only */
    public static function boot(): void
    {
        parent::boot();

        static::creating(fn () => false);
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    /**
     * Scope that joins users table to surface actor_name for display.
     */
    public function scopeWithActorName(Builder $query): Builder
    {
        return $query
            ->leftJoin('users as u', 'u.id', '=', 'audit_log.actor_user_id')
            ->select([
                'audit_log.id',
                'audit_log.occurred_at',
                DB::raw("COALESCE(u.full_name, audit_log.actor_user_id::TEXT) AS actor_name"),
                'u.email AS actor_email',
                'audit_log.actor_role',
                'audit_log.section',
                'audit_log.entity_type',
                'audit_log.entity_id',
                'audit_log.field_name',
                'audit_log.old_value',
                'audit_log.new_value',
                'audit_log.ip_address',
                'audit_log.row_hash',
            ]);
    }
}
