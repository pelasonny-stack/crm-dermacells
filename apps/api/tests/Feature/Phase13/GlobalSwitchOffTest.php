<?php

declare(strict_types=1);

use App\Models\AiSetting;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Phase 13 — Global Switch OFF Test (§11.1 verification)
|--------------------------------------------------------------------------
|
| When the AI module is globally disabled, every /ai/* endpoint must
| respond with 503 AI_GLOBALLY_DISABLED — no LLM call is attempted.
*/

it('returns 503 AI_GLOBALLY_DISABLED on all /ai/* routes when global switch is off', function (): void {
    $user = User::factory()->seller()->create();

    $settings                 = AiSetting::current();
    $settings->global_enabled = false;
    $settings->save();

    $endpoints = [
        ['GET',  '/api/v1/ai/usage/me'],
        ['GET',  '/api/v1/ai/digest/me'],
    ];

    foreach ($endpoints as [$method, $url]) {
        $response = $this->actingAs($user, 'sanctum')->json($method, $url);

        $response->assertStatus(503);
        expect($response)->toHaveProblemCode('AI_GLOBALLY_DISABLED');
    }
});
