<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One-off backfill (M1-04): energy of existing pets = min(100,
 * daily_step_count / breed daily_steps_required × 100), the formula the
 * step sync uses. Before M1-04 energy was a free value; without this a pet
 * could keep e.g. 100 % until the next midnight and pass the daily walk
 * check without walking.
 *
 * Birth-day grace: pets born on the current family-local day keep their
 * energy (they are allowed 100 % until their first local midnight).
 * Pets without a breed config or with a goal ≤ 0 are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();

        $pets = DB::table('pets')
            ->join('users as child', 'child.id', '=', 'pets.user_id')
            ->leftJoin('users as parent', 'parent.id', '=', 'child.parent_id')
            ->join('breed_configs', 'breed_configs.breed_slug', '=', DB::raw("replace(pets.breed_type, '_', '-')"))
            ->select([
                'pets.id',
                'pets.daily_step_count',
                'pets.born_at',
                'pets.created_at',
                'parent.timezone',
                'breed_configs.daily_steps_required',
            ])
            ->orderBy('pets.id')
            ->get();

        foreach ($pets as $pet) {
            $goal = (int) $pet->daily_steps_required;
            if ($goal <= 0) {
                continue;
            }

            $timezone = $pet->timezone ?: 'Europe/Ljubljana';
            $born = $pet->born_at ?? $pet->created_at;
            if ($born !== null
                && Carbon::parse($born, 'UTC')->setTimezone($timezone)->toDateString() === $now->copy()->setTimezone($timezone)->toDateString()) {
                continue; // birth-day grace
            }

            $energy = min(100.0, max(0, (int) $pet->daily_step_count) / $goal * 100);

            DB::table('pets')->where('id', $pet->id)->update(['energy_level' => $energy]);
        }
    }

    public function down(): void
    {
        // Data backfill: nothing to undo.
    }
};
