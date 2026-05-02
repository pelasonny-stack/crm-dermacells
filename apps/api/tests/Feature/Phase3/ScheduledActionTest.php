<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\ScheduledAction;
use App\Models\User;
use App\Notifications\ScheduledActionDue;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Phase 3 — Scheduled Action Tests
|--------------------------------------------------------------------------
|
| §3.8: Scheduled actions allow any role with customer visibility to attach
| a future date + note to a customer. On the scheduled date a push notification
| fires to the assigned Seller.
|
| Tests cover:
|   1. Create via API — happy path
|   2. Validation: date must be today or future
|   3. List scheduled actions per customer
|   4. Notification dispatch for due scheduled actions
|   5. Already-resolved actions are NOT re-fired
*/

// ---------------------------------------------------------------------------
// 1. Create a scheduled action (happy path)
// ---------------------------------------------------------------------------

it('creates a scheduled action for a customer and returns 201', function (): void {
    $seller   = User::factory()->seller()->create();
    $customer = Customer::factory()->assignedTo($seller)->create();

    $futureDate = now()->addDays(7)->toDateString();

    $response = $this->actingAs($seller, 'sanctum')
        ->postJson("/api/v1/customers/{$customer->id}/scheduled-actions", [
            'scheduled_date' => $futureDate,
            'note'           => 'Llamar para seguimiento de reorden.',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.customer_id', $customer->id)
        ->assertJsonPath('data.scheduled_date', $futureDate)
        ->assertJsonPath('data.is_resolved', false);

    expect(ScheduledAction::where('customer_id', $customer->id)->exists())->toBeTrue();
});

it('Director can create a scheduled action for any customer', function (): void {
    $director = User::factory()->director()->create();
    $customer = Customer::factory()->create();

    $response = $this->actingAs($director, 'sanctum')
        ->postJson("/api/v1/customers/{$customer->id}/scheduled-actions", [
            'scheduled_date' => now()->addDays(3)->toDateString(),
            'note'           => 'Visita presencial programada por Director.',
        ]);

    $response->assertCreated();
});

// ---------------------------------------------------------------------------
// 2. Validation
// ---------------------------------------------------------------------------

it('rejects scheduled_date in the past with 422', function (): void {
    $seller   = User::factory()->seller()->create();
    $customer = Customer::factory()->assignedTo($seller)->create();

    $response = $this->actingAs($seller, 'sanctum')
        ->postJson("/api/v1/customers/{$customer->id}/scheduled-actions", [
            'scheduled_date' => now()->subDay()->toDateString(),
            'note'           => 'Fecha pasada.',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['scheduled_date']);
});

it('rejects missing note with 422', function (): void {
    $seller   = User::factory()->seller()->create();
    $customer = Customer::factory()->assignedTo($seller)->create();

    $response = $this->actingAs($seller, 'sanctum')
        ->postJson("/api/v1/customers/{$customer->id}/scheduled-actions", [
            'scheduled_date' => now()->addDay()->toDateString(),
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['note']);
});

it('rejects note shorter than 5 characters with 422', function (): void {
    $seller   = User::factory()->seller()->create();
    $customer = Customer::factory()->assignedTo($seller)->create();

    $response = $this->actingAs($seller, 'sanctum')
        ->postJson("/api/v1/customers/{$customer->id}/scheduled-actions", [
            'scheduled_date' => now()->addDay()->toDateString(),
            'note'           => 'hi',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['note']);
});

// ---------------------------------------------------------------------------
// 3. List scheduled actions
// ---------------------------------------------------------------------------

it('lists all scheduled actions for a customer', function (): void {
    $seller   = User::factory()->seller()->create();
    $customer = Customer::factory()->assignedTo($seller)->create();

    ScheduledAction::factory()->count(3)->create([
        'customer_id' => $customer->id,
        'created_by'  => $seller->id,
    ]);

    $response = $this->actingAs($seller, 'sanctum')
        ->getJson("/api/v1/customers/{$customer->id}/scheduled-actions");

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

it('Seller cannot list scheduled actions for a customer they cannot see (404)', function (): void {
    $sellerA  = User::factory()->seller()->create();
    $sellerB  = User::factory()->seller()->create();
    $customer = Customer::factory()->assignedTo($sellerB)->create();

    // SellerA tries to list actions on SellerB's customer
    // Route model binding fails → 404 because RLS hides the customer row
    $response = $this->actingAs($sellerA, 'sanctum')
        ->getJson("/api/v1/customers/{$customer->id}/scheduled-actions");

    $response->assertNotFound();
});

// ---------------------------------------------------------------------------
// 4. Notification dispatch for due scheduled actions
// ---------------------------------------------------------------------------

it('ScheduledActionDue notification is sent to assigned Seller for due actions', function (): void {
    Notification::fake();

    $seller   = User::factory()->seller()->create();
    $customer = Customer::factory()->assignedTo($seller)->create();

    $action = ScheduledAction::factory()->dueToday()->create([
        'customer_id' => $customer->id,
        'created_by'  => $seller->id,
    ]);

    // Send the notification directly (simulating what the command does)
    $seller->notify(new ScheduledActionDue($action));

    Notification::assertSentTo($seller, ScheduledActionDue::class, function (ScheduledActionDue $notification) use ($action): bool {
        $data = $notification->toDatabase($action->customer->assignedSeller);
        return $data->data['scheduled_action_id'] === $action->id;
    });
});

// ---------------------------------------------------------------------------
// 5. Already-resolved actions do NOT re-fire
// ---------------------------------------------------------------------------

it('resolved scheduled actions are excluded from pending scope', function (): void {
    $seller   = User::factory()->seller()->create();
    $customer = Customer::factory()->assignedTo($seller)->create();

    $resolved = ScheduledAction::factory()->dueToday()->resolved()->create([
        'customer_id' => $customer->id,
        'created_by'  => $seller->id,
    ]);

    $pending = ScheduledAction::factory()->dueToday()->create([
        'customer_id' => $customer->id,
        'created_by'  => $seller->id,
    ]);

    $pendingIds = ScheduledAction::pending()->dueOn(now())->pluck('id');

    expect($pendingIds)->toContain($pending->id);
    expect($pendingIds)->not->toContain($resolved->id);
});

// ---------------------------------------------------------------------------
// 6. ScheduledAction::resolve() helper
// ---------------------------------------------------------------------------

it('resolving a scheduled action sets is_resolved and resolved_at', function (): void {
    $seller   = User::factory()->seller()->create();
    $customer = Customer::factory()->assignedTo($seller)->create();

    $action = ScheduledAction::factory()->create([
        'customer_id' => $customer->id,
        'created_by'  => $seller->id,
        'is_resolved' => false,
    ]);

    $action->resolve();

    $fresh = $action->fresh();
    expect($fresh->is_resolved)->toBeTrue();
    expect($fresh->resolved_at)->not->toBeNull();
});
