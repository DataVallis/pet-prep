<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product switches edited in the superadmin panel (M5-R06-09, David
 * 2026-10-10: "the cats switch must be in the admin, not in the server .env").
 *
 *  - app_settings: one row per key, value = JSON (read through
 *    App\Services\AppSettingsService, cached briefly, busted on save);
 *  - app_setting_changes: append-only audit (who, when, old → new).
 *
 * Additive only. No row = the default of the key (cats: off).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->jsonb('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('app_setting_changes', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('old')->nullable();
            $table->jsonb('new');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_setting_changes');
        Schema::dropIfExists('app_settings');
    }
};
