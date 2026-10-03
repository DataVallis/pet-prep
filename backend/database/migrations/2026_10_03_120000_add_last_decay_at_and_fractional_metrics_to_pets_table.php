<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M1-01 decay rewrite.
 *
 * - `last_decay_at`: the decay clock. Elapsed time for decay is measured only
 *   from this column, never from `updated_at` (which any write resets).
 * - `frozen_at` (M1-02): start of the current hard stop / illness freeze, used
 *   to shift the neglect clocks (*_zero_since) forward when the pet thaws.
 * - The four metric columns become `double precision` so fractional decay
 *   (e.g. 0.133 %/min for mutt hunger) accumulates without rounding loss.
 *   The 0–100 CHECK constraints are kept (PostgreSQL rewrites them with the
 *   column). API / broadcast payloads round for display (see Pet::displayMetric()).
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $metrics = ['hunger_level', 'thirst_level', 'energy_level', 'hygiene_level'];

    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->timestamp('last_decay_at')->nullable()->after('last_step_reset_at');
            // Start of the current hard stop / illness freeze (M1-02); null when not frozen.
            $table->timestamp('frozen_at')->nullable()->after('last_decay_at');
        });

        DB::table('pets')->whereNull('last_decay_at')->update(['last_decay_at' => now()]);
        DB::table('pets')
            ->where(fn ($q) => $q->where('is_hard_stopped', true)->orWhere('illness_until', '>', now()))
            ->update(['frozen_at' => now()]);

        if (DB::getDriverName() === 'pgsql') {
            foreach ($this->metrics as $column) {
                DB::statement("ALTER TABLE pets ALTER COLUMN {$column} TYPE double precision USING {$column}::double precision");
            }
        } else {
            Schema::table('pets', function (Blueprint $table) {
                foreach ($this->metrics as $column) {
                    $table->double($column)->default(100)->change();
                }
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach ($this->metrics as $column) {
                DB::statement("ALTER TABLE pets ALTER COLUMN {$column} TYPE integer USING round({$column})::integer");
            }
        } else {
            Schema::table('pets', function (Blueprint $table) {
                foreach ($this->metrics as $column) {
                    $table->integer($column)->default(100)->change();
                }
            });
        }

        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn(['last_decay_at', 'frozen_at']);
        });
    }
};
