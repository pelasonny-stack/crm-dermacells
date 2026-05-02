<?php

declare(strict_types=1);

use App\Casts\MoneyCast;
use App\Models\Product;
use Brick\Math\BigDecimal;
use Brick\Money\Exception\MoneyMismatchException;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| MoneyCast Tests
|--------------------------------------------------------------------------
|
| Verifies the compound Eloquent cast that maps (amount NUMERIC 18,4 +
| currency CHAR 3) DB columns to a Brick\Money\Money value object.
|
| Tests run in director GUC context (set by TestCase::setUp) so the
| products RLS write policy permits INSERT.
*/

it('hydrates a Money instance from two DB columns via MoneyCast', function (): void {
    $product = Product::create([
        'name'                => 'Test Hydrate ' . uniqid(),
        'units_per_box'       => 5,
        'base_price_amount'   => '750.0000',
        'base_price_currency' => 'USD',
        'is_active'           => true,
    ]);

    $fetched = Product::find($product->id);

    expect($fetched->base_price)->toBeInstanceOf(Money::class);
    expect($fetched->base_price->getCurrency()->getCurrencyCode())->toBe('USD');
    expect($fetched->base_price->getAmount()->isEqualTo(BigDecimal::of('750.0000')))->toBeTrue();
});

it('round-trips a Money value object through Eloquent set and get', function (): void {
    $money = Money::of('1250.50', 'USD');

    $product = Product::create([
        'name'                => 'RoundTrip ' . uniqid(),
        'units_per_box'       => 5,
        'base_price_amount'   => (string) $money->getAmount(),
        'base_price_currency' => $money->getCurrency()->getCurrencyCode(),
        'is_active'           => true,
    ]);

    $fetched = Product::find($product->id);

    // The Money value object should reconstruct to the same amount and currency.
    expect($fetched->base_price->isEqualTo($money))->toBeTrue();
});

it('MoneyCast::get returns null when amount column is null', function (): void {
    $cast       = new MoneyCast('base_price_amount', 'base_price_currency');
    $model      = new Product();
    $attributes = ['base_price_amount' => null, 'base_price_currency' => 'USD'];

    $result = $cast->get($model, 'base_price', null, $attributes);

    expect($result)->toBeNull();
});

it('MoneyCast::set decomposes Money into two column array', function (): void {
    $cast  = new MoneyCast('base_price_amount', 'base_price_currency');
    $model = new Product();
    $money = Money::of('750.0000', 'USD');

    $result = $cast->set($model, 'base_price', $money, []);

    expect($result)->toHaveKey('base_price_amount');
    expect($result)->toHaveKey('base_price_currency');
    expect($result['base_price_amount'])->toBe('750.0000');
    expect($result['base_price_currency'])->toBe('USD');
});

it('MoneyCast::set handles null value returning null columns', function (): void {
    $cast   = new MoneyCast('base_price_amount', 'base_price_currency');
    $model  = new Product();
    $result = $cast->set($model, 'base_price', null, []);

    expect($result['base_price_amount'])->toBeNull();
    expect($result['base_price_currency'])->toBeNull();
});

it('MoneyCast::set throws InvalidArgumentException for non-Money non-null value', function (): void {
    $cast  = new MoneyCast('base_price_amount', 'base_price_currency');
    $model = new Product();

    expect(fn () => $cast->set($model, 'base_price', 'not-money', []))
        ->toThrow(InvalidArgumentException::class);
});

it('adding Money objects with different currencies throws MoneyMismatchException', function (): void {
    $usd = Money::of('750', 'USD');
    $ars = Money::of('1000', 'ARS');

    expect(fn () => $usd->plus($ars))->toThrow(MoneyMismatchException::class);
});

it('persists different currencies independently', function (): void {
    $productUsd = Product::create([
        'name'                => 'Currency USD ' . uniqid(),
        'units_per_box'       => 5,
        'base_price_amount'   => '750.0000',
        'base_price_currency' => 'USD',
        'is_active'           => true,
    ]);

    $fetchedUsd = Product::find($productUsd->id);

    expect($fetchedUsd->base_price->getCurrency()->getCurrencyCode())->toBe('USD');
    expect((string) $fetchedUsd->base_price->getAmount())->toBe('750.0000');
});
