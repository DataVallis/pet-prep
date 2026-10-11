<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M5-R10-14: pets.breed_type accepts `british_shorthair` (BreedType::BritishShorthair, a CAT).
 *
 * The first cat breed after the species foundation, so both breed lists change:
 *  - `pets_breed_type_check` (every breed), and
 *  - `pets_species_breed_check` (2026_10_24_120000_add_species): the cat list, otherwise a
 *    British Shorthair pet would have to be species 'dog'.
 * The breed_configs / breed_stage_params rows come from the insert-only seeders
 * (BreedConfigsSeeder, run on every deploy). Cats stay hidden (PETPREP_CATS_ENABLED=false).
 *
 * Expand-only: no existing value changes.
 */
return new class extends Migration
{
    private const BREEDS_OLD = "'mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'domestic_cat', 'maine_coon'";

    private const BREEDS_NEW = "'mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'domestic_cat', 'maine_coon', 'british_shorthair'";

    private const CAT_BREEDS_OLD = "'domestic_cat', 'maine_coon'";

    private const CAT_BREEDS_NEW = "'domestic_cat', 'maine_coon', 'british_shorthair'";

    public function up(): void
    {
        DB::statement('ALTER TABLE pets DROP CONSTRAINT IF EXISTS pets_breed_type_check');
        DB::statement('ALTER TABLE pets ADD CONSTRAINT pets_breed_type_check CHECK (breed_type IN ('.self::BREEDS_NEW.'))');
        DB::statement('ALTER TABLE pets DROP CONSTRAINT IF EXISTS pets_species_breed_check');
        DB::statement('ALTER TABLE pets ADD CONSTRAINT pets_species_breed_check CHECK (species = CASE WHEN breed_type IN ('.self::CAT_BREEDS_NEW.") THEN 'cat' ELSE 'dog' END)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pets DROP CONSTRAINT IF EXISTS pets_breed_type_check');
        // Fails while British Shorthair pets exist — on purpose (no silent data loss).
        DB::statement('ALTER TABLE pets ADD CONSTRAINT pets_breed_type_check CHECK (breed_type IN ('.self::BREEDS_OLD.'))');
        DB::statement('ALTER TABLE pets DROP CONSTRAINT IF EXISTS pets_species_breed_check');
        DB::statement('ALTER TABLE pets ADD CONSTRAINT pets_species_breed_check CHECK (species = CASE WHEN breed_type IN ('.self::CAT_BREEDS_OLD.") THEN 'cat' ELSE 'dog' END)");
    }
};
