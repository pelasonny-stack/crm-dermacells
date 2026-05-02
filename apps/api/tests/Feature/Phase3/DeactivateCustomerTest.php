<?php

declare(strict_types=1);

use App\Actions\Customer\DeactivateCustomerAction;
use App\Exceptions\CustomerHasPendingObligationsException;
use App\Models\Customer;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Phase 3 — Deactivate Customer Tests
|--------------------------------------------------------------------------
|
| §3.10: Customer deactivation is blocked when:
|   - pending account balance  (Phase 7 — currently a TODO placeholder)
|   - open sales               (Phase 5 — currently a TODO placeholder)
|   - overdue collections      (Phase 7 — currently a TODO placeholder)
|
| Director can force deactivation with `force=true`.
|
| Because Phases 5 and 7 are not yet implemented, the "pending balance" check
| is validated by directly manipulating the `DeactivateCustomerAction` with a
| test double / subclass that simulates the blocking condition.
|
| The "no blocking obligations" path (standard deactivation) IS fully tested.
*/

// ---------------------------------------------------------------------------
// 1. Happy path — no blocking obligations
// ---------------------------------------------------------------------------

it('Director can deactivate a customer with no pending obligations', function (): void {
    $director = User::factory()->director()->create();
    $customer = Customer::factory()->create();

    $response = $this->actingAs($director, 'sanctum')
        ->deleteJson("/api/v1/customers/{$customer->id}", [
            'reason' => 'El cliente solicitó la baja del sistema.',
        ]);

    $response->assertNoContent();

    $customer->refresh();
    expect($customer->is_active)->toBeFalse();
    expect($customer->deactivated_by)->toBe($director->id);
    expect($customer->deactivation_reason)->toBe('El cliente solicitó la baja del sistema.');
    expect($customer->deactivated_at)->not->toBeNull();
});

it('Seller can deactivate their own customer with no pending obligations', function (): void {
    $seller   = User::factory()->seller()->create();
    $customer = Customer::factory()->assignedTo($seller)->create();

    $response = $this->actingAs($seller, 'sanctum')
        ->deleteJson("/api/v1/customers/{$customer->id}", [
            'reason' => 'El cliente cerró su clínica.',
        ]);

    $response->assertNoContent();
    expect($customer->fresh()->is_active)->toBeFalse();
});

// ---------------------------------------------------------------------------
// 2. Reason validation
// ---------------------------------------------------------------------------

it('returns 422 when reason is missing', function (): void {
    $director = User::factory()->director()->create();
    $customer = Customer::factory()->create();

    $this->actingAs($director, 'sanctum')
        ->deleteJson("/api/v1/customers/{$customer->id}", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason']);
});

it('returns 422 when reason is too short (less than 10 chars)', function (): void {
    $director = User::factory()->director()->create();
    $customer = Customer::factory()->create();

    $this->actingAs($director, 'sanctum')
        ->deleteJson("/api/v1/customers/{$customer->id}", ['reason' => 'Corto'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason']);
});

// ---------------------------------------------------------------------------
// 3. Blocking obligations — action layer (unit-style test)
// ---------------------------------------------------------------------------

it('DeactivateCustomerAction throws CustomerHasPendingObligationsException when obligations exist', function (): void {
    // Create a subclass that simulates a blocking obligation by overriding
    // the private method via reflection — instead, we test through the action's
    // public interface with a helper that monkey-patches the checking logic.
    //
    // Since the checks are TODO placeholders (Phases 5 & 7 not yet built),
    // we demonstrate the exception contract is correct by constructing it directly
    // and asserting the controller maps it to 409.

    $director = User::factory()->director()->create();
    $customer = Customer::factory()->create();

    // Mock the action to throw the blocking exception
    $this->instance(
        DeactivateCustomerAction::class,
        new class extends DeactivateCustomerAction {
            public function execute(Customer $customer, User $deactivatedBy, string $reason, bool $force = false): Customer
            {
                if (! $force) {
                    throw new CustomerHasPendingObligationsException(
                        ['El cliente tiene saldo pendiente en cuenta corriente.']
                    );
                }

                return parent::execute($customer, $deactivatedBy, $reason, $force);
            }
        }
    );

    $response = $this->actingAs($director, 'sanctum')
        ->deleteJson("/api/v1/customers/{$customer->id}", [
            'reason' => 'Prueba de bloqueo por obligaciones.',
            'force'  => false,
        ]);

    $response->assertStatus(409)
        ->assertJsonPath('code', 'CUSTOMER_HAS_PENDING_OBLIGATIONS')
        ->assertHeader('Content-Type', 'application/problem+json');
});

it('Director can force deactivate despite mocked blocking obligations', function (): void {
    $director = User::factory()->director()->create();
    $customer = Customer::factory()->create();

    // Inject action that would block without force
    $this->instance(
        DeactivateCustomerAction::class,
        new class extends DeactivateCustomerAction {
            public function execute(Customer $customer, User $deactivatedBy, string $reason, bool $force = false): Customer
            {
                if (! $force) {
                    throw new CustomerHasPendingObligationsException(
                        ['Saldo pendiente en cuenta corriente.']
                    );
                }

                return parent::execute($customer, $deactivatedBy, $reason, $force);
            }
        }
    );

    $response = $this->actingAs($director, 'sanctum')
        ->deleteJson("/api/v1/customers/{$customer->id}", [
            'reason' => 'Baja forzada por Director con obligaciones pendientes.',
            'force'  => true,
        ]);

    $response->assertNoContent();
    expect($customer->fresh()->is_active)->toBeFalse();
});

// ---------------------------------------------------------------------------
// 4. Non-director cannot force deactivate
// ---------------------------------------------------------------------------

it('Seller force=true is ignored — force only available to Directors', function (): void {
    $seller   = User::factory()->seller()->create();
    $customer = Customer::factory()->assignedTo($seller)->create();

    // Inject blocking action so non-force path would throw
    $this->instance(
        DeactivateCustomerAction::class,
        new class extends DeactivateCustomerAction {
            public function execute(Customer $customer, User $deactivatedBy, string $reason, bool $force = false): Customer
            {
                if (! $force) {
                    throw new CustomerHasPendingObligationsException(['Saldo pendiente.']);
                }

                return parent::execute($customer, $deactivatedBy, $reason, $force);
            }
        }
    );

    // Seller sends force=true but isForced() returns false for non-directors
    $response = $this->actingAs($seller, 'sanctum')
        ->deleteJson("/api/v1/customers/{$customer->id}", [
            'reason' => 'Intentando forzar baja como vendedor.',
            'force'  => true,
        ]);

    // Blocking exception fires because $force is false for Sellers
    $response->assertStatus(409);
});

// ---------------------------------------------------------------------------
// 5. 404 for non-existent customer
// ---------------------------------------------------------------------------

it('returns 404 when deactivating a non-existent customer', function (): void {
    $director = User::factory()->director()->create();
    $fakeId   = '00000000-0000-4000-8000-000000000001';

    $this->actingAs($director, 'sanctum')
        ->deleteJson("/api/v1/customers/{$fakeId}", [
            'reason' => 'Cliente inexistente.',
        ])
        ->assertNotFound();
});
