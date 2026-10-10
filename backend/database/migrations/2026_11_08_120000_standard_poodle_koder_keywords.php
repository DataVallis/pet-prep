<?php

use App\Services\BreedCatalogService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M5-R10-07 follow-up (David 2026-10-10): the Slovenian name of the Standard
 * Poodle is "Veliki koder" (koder = the Slovenian name of the Poodle; "pudelj"
 * stays a search synonym). BreedConfigsSeeder is insert-only, so the row seeded
 * on 2026-10-10 keeps the old keywords — this updates it, but only while it
 * still holds exactly the originally seeded list (an admin edit always wins).
 */
return new class extends Migration
{
    private const OLD = ['poodle (standard)', 'standard poodle', 'poodle', 'veliki pudelj', 'pudelj', 'standardni pudelj'];

    private const NEW = ['poodle (standard)', 'standard poodle', 'poodle', 'veliki koder', 'koder', 'veliki pudelj', 'pudelj', 'standardni pudelj'];

    public function up(): void
    {
        $this->swap(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->swap(self::NEW, self::OLD);
    }

    /**
     * @param  list<string>  $from
     * @param  list<string>  $to
     */
    private function swap(array $from, array $to): void
    {
        DB::table('breed_configs')
            ->where('breed_slug', 'poodle-standard')
            ->whereRaw('search_keywords = ?::jsonb', [json_encode($from, JSON_UNESCAPED_UNICODE)])
            ->update([
                'search_keywords' => json_encode($to, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);

        // Raw updates fire no model events (free / paid catalogue cache).
        BreedCatalogService::forget();
    }
};
