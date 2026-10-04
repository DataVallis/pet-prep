<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Routines, Care Score and traffic light (M2-06, David 2026-10-04,
 * PRODUCT_SPEC §9/§11).
 *
 *  - pet_status_periods: when a pet could not be cared for — hard stop,
 *    illness (vet), inactive (incl. game over). Written from now on by the
 *    Pet model hooks (PetStatusPeriodService); routines overlapping such a
 *    period are not expected unless they were done anyway.
 *  - pet_daily_routines: the routine ledger of closed family-local days,
 *    one row per expected routine (done | missed). Written by the decay tick
 *    (RoutineLedgerService::closeDueDays) once every routine of the day is
 *    resolved; today and unclosed days are computed live from the same rules.
 *    Materialising freezes history: a later change of quiet hours or feed
 *    windows does not rewrite past days.
 *  - pets.routines_closed_through / routines_next_close_at: the close pointer.
 *
 * Backfill (status periods only — the ledger fills itself lazily on the next
 * ticks, from max(birth, RoutineLedgerService::FIRST_LEDGER_DATE)):
 *  - illness: every `ignored_warning` row with value −1 (start = the walk
 *    row's illness_started_at when one lies in the 12 h before, else the row
 *    time), 12 h long;
 *  - hard stop: pets hard-stopped now, from frozen_at (else updated_at);
 *  - inactive: inactive pets, from their latest game-over row (else updated_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pet_status_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['pet_id', 'started_at']);
        });
        DB::statement("ALTER TABLE pet_status_periods ADD CONSTRAINT pet_status_periods_kind_check CHECK (kind IN ('hard_stop', 'illness', 'inactive'))");
        DB::statement('ALTER TABLE pet_status_periods ADD CONSTRAINT pet_status_periods_range_check CHECK (ended_at IS NULL OR ended_at >= started_at)');

        Schema::create('pet_daily_routines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();
            $table->date('local_date');
            $table->string('routine_type', 8);
            $table->unsignedSmallInteger('slot');
            $table->timestamp('opens_at');
            $table->timestamp('due_at');
            $table->string('status', 8);
            $table->timestamp('done_at')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('steps')->nullable();
            $table->unsignedInteger('goal')->nullable();
            $table->timestamps();

            $table->unique(['pet_id', 'local_date', 'routine_type', 'slot']);
            $table->index(['actor_user_id', 'local_date']);
        });
        DB::statement("ALTER TABLE pet_daily_routines ADD CONSTRAINT pet_daily_routines_type_check CHECK (routine_type IN ('feed', 'water', 'clean', 'walk'))");
        DB::statement("ALTER TABLE pet_daily_routines ADD CONSTRAINT pet_daily_routines_status_check CHECK (status IN ('done', 'missed'))");

        Schema::table('pets', function (Blueprint $table) {
            $table->date('routines_closed_through')->nullable();
            $table->timestamp('routines_next_close_at')->nullable();
            $table->index('routines_next_close_at');
        });

        $this->backfillStatusPeriods();
    }

    public function down(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->dropIndex(['routines_next_close_at']);
            $table->dropColumn(['routines_closed_through', 'routines_next_close_at']);
        });
        Schema::dropIfExists('pet_daily_routines');
        Schema::dropIfExists('pet_status_periods');
    }

    private function backfillStatusPeriods(): void
    {
        $now = now();

        // Illness: one 12 h period per illness row (value −1).
        $illnessRows = DB::table('activities_log')
            ->where('activity_type', 'ignored_warning')
            ->where('value', -1)
            ->orderBy('id')
            ->get(['pet_id', 'created_at']);

        foreach ($illnessRows as $row) {
            $at = Carbon::parse($row->created_at, 'UTC')->startOfSecond();
            $walkStart = DB::table('pet_daily_walks')
                ->where('pet_id', $row->pet_id)
                ->whereNotNull('illness_started_at')
                ->whereBetween('illness_started_at', [$at->copy()->subHours(12), $at])
                ->orderByDesc('illness_started_at')
                ->value('illness_started_at');
            $start = $walkStart !== null ? Carbon::parse($walkStart, 'UTC') : $at;

            DB::table('pet_status_periods')->insert([
                'pet_id' => $row->pet_id,
                'kind' => 'illness',
                'started_at' => $start,
                'ended_at' => $start->copy()->addHours(12),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Hard stop in force right now.
        foreach (DB::table('pets')->where('is_hard_stopped', true)->get(['id', 'frozen_at', 'updated_at']) as $pet) {
            DB::table('pet_status_periods')->insert([
                'pet_id' => $pet->id,
                'kind' => 'hard_stop',
                'started_at' => $pet->frozen_at ?? $pet->updated_at ?? $now,
                'ended_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Inactive (game over or switched off by an admin) right now.
        foreach (DB::table('pets')->where('is_active', false)->get(['id', 'updated_at']) as $pet) {
            $gameOverAt = DB::table('activities_log')
                ->where('pet_id', $pet->id)
                ->where('activity_type', 'ignored_warning')
                ->where('value', -2)
                ->max('created_at');

            DB::table('pet_status_periods')->insert([
                'pet_id' => $pet->id,
                'kind' => 'inactive',
                'started_at' => $gameOverAt ?? $pet->updated_at ?? $now,
                'ended_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
