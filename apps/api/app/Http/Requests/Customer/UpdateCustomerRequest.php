<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Rules\CuitFormat;
use App\Rules\UniqueCuit;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PATCH /api/v1/customers/{customer} payload.
 *
 * All fields are optional (partial update semantics). The CUIT uniqueness check
 * passes the current customer's ID so the existing row is excluded from the
 * duplicate check (a customer can "update" with the same CUIT without error).
 *
 * Note: reference_price is Director-only per §3.2. The controller enforces
 * this via a Gate check before delegating to this request; the request itself
 * does not repeat that check to keep validation and authorization concerns
 * separated.
 */
class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var string|null $customerId */
        $customerId = $this->route('customer');

        return [
            'first_name'               => ['sometimes', 'string', 'max:255'],
            'last_name'                => ['sometimes', 'string', 'max:255'],
            'cuit'                     => ['sometimes', 'string', new CuitFormat, new UniqueCuit($customerId)],
            'phone'                    => ['sometimes', 'string', 'max:50'],
            'email'                    => ['sometimes', 'email', 'max:255'],
            'address'                  => ['sometimes', 'string', 'max:500'],
            'category_id'              => ['sometimes', 'uuid', 'exists:customer_categories,id'],
            'zone_id'                  => ['sometimes', 'uuid', 'exists:zones,id'],
            'assigned_seller_id'       => ['sometimes', 'uuid', 'exists:users,id'],
            'default_payment_terms_id' => ['sometimes', 'uuid', 'exists:payment_terms,id'],

            'reference_price_amount'      => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'reference_price_currency'    => ['sometimes', 'nullable', 'string', 'size:3'],
            'reference_price_unit_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],

            'purchase_frequency_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
        ];
    }
}
