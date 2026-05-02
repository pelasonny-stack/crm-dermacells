<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates DELETE /api/v1/customers/{customer} (logical deactivation) payload.
 *
 * Per §3.10 a Director-forced deactivation requires a reason to be logged in
 * the audit trail. The `reason` field is therefore required — even for the
 * normal (non-forced) path — so the audit log always has context.
 *
 * `force` is a Director-only flag that bypasses the pending-obligations check
 * in DeactivateCustomerAction. Non-director users submitting `force=true` will
 * receive the obligations-blocking 409 regardless, because the controller
 * checks the role before reading this flag.
 */
class DeactivateCustomerRequest extends FormRequest
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
        return [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'force'  => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'El motivo de baja es obligatorio.',
            'reason.min'      => 'El motivo debe tener al menos 10 caracteres.',
        ];
    }

    /**
     * Whether the requesting Director wants to force-deactivate despite
     * pending obligations. Always false for non-director users.
     */
    public function isForced(): bool
    {
        $user = $this->user();

        if ($user === null || $user->role !== UserRole::Director) {
            return false;
        }

        return (bool) $this->boolean('force');
    }
}
