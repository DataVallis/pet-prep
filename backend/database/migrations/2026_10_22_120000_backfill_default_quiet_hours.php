<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * fix/quiet-hours-default (2026-10-08, production bug: pushes at 00:00 and
 * ~03:30 for a family whose parent app showed quiet hours that the server
 * never had): every family gets the defaults (night 21:00–07:00
 * family-local, no school window, active — the values of QuietHours::DEFAULTS
 * on 2026-10-08, inlined so this migration never changes with app code)
 * unless it already has a row.
 *
 *  - Insert only: existing rows (also is_active = false — the parent's
 *    explicit choice) are never touched.
 *  - parent_id (NOT NULL, unique) = the family's first parent (lowest
 *    family_user id) who does not own a quiet_hours row yet; families
 *    without such a parent are skipped and read the same defaults from code
 *    (Pet::quietHours(), RoutineLedgerService).
 *  - insertOrIgnore (ON CONFLICT DO NOTHING) on both unique keys (family_id,
 *    parent_id): idempotent and safe if a parent saves meanwhile.
 *  - No game state is rewritten: closed days in pet_daily_routines are never
 *    recomputed; quiet hours apply from the next tick (today's live routines
 *    and the running neglect / potty clocks read them from now on).
 *
 * down() is a no-op: a default row cannot be told apart from one a parent
 * saved with the same values, and deleting it would bring the bug back.
 */
return new class extends Migration
{
    public function up(): void
    {
        $families = DB::table('families')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('quiet_hours')->whereColumn('quiet_hours.family_id', 'families.id'))
            ->orderBy('id')
            ->pluck('id');

        $now = now();
        foreach ($families as $familyId) {
            $parentId = DB::table('family_user')
                ->where('family_id', $familyId)
                ->where('role', 'parent')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('quiet_hours')->whereColumn('quiet_hours.parent_id', 'family_user.user_id'))
                ->orderBy('id')
                ->value('user_id');

            if ($parentId === null) {
                continue;
            }

            DB::table('quiet_hours')->insertOrIgnore([
                'parent_id' => $parentId,
                'family_id' => $familyId,
                'school_start' => null,
                'school_end' => null,
                'bedtime_start' => '21:00',
                'bedtime_end' => '07:00',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally empty (see above).
    }
};
