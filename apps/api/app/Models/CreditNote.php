<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Enums\CreditNoteStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * CreditNote model — §6.5 Notas de Crédito Xubio.
 *
 * Two creation paths:
 *   1. Partial return (§5.8): IssueXubioCreditNoteJob fired by listener
 *      OnPartialReturnAwaitingCreditNote. sale_item_id + partial_return_id set.
 *   2. Manual cancellation NC: created via InvoiceController::createCreditNote.
 *      sale_item_id NULL, applies to full invoice amount.
 *
 * @property string                 $id
 * @property string                 $invoice_id
 * @property string                 $sale_id
 * @property string|null            $sale_item_id
 * @property string|null            $partial_return_id
 * @property string|null            $xubio_id
 * @property string                 $external_ref
 * @property string|null            $cn_number
 * @property string|null            $cae
 * @property string                 $amount_ars
 * @property string|null            $exchange_rate_id
 * @property string|null            $pdf_url
 * @property CreditNoteStatus       $status
 * @property Carbon|null            $issued_at
 * @property string|null            $issued_by
 *
 * @property-read Invoice               $invoice
 * @property-read Sale                  $sale
 * @property-read SaleItem|null         $saleItem
 * @property-read PartialReturn|null    $partialReturn
 * @property-read ExchangeRate|null     $exchangeRate
 * @property-read User|null             $issuedByUser
 */
class CreditNote extends Model
{
    use Auditable, HasFactory, HasUuids;

    protected $table = 'credit_notes';

    protected $fillable = [
        'invoice_id',
        'sale_id',
        'sale_item_id',
        'partial_return_id',
        'xubio_id',
        'external_ref',
        'cn_number',
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
            'status'    => CreditNoteStatus::class,
            'issued_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------
    // Relationships
    // ------------------------------------------------------------------

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function partialReturn(): BelongsTo
    {
        return $this->belongsTo(PartialReturn::class);
    }

    public function exchangeRate(): BelongsTo
    {
        return $this->belongsTo(ExchangeRate::class);
    }

    public function issuedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    public function isSuccess(): bool
    {
        return $this->status === CreditNoteStatus::Success;
    }
}
