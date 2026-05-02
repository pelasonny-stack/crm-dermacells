<?php

declare(strict_types=1);

namespace App\Http\Requests\Payments;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a payment reversal request (DELETE /payments/{id}).
 *
 * Only Directors can reverse payments (§7.6). The authorization check here
 * returns false for non-Directors so the framework returns a 403 before the
 * action is ever reached.
 */
class ReversePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Director;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
