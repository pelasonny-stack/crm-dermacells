<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * SellerMonthlyGoal — metas mensuales por Vendedor (§12.1, §16.8).
 *
 * year_month is stored as the first day of the month (DATE).
 * target_boxes is the integer box count goal for that seller/month pair.
 *
 * @property string      $id
 * @property string      $seller_id
 * @property Carbon      $year_month
 * @property int         $target_boxes
 * @property string      $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property-read User $seller
 * @property-read User $createdBy
 */
class SellerMonthlyGoal extends Model
{
    use Auditable, HasFactory, HasUuids;

    protected $table = 'seller_monthly_goals';

    protected $fillable = [
        'seller_id',
        'year_month',
        'target_boxes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'year_month'   => 'date',
            'target_boxes' => 'integer',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** @return BelongsTo<User, SellerMonthlyGoal> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    /** @return BelongsTo<User, SellerMonthlyGoal> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns a human-readable label like "Mayo 2026".
     */
    public function yearMonthLabel(): string
    {
        return $this->year_month->translatedFormat('F Y');
    }
}
