<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * David 2026-11-20 (»1+A se strinjam«): the bowls get a second clip — the animal DRINKING from the
 * water bowl (kind `drink_clip`, ItemMedia::KIND_DRINK_CLIP), made from the same key image as the
 * eating clip. Only the CHECK on (scope, kind) changes; the partial unique indexes already key on
 * `kind`. No backfill here: `media:item-media` (heal / ensure, every 10 min) queues the drinking clip
 * for every pet that already owns bowls.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->replace("('image', 'clip', 'drink_clip')");
    }

    public function down(): void
    {
        DB::table('item_media')->where('kind', 'drink_clip')->delete();
        $this->replace("('image', 'clip')");
    }

    private function replace(string $kinds): void
    {
        DB::statement('ALTER TABLE item_media DROP CONSTRAINT IF EXISTS item_media_scope_check');
        DB::statement("ALTER TABLE item_media ADD CONSTRAINT item_media_scope_check CHECK (
            (scope = 'catalogue' AND kind = 'picture' AND pet_id IS NULL AND pet_look_id IS NULL)
            OR (scope = 'look' AND kind IN {$kinds} AND pet_id IS NULL AND pet_look_id IS NOT NULL AND life_stage IS NOT NULL)
            OR (scope = 'pet' AND kind IN {$kinds} AND pet_id IS NOT NULL AND pet_look_id IS NULL))");
    }
};
