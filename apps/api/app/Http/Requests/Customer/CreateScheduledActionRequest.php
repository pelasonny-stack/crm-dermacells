<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/v1/customers/{customer}/scheduled-actions payload.
 *
 * Any role with visibility over the customer can create a scheduled action
 * (§3.8). The RLS policy on scheduled_actions inherits from customers, so the
 * DB layer ensures the caller can only create actions on visible customers.
 *
 * scheduled_date must be today or future — past dates make no sense for a
 * "future planned action". The timezone is ART for consistent date validation.
 */
class CreateScheduledActionRequest extends FormRequest
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
            'scheduled_date' => [
                'required',
                'date',
                'date_format:Y-m-d',
                'after_or_equal:today',
            ],
            'note' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scheduled_date.required'        => 'La fecha programada es obligatoria.',
            'scheduled_date.after_or_equal'  => 'La fecha programada debe ser hoy o una fecha futura.',
            'note.required'                  => 'La nota / motivo es obligatorio.',
            'note.min'                       => 'La nota debe tener al menos 5 caracteres.',
        ];
    }
}
