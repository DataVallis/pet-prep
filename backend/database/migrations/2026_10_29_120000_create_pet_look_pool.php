<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M4-10 (David 2026-10-09): free pets take their appearance from a shared
 * pool of looks per free breed instead of a unique DNA.
 *
 *  - `pet_looks`: one row per look of a breed's pool (pool_index 1..N, N =
 *    config media.look_pool.size, 20). The unique (breed_type, pool_index)
 *    index is the fill guard: two pairings that race for the next index
 *    cannot both create it. `dna` = the DNA v2 payload every pet of the
 *    look copies (traits, seed, fingerprint, prompt — no origin, no name).
 *  - `pets.pet_look_id`: the look of a pool pet (null = unique DNA as before;
 *    every pet created before this migration keeps its own media).
 *  - `pet_media` gets LOOK rows (pet_id null, pet_look_id set): the media of
 *    a look, one row per (kind, state, life_stage). They run through the same
 *    pipeline (claims, ledger, webhook, download). A pool pet's own slots
 *    point at the look row they show (`look_media_id`) and carry a copy of
 *    its `storage_path` — the file exists once on disk (`looks/{id}/…`).
 *    Look files are never deleted by a pet / family deletion (they live
 *    outside the pet's directory).
 *  - `pet_media_history.storage_path` is unique per pet now (was global):
 *    two pets of one look archive the same look file at a stage change.
 *
 * Additive; existing rows untouched. down() removes the look rows first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pet_looks', function (Blueprint $table) {
            $table->id();
            $table->string('breed_type');                 // BreedType value (a free breed)
            $table->unsignedSmallInteger('pool_index');    // 1..pool size, per breed
            $table->string('trait_fingerprint', 64);       // PetDnaService::fingerprint()
            $table->jsonb('dna');                          // DNA v2 payload shared by the look's pets
            $table->timestamps();

            $table->unique(['breed_type', 'pool_index']);
            $table->unique(['breed_type', 'trait_fingerprint']);
        });

        DB::statement('ALTER TABLE pet_looks ADD CONSTRAINT pet_looks_pool_index_check CHECK (pool_index >= 1)');

        Schema::table('pets', function (Blueprint $table) {
            $table->foreignId('pet_look_id')->nullable()->after('pet_dna')->constrained('pet_looks')->restrictOnDelete();
        });

        Schema::table('pet_media', function (Blueprint $table) {
            $table->foreignId('pet_look_id')->nullable()->after('pet_id')->constrained('pet_looks')->restrictOnDelete();
            $table->foreignId('look_media_id')->nullable()->after('pet_look_id')->constrained('pet_media')->nullOnDelete();
            $table->index('look_media_id');
        });

        DB::statement('ALTER TABLE pet_media ALTER COLUMN pet_id DROP NOT NULL');

        // Pet slots: one per (pet, kind, state) as before. Look rows: one per (look, kind, state, stage).
        DB::statement('DROP INDEX IF EXISTS pet_media_slot_unique');
        DB::statement('CREATE UNIQUE INDEX pet_media_slot_unique ON pet_media (pet_id, kind, state) NULLS NOT DISTINCT WHERE pet_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX pet_media_look_slot_unique ON pet_media (pet_look_id, kind, state, life_stage) NULLS NOT DISTINCT WHERE pet_look_id IS NOT NULL');

        DB::statement('ALTER TABLE pet_media ADD CONSTRAINT pet_media_owner_check CHECK ((pet_id IS NULL) <> (pet_look_id IS NULL))');
        DB::statement('ALTER TABLE pet_media ADD CONSTRAINT pet_media_look_stage_check CHECK (pet_look_id IS NULL OR life_stage IS NOT NULL)');
        DB::statement('ALTER TABLE pet_media ADD CONSTRAINT pet_media_look_link_check CHECK (look_media_id IS NULL OR pet_id IS NOT NULL)');

        Schema::table('pet_media_history', function (Blueprint $table) {
            $table->dropUnique(['storage_path']);
            $table->unique(['pet_id', 'storage_path']);
            $table->index('storage_path');
        });
    }

    public function down(): void
    {
        // History rows of pool pets point at look files; the global unique index cannot come back with duplicates.
        DB::statement('DELETE FROM pet_media_history h USING pet_media_history o WHERE h.storage_path = o.storage_path AND h.id > o.id');
        Schema::table('pet_media_history', function (Blueprint $table) {
            $table->dropIndex(['storage_path']);
            $table->dropUnique(['pet_id', 'storage_path']);
            $table->unique('storage_path');
        });

        DB::table('pet_media')->whereNotNull('pet_look_id')->delete();

        DB::statement('ALTER TABLE pet_media DROP CONSTRAINT IF EXISTS pet_media_look_link_check');
        DB::statement('ALTER TABLE pet_media DROP CONSTRAINT IF EXISTS pet_media_look_stage_check');
        DB::statement('ALTER TABLE pet_media DROP CONSTRAINT IF EXISTS pet_media_owner_check');
        DB::statement('DROP INDEX IF EXISTS pet_media_look_slot_unique');
        DB::statement('DROP INDEX IF EXISTS pet_media_slot_unique');
        DB::statement('CREATE UNIQUE INDEX pet_media_slot_unique ON pet_media (pet_id, kind, state) NULLS NOT DISTINCT');
        DB::statement('ALTER TABLE pet_media ALTER COLUMN pet_id SET NOT NULL');

        Schema::table('pet_media', function (Blueprint $table) {
            $table->dropConstrainedForeignId('look_media_id');
            $table->dropConstrainedForeignId('pet_look_id');
        });

        Schema::table('pets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pet_look_id');
        });

        Schema::dropIfExists('pet_looks');
    }
};
