<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /invoices body.
 *
 * Director / authorized users select the Sale and the billing entity to use
 * for the invoice. The actual emission is async (IssueXubioInvoiceJob).
 */
class CreateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'sale_id'           => ['required', 'uuid', 'exists:sales,id'],
            'billing_entity_id' => ['required', 'uuid', 'exists:customer_billing_entities,id'],
            'voucher_type'      => ['nullable', 'in:A,B,C'],
        ];
    }
}
