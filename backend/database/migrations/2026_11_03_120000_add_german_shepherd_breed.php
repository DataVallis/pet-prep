<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M5-R10-04: pets.breed_type accepts `german_shepherd`
 * (BreedType::GermanShepherd, a dog).
 *
 * Only `pets_breed_type_check` lists breeds. `pets_species_breed_check`
 * (2026_10_24_120000_add_species) maps every breed outside the cat list to
 * 'dog', so a new DOG breed needs no change there (a new cat breed would).
 * The breed_configs / breed_stage_params rows come from the insert-only
 * seeders (BreedConfigsSeeder, run on every deploy).
 *
 * Expand-only: no existing value changes.
 */
return new class extends Migration
{
    private const BREEDS_OLD = "'mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'domestic_cat', 'maine_coon'";

    private const BREEDS_NEW = "'mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'domestic_cat', 'maine_coon'";

    public function up(): void
    {
        DB::statement('ALTER TABLE pets DROP CONSTRAINT IF EXISTS pets_breed_type_check');
        DB::statement('ALTER TABLE pets ADD CONSTRAINT pets_breed_type_check CHECK (breed_type IN ('.self::BREEDS_NEW.'))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pets DROP CONSTRAINT IF EXISTS pets_breed_type_check');
        // Fails while German Shepherd pets exist — on purpose (no silent data loss).
        DB::statement('ALTER TABLE pets ADD CONSTRAINT pets_breed_type_check CHECK (breed_type IN ('.self::BREEDS_OLD.'))');
    }
};
