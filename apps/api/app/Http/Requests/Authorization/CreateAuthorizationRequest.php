<?php

declare(strict_types=1);

namespace App\Http\Requests\Authorization;

use App\Enums\AuthorizationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Validates the body of POST /api/v1/authorizations.
 *
 * Only Sellers and Distributors submit new requests; Directors modify values
 * directly without going through this flow (§13.1). Enforcement is done in
 * the controller — this request only validates shape.
 */
final class CreateAuthorizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Controller enforces role restriction; request stays open here
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type'           => ['required', new Enum(AuthorizationType::class)],
            'sale_id'        => ['nullable', 'uuid', 'exists:sales,id'],
            'current_value'  => ['required', 'numeric', 'min:0'],
            'proposed_value' => ['required', 'numeric', 'min:0'],
            'value_currency' => ['nullable', 'string', 'size:3'],
            'reason'         => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'type.required'          => 'El tipo de solicitud es obligatorio.',
            'reason.required'        => 'El motivo es obligatorio (§13.2).',
            'reason.min'             => 'El motivo debe tener al menos 5 caracteres.',
            'current_value.required' => 'El valor actual es obligatorio.',
            'proposed_value.required' => 'El valor propuesto es obligatorio.',
        ];
    }
}
