<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix 2026-10-23 (frozen same-frame clips):
 * - `pet_media.recipe` also takes `start_only` — a loop / same-pose phase clip made from
 *   its start frame only (no Kling end_image_url, which froze those clips);
 * - `pet_media.stored_recipe` — how the STORED file was made. `recipe` keeps its meaning
 *   (how the slot is / was submitted; look rows also use it as the »make it a loop« marker
 *   before the submit); `stored_recipe` is copied from it only when a new file is stored or
 *   linked (QA #361 M1: a remake that fails keeps its old frozen file AND its old
 *   stored_recipe, so media:regenerate-frozen still finds it). Existing files: = recipe.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");

        Schema::table('pet_media', function (Blueprint $table) {
            $table->string('stored_recipe')->nullable()->after('recipe');
        });

        DB::statement('ALTER TABLE pet_media DROP CONSTRAINT IF EXISTS pet_media_recipe_check');
        DB::statement("ALTER TABLE pet_media ADD CONSTRAINT pet_media_recipe_check CHECK (recipe IS NULL OR recipe IN ('pose', 'loop', 'start_only'))");
        DB::statement("ALTER TABLE pet_media ADD CONSTRAINT pet_media_stored_recipe_check CHECK (stored_recipe IS NULL OR stored_recipe IN ('pose', 'loop', 'start_only'))");

        DB::table('pet_media')->whereNotNull('storage_path')->whereNotNull('recipe')
            ->update(['stored_recipe' => DB::raw('recipe')]);
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        // Lossy on purpose: `start_only` becomes `loop`, so after a rollback media:regenerate-frozen
        // (if kept) would list those clips as frozen again — a paid remake; don't run it after a rollback.
        DB::table('pet_media')->where('recipe', 'start_only')->update(['recipe' => 'loop']);
        DB::statement('ALTER TABLE pet_media DROP CONSTRAINT IF EXISTS pet_media_stored_recipe_check');
        DB::statement('ALTER TABLE pet_media DROP CONSTRAINT IF EXISTS pet_media_recipe_check');
        DB::statement("ALTER TABLE pet_media ADD CONSTRAINT pet_media_recipe_check CHECK (recipe IS NULL OR recipe IN ('pose', 'loop'))");

        Schema::table('pet_media', function (Blueprint $table) {
            $table->dropColumn('stored_recipe');
        });
    }
};
