<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/v1/sales/{id}/confirm
 *
 * Minimal body — the sale ID comes from the route parameter.
 * An optional 'note' may be provided for the status history record.
 */
class ConfirmSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
