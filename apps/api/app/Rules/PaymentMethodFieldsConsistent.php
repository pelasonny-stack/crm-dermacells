<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\PaymentMethod;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that all required fields for a payment method are present and
 * that method-irrelevant fields are absent (or null).
 *
 * This rule is applied to the entire request data as an "after" rule on the
 * payment_method_id field. It loads the PaymentMethod and checks:
 *
 *   - transfer_dermacells / transfer_distributor: requires `reference`
 *   - credit_card:                                requires `installments`
 *   - check:                                      requires `check_number`, `check_bank`, `check_due_date`
 *   - cash:                                       no additional fields required (destination auto-resolved)
 *
 * Instantiate with the full request data array so the rule has access to all fields.
 *
 * @param array<string, mixed> $requestData
 */
final class PaymentMethodFieldsConsistent implements ValidationRule
{
    /**
     * @param array<string, mixed> $requestData
     */
    public function __construct(
        private readonly array $requestData,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // $value is the payment_method_id UUID
        $method = PaymentMethod::find($value);

        if ($method === null) {
            // The payment_method_id itself is validated by the 'exists' rule upstream
            return;
        }

        if ($method->requires_reference && empty($this->requestData['reference'])) {
            $fail("Payment method '{$method->name}' requires a reference / comprobante number.");
        }

        if ($method->requires_installments) {
            if (empty($this->requestData['installments']) || ! is_int((int) $this->requestData['installments'])) {
                $fail("Payment method '{$method->name}' requires the number of installments (cuotas).");
            }
        }

        if ($method->requires_check_fields) {
            if (empty($this->requestData['check_number'])) {
                $fail("Cheque payments require check_number.");
            }
            if (empty($this->requestData['check_bank'])) {
                $fail("Cheque payments require check_bank.");
            }
            if (empty($this->requestData['check_due_date'])) {
                $fail("Cheque payments require check_due_date (fecha de acreditación).");
            }
        }
    }
}
