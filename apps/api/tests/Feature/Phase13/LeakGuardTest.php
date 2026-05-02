<?php

declare(strict_types=1);

use App\Domain\AI\Services\LeakGuard;
use App\Models\AiAudit;
use App\Models\Customer;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Phase 13 — Leak Guard Test (§11.5)
|--------------------------------------------------------------------------
|
| Verifies that LeakGuard.validate() detects when a response references
| a customer_id different from the one injected into context, and
| persists an ai_audit row with leak_detected = true.
*/

beforeEach(function (): void {
    $this->user      = User::factory()->seller()->create();
    $this->customerA = Customer::factory()->create();
    $this->customerB = Customer::factory()->create();
});

it('flags response referencing a foreign customer_id and audits it', function (): void {
    $guard = new LeakGuard();

    $response = sprintf(
        'Próxima acción para %s: revisar también la cuenta de %s.',
        $this->customerA->getKey(),
        $this->customerB->getKey(),
    );

    $result = $guard->validate($response, $this->customerA->getKey(), $this->user->getKey());

    expect($result->leaked)->toBeTrue();
    expect($result->foreignCustomerIds)->toContain($this->customerB->getKey());

    $audit = AiAudit::query()
        ->where('user_id', $this->user->getKey())
        ->where('leak_detected', true)
        ->latest('created_at')
        ->first();

    expect($audit)->not->toBeNull();
    expect((string) $audit->customer_id)->toBe($this->customerA->getKey());
    expect($audit->leak_details)->toContain($this->customerB->getKey());
});

it('passes when only the injected customer_id is referenced', function (): void {
    $guard = new LeakGuard();

    $response = sprintf('Cliente %s: visita en 7 días.', $this->customerA->getKey());

    $result = $guard->validate($response, $this->customerA->getKey(), $this->user->getKey());

    expect($result->leaked)->toBeFalse();
    expect($result->foreignCustomerIds)->toBe([]);
});

it('ignores hallucinated UUIDs that do not exist in customers', function (): void {
    $guard = new LeakGuard();

    $hallucinated = '00000000-0000-4000-8000-aaaaaaaaaaaa';
    $response     = "Inventando UUID falso: {$hallucinated}";

    $result = $guard->validate($response, $this->customerA->getKey(), $this->user->getKey());

    // Not a real customer → not a leak (only catches confirmed references).
    expect($result->leaked)->toBeFalse();
});

it('walks JSON arrays for foreign customer_ids', function (): void {
    $guard = new LeakGuard();

    $payload = [
        'recommendation' => 'Llamar mañana.',
        'related'        => [
            'other_customer_id' => $this->customerB->getKey(),
        ],
    ];

    $result = $guard->validate($payload, $this->customerA->getKey(), $this->user->getKey());

    expect($result->leaked)->toBeTrue();
});
