<?php

declare(strict_types=1);

namespace App\Domain\AI\Tools;

use App\Models\User;

/**
 * LLMTool — contract for function-calling tools (Phase 13 — §11.5).
 *
 * Each implementation is dispatched by the natural-language use case
 * when the model emits a tool call matching its name(). RBAC scope is
 * enforced server-side inside execute() — never trust args alone.
 */
interface LLMTool
{
    public function name(): string;

    public function description(): string;

    /**
     * JSON schema for the tool arguments — passed to the LLM as the
     * tool's input definition.
     *
     * @return array<string, mixed>
     */
    public function schema(): array;

    /**
     * Execute the tool against the data layer with the calling user's RBAC
     * applied. The caller guarantees auth — this method MUST also scope its
     * query so a Seller can never read another Seller's data via tool args.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(array $args, User $caller): array;
}
