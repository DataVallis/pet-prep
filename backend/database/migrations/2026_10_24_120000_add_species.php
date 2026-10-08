<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M5-R06-01 species foundation (M5-R06_PLAN T1–T3, T9).
 *
 *  - pets.species (dog | cat, NOT NULL): every existing pet is a dog (column
 *    default backfills them); a CHECK keeps it equal to the breed's species.
 *  - pets.breed_type gains domestic_cat / maine_coon.
 *  - breed_configs catalogue fields for the server-driven picker
 *    (GET /api/breeds): species, sort_order, search_keywords, label_key.
 *    The two dog rows are backfilled here; the cat rows come from
 *    BreedConfigsSeeder (insert-only, runs on every deploy).
 *
 * Expand-only for dogs: no existing value changes.
 */
return new class extends Migration
{
    private const BREEDS_OLD = "'mutt', 'border_collie'";

    private const BREEDS_NEW = "'mutt', 'border_collie', 'domestic_cat', 'maine_coon'";

    private const CAT_BREEDS = "'domestic_cat', 'maine_coon'";

    /** Dog catalogue backfill — same values as BreedConfigsSeeder. */
    private const DOGS = [
        'mutt' => ['sort_order' => 0, 'label_key' => 'breeds.mutt', 'search_keywords' => ['mešanček', 'mesancek', 'mutt', 'mixed']],
        'border-collie' => ['sort_order' => 10, 'label_key' => 'breeds.border_collie', 'search_keywords' => ['border collie', 'koli']],
    ];

    public function up(): void
    {
        Schema::table('breed_configs', function (Blueprint $table) {
            $table->string('species', 16)->default('dog');
            $table->integer('sort_order')->default(0);
            $table->jsonb('search_keywords')->default('[]');
            $table->string('label_key', 64)->nullable();
        });

        DB::statement("ALTER TABLE breed_configs ADD CONSTRAINT breed_configs_species_check CHECK (species IN ('dog', 'cat'))");
        DB::statement("ALTER TABLE breed_configs ADD CONSTRAINT breed_configs_search_keywords_check CHECK (jsonb_typeof(search_keywords) = 'array')");

        foreach (self::DOGS as $slug => $values) {
            DB::table('breed_configs')->where('breed_slug', $slug)->update([
                'species' => 'dog',
                'sort_order' => $values['sort_order'],
                'label_key' => $values['label_key'],
                'search_keywords' => json_encode($values['search_keywords'], JSON_UNESCAPED_UNICODE),
            ]);
        }

        Schema::table('pets', function (Blueprint $table) {
            // Default 'dog' backfills every existing pet (PostgreSQL ≥ 11: no table rewrite).
            $table->string('species', 16)->default('dog');
        });

        DB::statement("ALTER TABLE pets ADD CONSTRAINT pets_species_check CHECK (species IN ('dog', 'cat'))");
        DB::statement('ALTER TABLE pets DROP CONSTRAINT IF EXISTS pets_breed_type_check');
        DB::statement('ALTER TABLE pets ADD CONSTRAINT pets_breed_type_check CHECK (breed_type IN ('.self::BREEDS_NEW.'))');
        // T2: the breed belongs to the species (code: Pet::saving, generate-pin 422 breed_species_mismatch).
        DB::statement('ALTER TABLE pets ADD CONSTRAINT pets_species_breed_check CHECK (species = CASE WHEN breed_type IN ('.self::CAT_BREEDS.") THEN 'cat' ELSE 'dog' END)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pets DROP CONSTRAINT IF EXISTS pets_species_breed_check');
        DB::statement('ALTER TABLE pets DROP CONSTRAINT IF EXISTS pets_species_check');
        DB::statement('ALTER TABLE pets DROP CONSTRAINT IF EXISTS pets_breed_type_check');
        // Fails while cat pets exist — on purpose (no silent data loss).
        DB::statement('ALTER TABLE pets ADD CONSTRAINT pets_breed_type_check CHECK (breed_type IN ('.self::BREEDS_OLD.'))');

        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn('species');
        });

        DB::statement('ALTER TABLE breed_configs DROP CONSTRAINT IF EXISTS breed_configs_search_keywords_check');
        DB::statement('ALTER TABLE breed_configs DROP CONSTRAINT IF EXISTS breed_configs_species_check');

        Schema::table('breed_configs', function (Blueprint $table) {
            $table->dropColumn(['species', 'sort_order', 'search_keywords', 'label_key']);
        });
    }
};
