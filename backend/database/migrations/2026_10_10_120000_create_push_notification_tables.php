<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M3-02 push notifications (Expo push).
 *
 * - device_push_tokens: one row per Expo push token (a token identifies an
 *   app install). Re-registering the token from another account moves it.
 *   Linked to the Sanctum token that registered it, so logout / device
 *   revocation / pruning (personal_access_tokens rows deleted) removes the
 *   push token with it (FK cascade).
 * - push_notifications: one row per escalation push decision (audit, duplicate
 *   guard, idempotency key); recipients are user ids, never names.
 * - push_tickets: one Expo ticket per (notification, device) — the unique key
 *   makes a retried send job skip devices that already got the message;
 *   receipts are checked later (DeviceNotRegistered → token disabled).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')->nullable()
                ->constrained('personal_access_tokens')->cascadeOnDelete();
            $table->string('expo_push_token', 255)->unique();
            $table->string('platform', 16);
            $table->string('app_version', 32)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->string('disabled_reason', 64)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'disabled_at']);
        });

        DB::statement("ALTER TABLE device_push_tokens ADD CONSTRAINT device_push_tokens_platform_check CHECK (platform IN ('ios', 'android'))");

        Schema::create('push_notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('pet_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('metric', 20)->nullable();
            // [{"user_id": 12, "audience": "child"}, …] — ids only, no names.
            $table->jsonb('recipients');
            $table->string('status', 16);
            $table->string('suppressed_reason', 32)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['pet_id', 'type', 'created_at']);
        });

        DB::statement("ALTER TABLE push_notifications ADD CONSTRAINT push_notifications_status_check CHECK (status IN ('queued', 'sent', 'suppressed', 'failed'))");
        DB::statement("ALTER TABLE push_notifications ADD CONSTRAINT push_notifications_type_check CHECK (type IN ('soft_warning', 'critical_alert', 'parent_intervention_alarm', 'illness_triggered', 'game_over_virtual_shelter'))");

        Schema::create('push_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('push_notification_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_push_token_id')->constrained()->cascadeOnDelete();
            $table->string('ticket_id', 64)->nullable();
            $table->string('status', 8);
            $table->string('error', 64)->nullable();
            $table->string('receipt_status', 8)->nullable();
            $table->string('receipt_error', 64)->nullable();
            $table->timestamp('receipt_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['push_notification_id', 'device_push_token_id']);
            $table->index(['receipt_checked_at', 'created_at']);
        });

        DB::statement("ALTER TABLE push_tickets ADD CONSTRAINT push_tickets_status_check CHECK (status IN ('ok', 'error'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('push_tickets');
        Schema::dropIfExists('push_notifications');
        Schema::dropIfExists('device_push_tokens');
    }
};
