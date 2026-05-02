<?php

declare(strict_types=1);

use App\Models\AiSetting;
use App\Models\AiUsage;
use App\Models\AiUserOverride;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Phase 13 — Token Cap Exceeded Test (§11.5 verification)
|--------------------------------------------------------------------------
|
| Verifies that EnforceAiTokenCap returns 429 AI_TOKEN_CAP_EXCEEDED when
| the calling user has exhausted their monthly token cap.
*/

beforeEach(function (): void {
    $this->user = User::factory()->seller()->create();

    $settings                 = AiSetting::current();
    $settings->global_enabled = true;
    $settings->provider       = 'openai';
    $settings->model          = 'gpt-4o-mini';
    $settings->save();
});

it('returns 429 with AI_TOKEN_CAP_EXCEEDED when monthly usage exceeds cap', function (): void {
    // Tight cap.
    AiUserOverride::create([
        'user_id'           => $this->user->getKey(),
        'enabled'           => true,
        'monthly_token_cap' => 100,
        'updated_at'        => now(),
    ]);

    // Insert usage over the cap for this month.
    AiUsage::create([
        'user_id'             => $this->user->getKey(),
        'customer_id'         => null,
        'period_month'        => now()->startOfMonth()->toDateString(),
        'provider'            => 'openai',
        'model'               => 'gpt-4o-mini',
        'input_tokens'        => 100,
        'cached_input_tokens' => 0,
        'output_tokens'       => 100,
        'cost_estimate_usd'   => '0.0001',
        'created_at'          => now(),
    ]);

    $response = $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/ai/usage/me');

    $response->assertStatus(429);
    expect($response)->toHaveProblemCode('AI_TOKEN_CAP_EXCEEDED');
});

it('allows the request when usage is below cap', function (): void {
    AiUserOverride::create([
        'user_id'           => $this->user->getKey(),
        'enabled'           => true,
        'monthly_token_cap' => 1_000_000,
        'updated_at'        => now(),
    ]);

    $response = $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/ai/usage/me');

    $response->assertStatus(200);
});
