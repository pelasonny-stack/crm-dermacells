<?php

declare(strict_types=1);

namespace App\Http\Requests\Payments;

use App\Rules\PaymentMethodFieldsConsistent;
use Brick\Money\Money;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a payment registration request (POST /payments).
 *
 * Method-specific field consistency is enforced by PaymentMethodFieldsConsistent.
 * The `amount` Money value object is built in after() for use in the action.
 */
class RegisterPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // All authenticated roles can register payments (§2.4)
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sale_id'            => ['required', 'uuid', 'exists:sales,id'],
            'payment_method_id'  => [
                'required',
                'uuid',
                'exists:payment_methods,id',
                new PaymentMethodFieldsConsistent($this->all()),
            ],
            'amount'             => ['required', 'numeric', 'gt:0'],
            'currency'           => ['required', 'string', 'size:3', 'in:ARS,USD'],
            'exchange_rate_id'   => ['nullable', 'uuid', 'exists:exchange_rates,id'],
            'is_advance'         => ['boolean'],
            'payment_date'       => ['required', 'date'],

            // Transfer methods
            'reference'          => ['nullable', 'string', 'max:255'],

            // Credit card
            'installments'       => ['nullable', 'integer', 'min:1', 'max:48'],

            // Check
            'check_number'       => ['nullable', 'string', 'max:100'],
            'check_bank'         => ['nullable', 'string', 'max:100'],
            'check_due_date'     => ['nullable', 'date'],
        ];
    }

    /**
     * Build the typed payload expected by RegisterPaymentAction.
     *
     * @return array{
     *   sale_id:           string,
     *   payment_method_id: string,
     *   amount:            Money,
     *   exchange_rate_id:  string|null,
     *   is_advance:        bool,
     *   payment_date:      string,
     *   reference:         string|null,
     *   installments:      int|null,
     *   check_number:      string|null,
     *   check_bank:        string|null,
     *   check_due_date:    string|null,
     * }
     */
    public function toActionPayload(): array
    {
        return [
            'sale_id'           => $this->input('sale_id'),
            'payment_method_id' => $this->input('payment_method_id'),
            'amount'            => Money::of($this->input('amount'), $this->input('currency')),
            'exchange_rate_id'  => $this->input('exchange_rate_id'),
            'is_advance'        => (bool) $this->input('is_advance', false),
            'payment_date'      => $this->input('payment_date'),
            'reference'         => $this->input('reference'),
            'installments'      => $this->input('installments') !== null ? (int) $this->input('installments') : null,
            'check_number'      => $this->input('check_number'),
            'check_bank'        => $this->input('check_bank'),
            'check_due_date'    => $this->input('check_due_date'),
        ];
    }
}
