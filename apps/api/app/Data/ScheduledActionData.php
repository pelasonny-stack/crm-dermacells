<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\ScheduledAction;
use Spatie\LaravelData\Data;

/**
 * DTO for ScheduledAction responses.
 */
class ScheduledActionData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $customer_id,
        public readonly string $created_by,
        public readonly string $scheduled_date,
        public readonly string $note,
        public readonly bool $is_resolved,
        public readonly string|null $resolved_at,
        public readonly string $created_at,
        public readonly string $updated_at,
    ) {}

    public static function fromModel(ScheduledAction $action): self
    {
        return new self(
            id:             $action->id,
            customer_id:    $action->customer_id,
            created_by:     $action->created_by,
            scheduled_date: $action->scheduled_date->toDateString(),
            note:           $action->note,
            is_resolved:    $action->is_resolved,
            resolved_at:    $action->resolved_at?->toIso8601String(),
            created_at:     $action->created_at?->toIso8601String() ?? '',
            updated_at:     $action->updated_at?->toIso8601String() ?? '',
        );
    }
}
