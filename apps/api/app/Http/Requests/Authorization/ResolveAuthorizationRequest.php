<?php

declare(strict_types=1);

namespace App\Http\Requests\Authorization;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the body of PATCH /api/v1/authorizations/{id}/resolve.
 *
 * Only Directors may resolve; this is enforced both here (authorize()) and
 * at the policy/controller level for defense-in-depth.
 *
 * Fields:
 * - action:            'approve' | 'reject'
 * - rejection_reason:  Required when action = 'reject'; prohibited for approve.
 */
final class ResolveAuthorizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Director;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action'           => ['required', Rule::in(['approve', 'reject'])],
            'rejection_reason' => [
                Rule::requiredIf(fn () => $this->input('action') === 'reject'),
                'nullable',
                'string',
                'min:5',
                'max:2000',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'action.required'              => 'La acción (approve|reject) es obligatoria.',
            'action.in'                    => 'La acción debe ser "approve" o "reject".',
            'rejection_reason.required_if' => 'El motivo de rechazo es obligatorio al rechazar.',
        ];
    }
}
