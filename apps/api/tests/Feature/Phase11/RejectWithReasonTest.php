<?php

declare(strict_types=1);

use App\Enums\AuthorizationType;
use App\Enums\UserRole;
use App\Events\AuthorizationResolved as AuthorizationResolvedEvent;
use App\Models\AuthorizationRequest;
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
        'proposed_value' => '500.0000',
        'value_currency' => 'USD',
        'reason'         => 'El cliente pidió precio muy por debajo del mínimo.',
        'status'         => 'pending',
    ]);
});

it('director can reject a request with a reason', function (): void {
    $rejectionReason = 'No se puede bajar más del 15% del precio de referencia sin aprobación de socios.';

    $response = $this->actingAs($this->director, 'sanctum')
        ->patchJson("/api/v1/authorizations/{$this->authRequest->id}/resolve", [
            'action'           => 'reject',
            'rejection_reason' => $rejectionReason,
        ]);

    $response->assertStatus(200);

    $this->authRequest->refresh();
    expect($this->authRequest->status)->toBe('rejected')
        ->and($this->authRequest->rejection_reason)->toBe($rejectionReason)
        ->and($this->authRequest->resolved_by)->toBe($this->director->id);
});

it('rejection fires AuthorizationResolved event on the requester channel', function (): void {
    Event::fake([AuthorizationResolvedEvent::class]);

    $this->actingAs($this->director, 'sanctum')
        ->patchJson("/api/v1/authorizations/{$this->authRequest->id}/resolve", [
            'action'           => 'reject',
            'rejection_reason' => 'Precio demasiado bajo.',
        ]);

    Event::assertDispatched(AuthorizationResolvedEvent::class, function (AuthorizationResolvedEvent $event): bool {
        return $event->authorizationRequest->status === 'rejected'
            && $event->authorizationRequest->rejection_reason === 'Precio demasiado bajo.';
    });
});

it('requester receives notification with rejection reason', function (): void {
    Notification::fake();

    $rejectionReason = 'El margen no lo permite.';

    $this->actingAs($this->director, 'sanctum')
        ->patchJson("/api/v1/authorizations/{$this->authRequest->id}/resolve", [
            'action'           => 'reject',
            'rejection_reason' => $rejectionReason,
        ]);

    Notification::assertSentTo(
        $this->seller,
        AuthorizationResolvedNotification::class,
        function (AuthorizationResolvedNotification $notification) use ($rejectionReason): bool {
            $data = $notification->toArray($this->seller);

            return $data['status'] === 'rejected'
                && $data['rejection_reason'] === $rejectionReason;
        }
    );
});

it('rejection_reason is required when action is reject', function (): void {
    $response = $this->actingAs($this->director, 'sanctum')
        ->patchJson("/api/v1/authorizations/{$this->authRequest->id}/resolve", [
            'action' => 'reject',
            // rejection_reason intentionally omitted
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['rejection_reason']);
});

it('rejection persists reason in the database', function (): void {
    $reason = 'Política de precios no lo permite.';

    $this->actingAs($this->director, 'sanctum')
        ->patchJson("/api/v1/authorizations/{$this->authRequest->id}/resolve", [
            'action'           => 'reject',
            'rejection_reason' => $reason,
        ]);

    $this->assertDatabaseHas('authorization_requests', [
        'id'               => $this->authRequest->id,
        'status'           => 'rejected',
        'rejection_reason' => $reason,
    ]);
});
