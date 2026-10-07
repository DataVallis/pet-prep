<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M3-11: plan per pet, 7-day trial, payment lock (PAYMENTS_SPEC, David
 * 2026-10-07 P1–P4).
 *
 *  - pets.plan: `free` (mutt sandbox, forever) | `challenge` (12-week program).
 *  - pets.trial_ends_at: birth + 7 days (challenge only; null before birth).
 *  - pets.challenge_paid_at / challenge_paid_source: `purchase` (a challenge
 *    credit is assigned) | `grandfathered` (pets from before this release).
 *  - pets.payment_locked_at: the game-loop freeze of an unpaid challenge after
 *    its trial (lock reason `payment_required`); set by the tick, cleared on
 *    payment. Part of Pet::isFrozen() like the hard stop.
 *  - pets.trial_reminder_sent_at: the parent's "trial ends tomorrow" push
 *    was decided (once per pet).
 *  - child_login_pins.plan: the plan the parent chose for the new pet (null =
 *    an old app build → `challenge`).
 *  - pet_status_periods kind `payment_lock` (routines excused, training
 *    sessions interrupted) and push types `trial_ending` / `payment_required`.
 *
 * Backfill: every existing pet becomes `challenge`, paid, source
 * `grandfathered` (nobody is locked by the deploy); `challenge_paid_at` =
 * birth (or creation for an unborn pet), `trial_ends_at` = birth + 7 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->string('plan', 16)->default('challenge');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('challenge_paid_at')->nullable();
            $table->string('challenge_paid_source', 16)->nullable();
            $table->timestamp('payment_locked_at')->nullable();
            $table->timestamp('trial_reminder_sent_at')->nullable();
        });

        DB::statement("UPDATE pets SET challenge_paid_at = COALESCE(born_at, created_at, now()), challenge_paid_source = 'grandfathered', trial_ends_at = born_at + interval '7 days'");

        DB::statement("ALTER TABLE pets ADD CONSTRAINT pets_plan_check CHECK (plan IN ('free', 'challenge'))");
        DB::statement("ALTER TABLE pets ADD CONSTRAINT pets_challenge_paid_check CHECK ((challenge_paid_at IS NULL AND challenge_paid_source IS NULL) OR (challenge_paid_at IS NOT NULL AND challenge_paid_source IN ('purchase', 'grandfathered')))");
        DB::statement("ALTER TABLE pets ADD CONSTRAINT pets_free_plan_check CHECK (plan = 'challenge' OR (challenge_paid_at IS NULL AND trial_ends_at IS NULL AND payment_locked_at IS NULL))");
        // The tick's trial scan: unpaid challenge pets only.
        DB::statement("CREATE INDEX pets_unpaid_trial_idx ON pets (trial_ends_at) WHERE plan = 'challenge' AND challenge_paid_at IS NULL");

        Schema::table('child_login_pins', function (Blueprint $table) {
            $table->string('plan', 16)->nullable();
        });
        DB::statement("ALTER TABLE child_login_pins ADD CONSTRAINT child_login_pins_plan_check CHECK (plan IS NULL OR plan IN ('free', 'challenge'))");

        DB::statement('ALTER TABLE pet_status_periods DROP CONSTRAINT pet_status_periods_kind_check');
        DB::statement("ALTER TABLE pet_status_periods ADD CONSTRAINT pet_status_periods_kind_check CHECK (kind IN ('hard_stop', 'illness', 'inactive', 'payment_lock'))");

        DB::statement('ALTER TABLE push_notifications DROP CONSTRAINT push_notifications_type_check');
        DB::statement("ALTER TABLE push_notifications ADD CONSTRAINT push_notifications_type_check CHECK (type IN ('soft_warning', 'critical_alert', 'walk_reminder', 'parent_intervention_alarm', 'illness_triggered', 'game_over_virtual_shelter', 'trial_ending', 'payment_required'))");
    }

    public function down(): void
    {
        DB::table('push_notifications')->whereIn('type', ['trial_ending', 'payment_required'])->delete();
        DB::statement('ALTER TABLE push_notifications DROP CONSTRAINT push_notifications_type_check');
        DB::statement("ALTER TABLE push_notifications ADD CONSTRAINT push_notifications_type_check CHECK (type IN ('soft_warning', 'critical_alert', 'walk_reminder', 'parent_intervention_alarm', 'illness_triggered', 'game_over_virtual_shelter'))");

        DB::table('pet_status_periods')->where('kind', 'payment_lock')->delete();
        DB::statement('ALTER TABLE pet_status_periods DROP CONSTRAINT pet_status_periods_kind_check');
        DB::statement("ALTER TABLE pet_status_periods ADD CONSTRAINT pet_status_periods_kind_check CHECK (kind IN ('hard_stop', 'illness', 'inactive'))");

        DB::statement('ALTER TABLE child_login_pins DROP CONSTRAINT IF EXISTS child_login_pins_plan_check');
        Schema::table('child_login_pins', function (Blueprint $table) {
            $table->dropColumn('plan');
        });

        DB::statement('DROP INDEX IF EXISTS pets_unpaid_trial_idx');
        DB::statement('ALTER TABLE pets DROP CONSTRAINT IF EXISTS pets_free_plan_check');
        DB::statement('ALTER TABLE pets DROP CONSTRAINT IF EXISTS pets_challenge_paid_check');
        DB::statement('ALTER TABLE pets DROP CONSTRAINT IF EXISTS pets_plan_check');
        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn(['plan', 'trial_ends_at', 'challenge_paid_at', 'challenge_paid_source', 'payment_locked_at', 'trial_reminder_sent_at']);
        });
    }
};
