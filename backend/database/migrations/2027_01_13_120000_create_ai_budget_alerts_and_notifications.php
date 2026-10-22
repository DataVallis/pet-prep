<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M5-T15 (David 2026-10-22): AI budget warnings.
 *
 *  - `ai_budget_alerts`: one row per (budget, period, period key, threshold) that
 *    was announced to the superadmins — the dedupe, so every threshold is sent at
 *    most once per budget day / month (or per day for the fal balance).
 *  - index (error_reason, created_at) on ai_spend_ledger for the 24 h fal_balance count.
 *  - `notifications`: Laravel's standard table, for Filament database
 *    notifications (the bell in /admin). Only superadmins get rows; no child data.
 *
 * Additive: no existing row changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_budget_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('budget', 20);      // production | lab | fal_balance
            $table->string('period', 10);      // day | month
            $table->string('period_key', 10);  // 2026-10-22 | 2026-10 (budget timezone)
            $table->unsignedSmallInteger('threshold'); // 80 | 100 (percent); fal_balance: 0 low, 100 exhausted
            $table->decimal('spent_usd', 12, 4)->nullable();
            $table->decimal('cap_usd', 12, 4)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['budget', 'period', 'period_key', 'threshold']);
        });

        DB::statement("ALTER TABLE ai_budget_alerts ADD CONSTRAINT ai_budget_alerts_budget_check CHECK (budget IN ('production', 'lab', 'fal_balance'))");
        DB::statement("ALTER TABLE ai_budget_alerts ADD CONSTRAINT ai_budget_alerts_period_check CHECK (period IN ('day', 'month'))");

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        // System health counts fal_balance refusals of the last 24 h.
        Schema::table('ai_spend_ledger', function (Blueprint $table) {
            $table->index(['error_reason', 'created_at'], 'ai_spend_ledger_error_reason_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('ai_spend_ledger', function (Blueprint $table) {
            $table->dropIndex('ai_spend_ledger_error_reason_created_at_index');
        });
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('ai_budget_alerts');
    }
};
