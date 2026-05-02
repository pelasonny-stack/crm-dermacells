<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * SalesStatusHistory — immutable audit row per state transition.
 *
 * Created by Action classes; never mutated after creation.
 * No Auditable trait here to avoid audit_log recursion; the history table
 * itself is the audit record for sales state changes.
 *
 * @property string      $id
 * @property string      $sale_id
 * @property string|null $from_status
 * @property string      $to_status
 * @property string      $changed_by
 * @property Carbon      $changed_at
 * @property string|null $note
 *
 * @property-read Sale $sale
 * @property-read User $changedByUser
 */
class SalesStatusHistory extends Model
{
    use HasUuids;

    /**
     * History rows are immutable — disable updated_at.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'sale_id',
        'from_status',
        'to_status',
        'changed_by',
        'changed_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** @return BelongsTo<Sale, SalesStatusHistory> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<User, SalesStatusHistory> */
    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
