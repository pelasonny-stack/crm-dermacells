<?php

declare(strict_types=1);

use App\Enums\AuthorizationType;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Events\AuthorizationResolved as AuthorizationResolvedEvent;
use App\Models\AuthorizationRequest;
use App\Models\Customer;
use App\Models\PaymentTerm;
use App\Models\Sale;
use App\Models\User;
use App\Models\Zone;
use App\Notifications\AuthorizationResolvedNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->zone     = Zone::factory()->create(['distributor_id' => null]);
    $this->seller   = User::factory()->create(['role' => UserRole::Seller, 'is_active' => true]);
    $this->director = User::factory()->create(['role' => UserRole::Director, 'is_active' => true]);

    $this->authRequest = AuthorizationRequest::create([
        'type'           => AuthorizationType::PriceChange,
        'requested_by'   => $this->seller->id,
        'current_value'  => '750.0000',
        'proposed_value' => '680.0000',
        'value_currency' => 'USD',
        'reason'         => 'Descuento acordado con cliente.',
        'status'         => 'pending',
    ]);
});

it('director can approve a pending request and status transitions to approved', function (): void {
    $response = $this->actingAs($this->director, 'sanctum')
        ->patchJson("/api/v1/authorizations/{$this->authRequest->id}/resolve", [
            'action' => 'approve',
        ]);

    $response->assertStatus(200);

    $this->authRequest->refresh();
    expect($this->authRequest->status)->toBe('approved')
        ->and($this->authRequest->resolved_by)->toBe($this->director->id)
        ->and($this->authRequest->resolved_at)->not->toBeNull();
});

it('approval fires AuthorizationResolved event on the requester channel', function (): void {
    Event::fake([AuthorizationResolvedEvent::class]);

    $this->actingAs($this->director, 'sanctum')
        ->patchJson("/api/v1/authorizations/{$this->authRequest->id}/resolve", [
            'action' => 'approve',
        ]);

    Event::assertDispatched(AuthorizationResolvedEvent::class, function (AuthorizationResolvedEvent $event): bool {
        $channels = $event->broadcastOn();

        return $event->authorizationRequest->id === $this->authRequest->id
            && $event->authorizationRequest->status === 'approved'
            && count($channels) === 1
            && str_contains($channels[0]->name, $this->seller->id);
    });
});

it('approval sends AuthorizationResolvedNotification to the requester', function (): void {
    Notification::fake();

    $this->actingAs($this->director, 'sanctum')
        ->patchJson("/api/v1/authorizations/{$this->authRequest->id}/resolve", [
            'action' => 'approve',
        ]);

    Notification::assertSentTo($this->seller, AuthorizationResolvedNotification::class);
});

it('approved request has resolved_at timestamp persisted in database', function (): void {
    $this->actingAs($this->director, 'sanctum')
        ->patchJson("/api/v1/authorizations/{$this->authRequest->id}/resolve", [
            'action' => 'approve',
        ]);

    $this->assertDatabaseHas('authorization_requests', [
        'id'          => $this->authRequest->id,
        'status'      => 'approved',
        'resolved_by' => $this->director->id,
    ]);
});

it('cannot approve an already-approved request', function (): void {
    // First approval
    $this->actingAs($this->director, 'sanctum')
        ->patchJson("/api/v1/authorizations/{$this->authRequest->id}/resolve", [
            'action' => 'approve',
        ]);

    // Second attempt should return 409
    $response = $this->actingAs($this->director, 'sanctum')
        ->patchJson("/api/v1/authorizations/{$this->authRequest->id}/resolve", [
            'action' => 'approve',
        ]);

    $response->assertStatus(409);
    expect($response->json('code'))->toBe('AUTHORIZATION_ALREADY_RESOLVED');
});
