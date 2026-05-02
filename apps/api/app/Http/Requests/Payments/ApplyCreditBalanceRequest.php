<?php

declare(strict_types=1);

namespace App\Http\Requests\Payments;

use App\Enums\UserRole;
use Brick\Money\Money;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates applying a customer credit balance to a sale.
 *
 * POST /customers/{id}/credit-balances/{cbId}/apply
 * Director only (§7.5 — "disponible para imputar manualmente en futuras ventas").
 */
class ApplyCreditBalanceRequest extends FormRequest
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
            'sale_id'  => ['required', 'uuid', 'exists:sales,id'],
            'amount'   => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', 'string', 'size:3', 'in:ARS,USD'],
        ];
    }

    public function toMoney(): Money
    {
        return Money::of($this->input('amount'), $this->input('currency'));
    }
}
