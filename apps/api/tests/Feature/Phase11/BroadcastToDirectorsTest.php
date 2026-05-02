<?php

declare(strict_types=1);

use App\Enums\AuthorizationType;
use App\Enums\UserRole;
use App\Events\AuthorizationRequested as AuthorizationRequestedEvent;
use App\Models\User;
use App\Models\Zone;
use App\Models\Customer;
use App\Models\PaymentTerm;
use App\Notifications\AuthorizationRequestedNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->zone     = Zone::factory()->create(['distributor_id' => null]);
    $this->seller   = User::factory()->create(['role' => UserRole::Seller, 'is_active' => true]);
    $this->directors = User::factory()->count(2)->create([
        'role'      => UserRole::Director,
        'is_active' => true,
    ]);
});

it('fires AuthorizationRequested event when seller submits a request', function (): void {
    Event::fake([AuthorizationRequestedEvent::class]);

    $this->actingAs($this->seller, 'sanctum')
        ->postJson('/api/v1/authorizations', [
            'type'           => AuthorizationType::PriceChange->value,
            'current_value'  => '750.00',
            'proposed_value' => '680.00',
            'value_currency' => 'USD',
            'reason'         => 'Test broadcast event.',
        ]);

    Event::assertDispatched(AuthorizationRequestedEvent::class, function (AuthorizationRequestedEvent $event): bool {
        return $event->authorizationRequest->requested_by === $this->seller->id
            && $event->authorizationRequest->status === 'pending';
    });
});

it('broadcasts on the private-director channel', function (): void {
    Event::fake([AuthorizationRequestedEvent::class]);

    $this->actingAs($this->seller, 'sanctum')
        ->postJson('/api/v1/authorizations', [
            'type'           => AuthorizationType::PriceChange->value,
            'current_value'  => '750.00',
            'proposed_value' => '680.00',
            'reason'         => 'Test canal director.',
        ]);

    Event::assertDispatched(AuthorizationRequestedEvent::class, function (AuthorizationRequestedEvent $event): bool {
        $channels = $event->broadcastOn();
        // PrivateChannel::name = 'private-director'
        return count($channels) === 1
            && str_contains($channels[0]->name, 'director');
    });
});

it('sends AuthorizationRequestedNotification to all active directors', function (): void {
    Notification::fake();

    $this->actingAs($this->seller, 'sanctum')
        ->postJson('/api/v1/authorizations', [
            'type'           => AuthorizationType::PriceChange->value,
            'current_value'  => '750.00',
            'proposed_value' => '680.00',
            'reason'         => 'Test notificación directors.',
        ]);

    // Both active directors should receive the notification
    foreach ($this->directors as $director) {
        Notification::assertSentTo($director, AuthorizationRequestedNotification::class);
    }
});

it('does not send notification to inactive directors', function (): void {
    Notification::fake();

    $inactiveDirector = User::factory()->create([
        'role'      => UserRole::Director,
        'is_active' => false,
    ]);

    $this->actingAs($this->seller, 'sanctum')
        ->postJson('/api/v1/authorizations', [
            'type'           => AuthorizationType::PriceChange->value,
            'current_value'  => '750.00',
            'proposed_value' => '680.00',
            'reason'         => 'Solo directores activos deben recibir la notificacion.',
        ]);

    Notification::assertNotSentTo($inactiveDirector, AuthorizationRequestedNotification::class);
});
