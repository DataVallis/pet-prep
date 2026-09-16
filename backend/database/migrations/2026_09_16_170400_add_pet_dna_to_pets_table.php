<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds Pet DNA (visual identity) and fal.ai video state fields
     * to the pets table for 100% visual consistency across all
     * AI-generated images and videos.
     */
    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            // pet_dna: JSONB storing the pet's unique visual identity payload.
            // Contains: seed, visual_traits, prompt_anchor, reference_image_url
            $table->jsonb('pet_dna')->nullable()->after('breed_type');

            // current_video_url: URL of the currently active Kling 3.0 video
            // loop displayed in the child UI background.
            $table->string('current_video_url')->nullable()->after('pet_dna');
        });

        // GIN index for efficient JSONB queries on pet_dna
        DB::statement('CREATE INDEX pets_pet_dna_gin_index ON pets USING GIN (pet_dna)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pets_pet_dna_gin_index');

        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn(['pet_dna', 'current_video_url']);
        });
    }
};
