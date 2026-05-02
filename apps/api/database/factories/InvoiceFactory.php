<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\CustomerBillingEntity;
use App\Models\Invoice;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'sale_id'           => Sale::factory(),
            'billing_entity_id' => CustomerBillingEntity::factory(),
            'external_ref'      => 'sale-test-' . Str::ulid(),
            'voucher_type'      => 'B',
            'status'            => InvoiceStatus::Pending,
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => InvoiceStatus::Pending]);
    }

    public function reconciling(): static
    {
        return $this->state(['status' => InvoiceStatus::Reconciling]);
    }

    public function success(): static
    {
        return $this->state([
            'status'         => InvoiceStatus::Success,
            'xubio_id'       => 'xubio-' . Str::ulid(),
            'cae'            => '70000000000001',
            'invoice_number' => '0001-00001234',
            'amount_ars'     => '12345.6700',
            'issued_at'      => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(['status' => InvoiceStatus::FailedManualReview]);
    }
}
