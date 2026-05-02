<?php

declare(strict_types=1);

use App\Models\AiSetting;
use App\Models\AiUserOverride;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Phase 13 — Per-User Override Test (§11.1 verification)
|--------------------------------------------------------------------------
|
| When the global switch is ON but a user's AiUserOverride.enabled = false,
| that user (Seller / Distributor) must receive 403 AI_DISABLED_FOR_USER.
| Directors are exempt from per-user override checks.
*/

beforeEach(function (): void {
    $settings                 = AiSetting::current();
    $settings->global_enabled = true;
    $settings->provider       = 'openai';
    $settings->model          = 'gpt-4o-mini';
    $settings->save();
});

it('returns 403 AI_DISABLED_FOR_USER when seller override is disabled', function (): void {
    $seller = User::factory()->seller()->create();

    AiUserOverride::create([
        'user_id'    => $seller->getKey(),
        'enabled'    => false,
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($seller, 'sanctum')
        ->getJson('/api/v1/ai/usage/me');

    $response->assertStatus(403);
    expect($response)->toHaveProblemCode('AI_DISABLED_FOR_USER');
});

it('director is exempt from per-user override and gets through', function (): void {
    $director = User::factory()->director()->create();

    AiUserOverride::create([
        'user_id'    => $director->getKey(),
        'enabled'    => false,
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($director, 'sanctum')
        ->getJson('/api/v1/ai/usage/me');

    $response->assertStatus(200);
});

it('seller without an override row passes through the gate', function (): void {
    $seller = User::factory()->seller()->create();

    $response = $this->actingAs($seller, 'sanctum')
        ->getJson('/api/v1/ai/usage/me');

    $response->assertStatus(200);
});
