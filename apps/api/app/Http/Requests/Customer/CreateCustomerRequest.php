<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Enums\IvaCondition;
use App\Rules\CuitFormat;
use App\Rules\UniqueCuit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Validates the POST /api/v1/customers payload.
 *
 * Required fields per §3.1. The CUIT passes through two custom rules:
 *   1. CuitFormat  — 11 digits + modulo-11 checksum
 *   2. UniqueCuit  — no existing customer with that CUIT
 *
 * Optional fields:
 *   - reference_price_amount / reference_price_currency — compound money pair
 *   - reference_price_unit_amount — per-unit price (nullable, defaults to amount/5)
 *   - purchase_frequency_days — override of category default
 *
 * The reference_price_currency defaults to 'USD' at the DB level but the
 * request must specify it explicitly when providing reference_price_amount to
 * avoid ambiguity.
 */
class CreateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Gate: Director, Distributor (own zone), Seller (own customers)
        // Fine-grained policy handled by CustomerController via RLS + Policies
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name'               => ['required', 'string', 'max:255'],
            'last_name'                => ['required', 'string', 'max:255'],
            'cuit'                     => ['required', 'string', new CuitFormat, new UniqueCuit],
            'phone'                    => ['required', 'string', 'max:50'],
            'email'                    => ['required', 'email', 'max:255'],
            'address'                  => ['required', 'string', 'max:500'],
            'category_id'              => ['required', 'uuid', 'exists:customer_categories,id'],
            'zone_id'                  => ['required', 'uuid', 'exists:zones,id'],
            'assigned_seller_id'       => ['required', 'uuid', 'exists:users,id'],
            'default_payment_terms_id' => ['required', 'uuid', 'exists:payment_terms,id'],

            // Reference price (optional compound pair)
            'reference_price_amount'   => ['nullable', 'numeric', 'min:0', 'max:9999999999999.9999'],
            'reference_price_currency' => ['nullable', 'string', 'size:3', 'required_with:reference_price_amount'],
            'reference_price_unit_amount' => ['nullable', 'numeric', 'min:0'],

            // Evolution engine
            'purchase_frequency_days'  => ['nullable', 'integer', 'min:1', 'max:3650'],

            // Billing entities (optional at creation — added via nested resource later)
            'billing_entities'         => ['nullable', 'array', 'max:10'],
            'billing_entities.*.name'  => ['required_with:billing_entities', 'string', 'max:255'],
            'billing_entities.*.cuit'  => ['required_with:billing_entities', 'string', new CuitFormat],
            'billing_entities.*.iva_condition' => [
                'required_with:billing_entities',
                new Enum(IvaCondition::class),
            ],
            'billing_entities.*.is_primary' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_id.exists'              => 'La categoría seleccionada no existe o no está disponible.',
            'zone_id.exists'                  => 'La zona seleccionada no existe.',
            'assigned_seller_id.exists'       => 'El vendedor seleccionado no existe.',
            'default_payment_terms_id.exists' => 'La condición de pago seleccionada no existe.',
            'reference_price_currency.required_with' => 'La moneda del precio de referencia es obligatoria cuando se indica el monto.',
        ];
    }
}
