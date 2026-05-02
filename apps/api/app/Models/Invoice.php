<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Invoice model — §6 Xubio integration.
 *
 * `external_ref` is the application-side idempotency key. Set by
 * IssueXubioInvoiceJob using "sale-{sale_id}-{ulid}" so a retry of the
 * Job submits the same external_ref and Xubio (or our reconciler) can
 * detect a duplicate.
 *
 * `xubio_id`, `cae`, `invoice_number`, `pdf_url`, `amount_ars`,
 * `voucher_type`, `issued_at`, `issued_by` are populated only after
 * Xubio confirms emission.
 *
 * @property string             $id
 * @property string             $sale_id
 * @property string             $billing_entity_id
 * @property string|null        $xubio_id
 * @property string             $external_ref
 * @property string|null        $invoice_number
 * @property string|null        $voucher_type
 * @property string|null        $cae
 * @property string|null        $amount_ars
 * @property string|null        $exchange_rate_id
 * @property string|null        $pdf_url
 * @property InvoiceStatus      $status
 * @property Carbon|null        $issued_at
 * @property string|null        $issued_by
 * @property Carbon|null        $created_at
 * @property Carbon|null        $updated_at
 *
 * @property-read Sale                  $sale
 * @property-read CustomerBillingEntity $billingEntity
 * @property-read ExchangeRate|null     $exchangeRate
 * @property-read User|null             $issuedByUser
 * @property-read \Illuminate\Database\Eloquent\Collection<int,CreditNote> $creditNotes
 */
class Invoice extends Model
{
    use Auditable, HasFactory, HasUuids;

    protected $table = 'invoices';

    protected $fillable = [
        'sale_id',
        'billing_entity_id',
        'xubio_id',
        'external_ref',
        'invoice_number',
        'voucher_type',
        'cae',
        'amount_ars',
        'exchange_rate_id',
        'pdf_url',
        'status',
        'issued_at',
        'issued_by',
    ];

    protected function casts(): array
    {
        return [
            'status'    => InvoiceStatus::class,
            'issued_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------
    // Relationships
    // ------------------------------------------------------------------

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function billingEntity(): BelongsTo
    {
        return $this->belongsTo(CustomerBillingEntity::class, 'billing_entity_id');
    }

    public function exchangeRate(): BelongsTo
    {
        return $this->belongsTo(ExchangeRate::class);
    }

    public function issuedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class, 'invoice_id');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    public function isSuccess(): bool
    {
        return $this->status === InvoiceStatus::Success;
    }

    public function isReconciling(): bool
    {
        return $this->status === InvoiceStatus::Reconciling;
    }

    public function isFailed(): bool
    {
        return $this->status === InvoiceStatus::FailedManualReview;
    }
}
