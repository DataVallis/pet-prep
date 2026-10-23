<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix 2026-10-23 (frozen same-frame clips): `pet_media.recipe` gets `start_only` —
 * a loop / same-pose phase clip made from its start frame only (no Kling
 * end_image_url, which froze those clips). Constraint only; no row changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('ALTER TABLE pet_media DROP CONSTRAINT IF EXISTS pet_media_recipe_check');
        DB::statement("ALTER TABLE pet_media ADD CONSTRAINT pet_media_recipe_check CHECK (recipe IS NULL OR recipe IN ('pose', 'loop', 'start_only'))");
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::table('pet_media')->where('recipe', 'start_only')->update(['recipe' => 'loop']);
        DB::statement('ALTER TABLE pet_media DROP CONSTRAINT IF EXISTS pet_media_recipe_check');
        DB::statement("ALTER TABLE pet_media ADD CONSTRAINT pet_media_recipe_check CHECK (recipe IS NULL OR recipe IN ('pose', 'loop'))");
    }
};
