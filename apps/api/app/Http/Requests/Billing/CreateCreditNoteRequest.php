<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

class CreateCreditNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'amount_ars'         => ['required', 'numeric', 'min:0.01'],
            'partial_return_id'  => ['nullable', 'uuid', 'exists:partial_returns,id'],
            'sale_item_id'       => ['nullable', 'uuid', 'exists:sale_items,id'],
            'reason'             => ['nullable', 'string', 'max:500'],
        ];
    }
}
