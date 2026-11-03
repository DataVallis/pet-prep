<?php

use App\Enums\BreedType;
use App\Enums\Species;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M6-W03 »AI vsebine stvari« (SHOP_SPEC §5, David 2026-11-03: build it fully, with designs).
 *
 *  - `pet_wishes.design` / `pet_items.design`: the design the child wished for / the pet owns
 *    (config/shop_designs.php keys; null for treats). Durable items owned before this migration
 *    get the FIRST design of their item for the pet's species (David 2026-11-03 — e.g. the bed
 *    bought on production on 2026-10-30/31). Open wishes of durable items likewise.
 *  - `item_media`: the AI content of items (ItemMediaService). Own table — never pet_media rows,
 *    so the pet media pipeline (sweep, retry, quality check, compression, frugal clips) never
 *    sees them. Scopes:
 *      catalogue — the product picture of one design (kind picture; no pet, no look);
 *      look      — content of a shared look (kind image | clip) per (look, design, life stage):
 *                  made once, every pet of the look at that stage shows it (0 $ later);
 *      pet       — content of a pet with its own media (unique DNA) per (pet, design, kind),
 *                  regenerated in place when the pet's reference image changes (new stage).
 *    Identity of the source = its storage_path (`source_path` of the stored file, `target_path` of the
 *    current request) — a new reference image (stage growth, admin regenerate) makes the content stale.
 *  - `item_media_notices`: one row per (pet, item, design, stage) whose content became ready
 *    (or fell back) — the PetUpdated `item_media_ready` + `item_ready` push are sent once.
 *  - `ai_spend_ledger.item_media_id` + purpose `item_media` (production budget).
 *  - push type `item_ready` (caretaker children).
 */
return new class extends Migration
{
    private const PUSH_TYPE = 'item_ready';

    public function up(): void
    {
        Schema::table('pet_wishes', function (Blueprint $table) {
            $table->string('design', 64)->nullable();
        });
        Schema::table('pet_items', function (Blueprint $table) {
            $table->string('design', 64)->nullable();
        });

        Schema::create('item_media', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 16);                                   // catalogue | look | pet
            $table->foreignId('pet_id')->nullable()->constrained('pets')->cascadeOnDelete();
            // No FK on purpose: looks are never deleted by the app (only the game-data reset, which
            // deletes item_media first), and the M4-10 migration's down() must still drop pet_looks.
            $table->unsignedBigInteger('pet_look_id')->nullable()->index();
            $table->string('item_key', 32);                                 // bed | bowls | toy_1 | toy_2 | treats
            $table->string('design', 64);                                   // design key, or dog_treats / cat_treats
            $table->string('kind', 16);                                     // picture | image | clip
            $table->string('life_stage', 16)->nullable();                   // look / pet rows: the stage it shows
            // The stored file was made from this picture (image: the reference image's storage_path; clip:
            // the item image's storage_path) — content is CURRENT only while that is what the pet shows.
            $table->string('source_path')->nullable();
            $table->string('target_path')->nullable();                      // what the running / failed chain is made from
            $table->string('status', 16)->default('pending');               // pending | running | ready | failed
            $table->unsignedInteger('generation')->default(1);
            $table->string('profile')->nullable();
            $table->string('request_id')->nullable()->unique();
            $table->text('status_url')->nullable();
            $table->text('response_url')->nullable();
            $table->text('source_url')->nullable();
            $table->string('storage_path')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('mime')->nullable();
            $table->float('duration_seconds')->nullable();
            $table->decimal('cost_usd', 10, 4)->default(0);
            $table->string('error_reason')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            // Self-healing (like ActivityClipRetryService, PR #547): retries of the current failure
            // chain, the prompt variant of the next request (bumped only after `no_media_generated`),
            // and when the chain was given up (the app then shows the item without its content).
            $table->unsignedSmallInteger('auto_retries')->default(0);
            $table->unsignedSmallInteger('prompt_variant')->default(0);
            // QA #655 m5: our own download / store of a result failed (its fal URL may still be valid) —
            // the sweep retries the DOWNLOAD (free) before the row fails and is regenerated (paid).
            $table->unsignedSmallInteger('download_attempts')->default(0);
            $table->timestamp('exhausted_at')->nullable();
            $table->timestamp('alerted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at']);
        });

        DB::statement("ALTER TABLE item_media ADD CONSTRAINT item_media_scope_check CHECK (
            (scope = 'catalogue' AND kind = 'picture' AND pet_id IS NULL AND pet_look_id IS NULL)
            OR (scope = 'look' AND kind IN ('image', 'clip') AND pet_id IS NULL AND pet_look_id IS NOT NULL AND life_stage IS NOT NULL)
            OR (scope = 'pet' AND kind IN ('image', 'clip') AND pet_id IS NOT NULL AND pet_look_id IS NULL))");
        DB::statement("ALTER TABLE item_media ADD CONSTRAINT item_media_status_check CHECK (status IN ('pending', 'running', 'ready', 'failed'))");
        DB::statement("ALTER TABLE item_media ADD CONSTRAINT item_media_item_key_check CHECK (item_key IN ('bed', 'bowls', 'toy_1', 'toy_2', 'treats'))");
        DB::statement('ALTER TABLE item_media ADD CONSTRAINT item_media_error_reason_check CHECK (error_reason IS NULL OR error_reason IN ('
            ."'budget_daily', 'budget_monthly', 'budget_run', 'budget_lab', 'timed_out', 'fal_balance', 'http_error', 'invalid_response', 'disabled', 'generation_failed', 'quality_failed'))");
        DB::statement("CREATE UNIQUE INDEX item_media_catalogue_unique ON item_media (design) WHERE scope = 'catalogue'");
        DB::statement("CREATE UNIQUE INDEX item_media_look_unique ON item_media (pet_look_id, design, kind, life_stage) WHERE scope = 'look'");
        DB::statement("CREATE UNIQUE INDEX item_media_pet_unique ON item_media (pet_id, design, kind) WHERE scope = 'pet'");

        Schema::create('item_media_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();
            $table->string('item_key', 32);
            $table->string('design', 64);
            $table->string('life_stage', 16)->default('');                  // '' = a pet without stages (legacy)
            $table->string('outcome', 16);                                  // content | fallback | no_media
            $table->timestamp('created_at')->nullable();

            $table->unique(['pet_id', 'item_key', 'design', 'life_stage']);
        });

        Schema::table('ai_spend_ledger', function (Blueprint $table) {
            $table->foreignId('item_media_id')->nullable()->constrained('item_media')->nullOnDelete();
        });
        DB::statement('ALTER TABLE ai_spend_ledger DROP CONSTRAINT IF EXISTS ai_spend_ledger_purpose_check');
        DB::statement("ALTER TABLE ai_spend_ledger ADD CONSTRAINT ai_spend_ledger_purpose_check CHECK (purpose IN ('lab', 'reference_image', 'state_video', 'quality_check', 'item_media')) NOT VALID");
        DB::statement('ALTER TABLE ai_spend_ledger VALIDATE CONSTRAINT ai_spend_ledger_purpose_check');

        $types = $this->allowedPushTypes();
        if (! in_array(self::PUSH_TYPE, $types, true)) {
            $this->replacePushTypes([...$types, self::PUSH_TYPE]);
        }

        $this->backfillDesigns();
    }

    public function down(): void
    {
        DB::table('push_notifications')->where('type', self::PUSH_TYPE)->delete();
        $this->replacePushTypes(array_values(array_diff($this->allowedPushTypes(), [self::PUSH_TYPE])));

        DB::table('ai_spend_ledger')->where('purpose', 'item_media')->update(['purpose' => 'reference_image']);
        DB::statement('ALTER TABLE ai_spend_ledger DROP CONSTRAINT IF EXISTS ai_spend_ledger_purpose_check');
        DB::statement("ALTER TABLE ai_spend_ledger ADD CONSTRAINT ai_spend_ledger_purpose_check CHECK (purpose IN ('lab', 'reference_image', 'state_video', 'quality_check'))");
        Schema::table('ai_spend_ledger', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_media_id');
        });

        Schema::dropIfExists('item_media_notices');
        Schema::dropIfExists('item_media');

        Schema::table('pet_items', function (Blueprint $table) {
            $table->dropColumn('design');
        });
        Schema::table('pet_wishes', function (Blueprint $table) {
            $table->dropColumn('design');
        });
    }

    /**
     * David 2026-11-03: items (and open wishes) from before designs existed get the FIRST
     * design of their item for the pet's species. Reads the design list from the config at
     * migration time (config/shop_designs.php, stable keys).
     */
    private function backfillDesigns(): void
    {
        $first = function (string $itemKey, Species $species): ?string {
            $list = config("shop_designs.{$species->value}.{$itemKey}", []);

            return is_array($list) && isset($list[0]['key']) ? (string) $list[0]['key'] : null;
        };
        $speciesOf = function (?string $breed): Species {
            $type = $breed !== null ? BreedType::tryFrom($breed) : null;

            return $type?->species() ?? Species::Dog;
        };

        foreach (['pet_items' => "kind = 'durable'", 'pet_wishes' => "item_key IN ('bed', 'bowls', 'toy_1', 'toy_2') AND status = 'open'"] as $table => $where) {
            DB::table($table)->join('pets', 'pets.id', '=', "{$table}.pet_id")
                ->whereNull("{$table}.design")->whereRaw("{$table}.{$where}")
                ->select("{$table}.id", "{$table}.item_key", 'pets.breed_type')
                ->orderBy("{$table}.id")
                ->chunk(500, function ($rows) use ($table, $first, $speciesOf): void {
                    foreach ($rows as $row) {
                        $design = $first((string) $row->item_key, $speciesOf($row->breed_type));
                        if ($design !== null) {
                            DB::table($table)->where('id', $row->id)->update(['design' => $design]);
                        }
                    }
                });
        }
    }

    /** @return list<string> */
    private function allowedPushTypes(): array
    {
        $row = DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'push_notifications_type_check'");
        if ($row === null) {
            throw new RuntimeException('Constraint push_notifications_type_check not found.');
        }
        preg_match_all("/'((?:[^']|'')*)'/", (string) $row->def, $matches);

        return array_values(array_unique(array_map(fn (string $v) => str_replace("''", "'", $v), $matches[1])));
    }

    /** @param  list<string>  $values */
    private function replacePushTypes(array $values): void
    {
        $list = implode(', ', array_map(fn (string $v) => "'".str_replace("'", "''", $v)."'", $values));
        DB::statement('ALTER TABLE push_notifications DROP CONSTRAINT IF EXISTS push_notifications_type_check');
        DB::statement("ALTER TABLE push_notifications ADD CONSTRAINT push_notifications_type_check CHECK (type IN ({$list})) NOT VALID");
        DB::statement('ALTER TABLE push_notifications VALIDATE CONSTRAINT push_notifications_type_check');
    }
};
