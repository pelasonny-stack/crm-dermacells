<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CustomerContact model — contactos del cliente (§3.6).
 *
 * A customer may have multiple contacts. If `birthday` is set, the system
 * dispatches a push notification to the assigned Seller at 8:00 AM ART on
 * that calendar day via the `customers:dispatch-birthday-alerts` command.
 *
 * @property string               $id
 * @property string               $customer_id
 * @property string               $full_name
 * @property string               $phone
 * @property string               $email
 * @property string               $role_label
 * @property \Carbon\Carbon|null  $birthday
 */
class CustomerContact extends Model
{
    use Auditable, HasFactory, HasUuids;

    protected $table = 'customer_contacts';

    protected $fillable = [
        'customer_id',
        'full_name',
        'phone',
        'email',
        'role_label',
        'birthday',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'birthday'   => 'date',
            'is_primary' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** True if this contact has a birthday that matches today (month+day). */
    public function isBirthdayToday(): bool
    {
        if ($this->birthday === null) {
            return false;
        }

        $today = now()->timezone('America/Argentina/Buenos_Aires');

        return $this->birthday->month === (int) $today->month
            && $this->birthday->day === (int) $today->day;
    }
}
