<?php

declare(strict_types=1);

use App\Domain\AI\Services\CustomerContextBuilder;
use App\Models\Customer;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Phase 13 — Context Builder No-Leak Test (§11.5)
|--------------------------------------------------------------------------
|
| The CustomerContextBuilder must NEVER include data from a customer other
| than the one it was asked to build context for. This test seeds two
| customers and asserts that customer A's context does not mention
| customer B's id anywhere in the serialised payload.
*/

beforeEach(function (): void {
    $this->seller    = User::factory()->seller()->create();
    $this->customerA = Customer::factory()->create([
        'assigned_seller_id' => $this->seller->getKey(),
    ]);
    $this->customerB = Customer::factory()->create([
        'assigned_seller_id' => $this->seller->getKey(),
    ]);
});

it('context for customer A never mentions customer B id', function (): void {
    $builder = new CustomerContextBuilder();

    $rendered = $builder->renderJson($this->customerA, $this->seller);

    expect($rendered)->toContain($this->customerA->getKey());
    expect($rendered)->not->toContain($this->customerB->getKey());
});

it('context array isolates per customer', function (): void {
    $builder = new CustomerContextBuilder();

    $contextA = $builder->build($this->customerA, $this->seller);
    $contextB = $builder->build($this->customerB, $this->seller);

    expect($contextA['customer']['id'])->toBe($this->customerA->getKey());
    expect($contextB['customer']['id'])->toBe($this->customerB->getKey());
    expect($contextA['customer']['id'])->not->toBe($contextB['customer']['id']);
});
