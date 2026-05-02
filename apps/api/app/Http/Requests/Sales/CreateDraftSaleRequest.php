<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Data\Sales\CreateSalePayload;
use App\Data\Sales\SaleItemData;
use App\Rules\CurrencyConsistency;
use App\Rules\SellerCanSellInZone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the POST /api/v1/sales (create draft) request.
 *
 * The idempotent middleware runs before this request reaches the controller,
 * so by the time authorize() and rules() are called, the idempotency check
 * has already either replayed a cached response or decided to proceed.
 */
class CreateDraftSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Controller applies Sanctum auth; any authenticated user may attempt
        // to create a sale. SellerCanSellInZone rule handles seller-level authorization.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $currency = $this->input('currency', 'ARS');

        return [
            'customer_id'               => ['required', 'uuid', 'exists:customers,id'],
            'seller_id'                 => [
                'required',
                'uuid',
                'exists:users,id',
                new SellerCanSellInZone($this->all()),
            ],
            'zone_id'                   => ['required', 'uuid', 'exists:zones,id'],
            'sale_date'                 => ['required', 'date_format:Y-m-d'],
            'payment_terms_id'          => ['required', 'uuid', 'exists:payment_terms,id'],
            'currency'                  => ['required', 'in:ARS,USD'],
            'exchange_rate_id'          => [
                'nullable',
                'uuid',
                'exists:exchange_rates,id',
                // Required when currency=ARS
                $currency === 'ARS' ? 'required' : 'nullable',
            ],
            'delegated_delivery'        => ['boolean'],
            'delegated_distributor_id'  => ['nullable', 'uuid', 'exists:users,id'],

            // Items
            'items'                     => [
                'required',
                'array',
                'min:1',
                new CurrencyConsistency($currency),
            ],
            'items.*.product_id'        => ['required', 'uuid', 'exists:products,id'],
            'items.*.quantity_boxes'    => ['required', 'integer', 'min:0'],
            'items.*.quantity_units'    => ['required', 'integer', 'min:0', 'max:4'],
            'items.*.unit_price_amount' => ['nullable', 'numeric', 'min:0.0001'],
            'items.*.unit_price_currency' => ['nullable', 'in:ARS,USD'],
        ];
    }

    public function toPayload(): CreateSalePayload
    {
        $validated = $this->validated();

        return new CreateSalePayload(
            customerId: $validated['customer_id'],
            sellerId: $validated['seller_id'],
            zoneId: $validated['zone_id'],
            saleDate: $validated['sale_date'],
            paymentTermsId: $validated['payment_terms_id'],
            currency: $validated['currency'],
            exchangeRateId: $validated['exchange_rate_id'] ?? null,
            delegatedDelivery: (bool) ($validated['delegated_delivery'] ?? false),
            delegatedDistributorId: $validated['delegated_distributor_id'] ?? null,
            items: array_map(
                fn (array $item) => new SaleItemData(
                    productId: $item['product_id'],
                    quantityBoxes: (int) $item['quantity_boxes'],
                    quantityUnits: (int) $item['quantity_units'],
                    unitPriceAmount: $item['unit_price_amount'] ?? null,
                    unitPriceCurrency: $item['unit_price_currency'] ?? $validated['currency'],
                ),
                $validated['items'],
            ),
        );
    }
}
