<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QA #361 M1 (frozen same-frame clips): `pet_media.stored_recipe` = how the STORED file
 * was made. `recipe` keeps its meaning (how the slot is / was submitted; look rows also use
 * it as the »make it a loop« marker before the submit) and `stored_recipe` is copied from it
 * only when a new file is stored or linked from a look — so a remake that fails keeps its
 * old frozen file AND its old stored_recipe, and media:regenerate-frozen still finds it.
 * Existing files: stored_recipe = recipe (for rows submitted before this migration that is
 * the recipe of the stored file, except a remake in flight — its file is then the old one;
 * storing the new file overwrites it, a failure keeps it listed or not: accepted).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");

        Schema::table('pet_media', function (Blueprint $table) {
            $table->string('stored_recipe')->nullable()->after('recipe');
        });

        DB::statement("ALTER TABLE pet_media ADD CONSTRAINT pet_media_stored_recipe_check CHECK (stored_recipe IS NULL OR stored_recipe IN ('pose', 'loop', 'start_only'))");

        DB::table('pet_media')->whereNotNull('storage_path')->whereNotNull('recipe')
            ->update(['stored_recipe' => DB::raw('recipe')]);
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('ALTER TABLE pet_media DROP CONSTRAINT IF EXISTS pet_media_stored_recipe_check');

        Schema::table('pet_media', function (Blueprint $table) {
            $table->dropColumn('stored_recipe');
        });
    }
};
