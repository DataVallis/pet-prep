<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M5-R16 (David 2026-10-12, "1 ura, samo opozorilo"): new breed_stage_params
 * key `meal_exercise_gap_minutes` — for this many minutes after a meal the
 * child app warns "wait with the walk, the dog is digesting" (GDV / bloat
 * advice of PDSA). Mirrored in StageParamKey. The rows (Cane Corso,
 * Dobermann) come from the insert-only BreedStageParamsSeeder.
 *
 * The key check is rebuilt from the constraint as it is now (plus or minus
 * this key), so a parallel migration that adds another key is never undone.
 * Expand-only; down() deletes the rows of the key and removes it from the
 * check again.
 */
return new class extends Migration
{
    private const KEY = 'meal_exercise_gap_minutes';

    public function up(): void
    {
        $keys = $this->currentKeys();
        if (! in_array(self::KEY, $keys, true)) {
            $keys[] = self::KEY;
        }
        $this->replaceCheck($keys);
    }

    public function down(): void
    {
        DB::table('breed_stage_params')->where('key', self::KEY)->delete();
        $this->replaceCheck(array_values(array_diff($this->currentKeys(), [self::KEY])));
    }

    /** @return list<string> */
    private function currentKeys(): array
    {
        $definition = (string) DB::scalar(
            "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'breed_stage_params_key_check'"
        );
        preg_match_all("/'([a-z0-9_]+)'/", $definition, $m);
        $keys = array_values(array_unique($m[1]));
        if ($keys === []) {
            throw new RuntimeException('breed_stage_params_key_check not found or empty.');
        }

        return $keys;
    }

    /** @param list<string> $keys */
    private function replaceCheck(array $keys): void
    {
        $list = implode(', ', array_map(fn (string $k): string => "'{$k}'", $keys));
        DB::statement('ALTER TABLE breed_stage_params DROP CONSTRAINT IF EXISTS breed_stage_params_key_check');
        DB::statement("ALTER TABLE breed_stage_params ADD CONSTRAINT breed_stage_params_key_check CHECK (key IN ({$list}))");
    }
};
