<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CreditNoteStatus;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CreditNote>
 */
class CreditNoteFactory extends Factory
{
    protected $model = CreditNote::class;

    public function definition(): array
    {
        return [
            'invoice_id'       => Invoice::factory(),
            'sale_id'          => Sale::factory(),
            'external_ref'     => 'cn-test-' . Str::ulid(),
            'amount_ars'       => '500.0000',
            'status'           => CreditNoteStatus::Pending,
        ];
    }

    public function success(): static
    {
        return $this->state([
            'status'    => CreditNoteStatus::Success,
            'xubio_id'  => 'xubio-nc-' . Str::ulid(),
            'cae'       => '70000000000099',
            'cn_number' => '0001-NC-00001234',
            'issued_at' => now(),
        ]);
    }
}
