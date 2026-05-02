<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/sales/{id}/returns
 *
 * Initiates a partial product return for a single sale item.
 * At least one of quantity_boxes or quantity_units must be > 0.
 */
class InitiatePartialReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sale_item_id'   => ['required', 'uuid', 'exists:sale_items,id'],
            'quantity_boxes' => ['required', 'integer', 'min:0'],
            'quantity_units' => ['required', 'integer', 'min:0', 'max:4'],
            'reason'         => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Ensure at least one quantity field is > 0.
     *
     * @return array<string, mixed>
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $v): void {
            if (
                (int) $this->input('quantity_boxes', 0) === 0
                && (int) $this->input('quantity_units', 0) === 0
            ) {
                $v->errors()->add('quantity_boxes', 'At least one of quantity_boxes or quantity_units must be greater than zero.');
            }
        });
    }
}
