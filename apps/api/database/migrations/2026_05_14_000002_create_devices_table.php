<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14 — FCM device registration table.
 *
 * Required by Phase 15 mobile (Expo) FCM flow but defined here per scope.
 * The Device model maps to this table and uses HasUuids for the primary key.
 *
 * UNIQUE (user_id, fcm_token) ensures idempotent POST /devices:
 *   - First registration → 201 Created.
 *   - Same token re-registered → 200 OK (touch last_seen_at).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('fcm_token');
            $table->string('platform', 20); // ios | android | web
            $table->string('device_name', 255)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            // Idempotency: one token per user-device combination.
            $table->unique(['user_id', 'fcm_token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
