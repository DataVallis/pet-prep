<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M6-W01 / M6-W02 »Želje« (SHOP_SPEC, docs/engineering/WISHES_API.md, David 2026-10-26 / 27).
 *
 *  - shop_items: the server-side catalogue, seeded HERE (not editable in the
 *    app or admin). One row per key; the variant depends on the pet's species
 *    (two toys per species — David 2026-10-27). `covers` = the keys a purchase
 *    of this row gives the pet (the welcome bundle = bed + bowls + toy_1).
 *    `product_id` = the default store product (one per key, species-neutral);
 *    `services.revenuecat.wish_products` maps the store ids actually used.
 *  - pet_wishes: a child asks for an item for a pet (ids only — no new personal
 *    data). open → bought | declined | cancelled. At most one OPEN wish per
 *    pet + key (partial unique index). Cascades with the pet and the child.
 *  - wish_credits: one per store purchase of a wish product (like
 *    challenge_credits): family-owned, assigned to one pet by the parent's
 *    redeem call; survives the pet's deletion with pet_id null (used — no
 *    refund, S11). `redeem_key` = the Idempotency-Key of the redeem call.
 *  - pet_items: what a pet owns. One row per durable item (at most one
 *    unrevoked per pet + key) and one row per treat pack (quantity_left).
 *    A store refund revokes the credit's rows (revoked_at, treats → 0 left).
 *  - pet_treat_events: one row per treat given (max 2 per family-local day per pet).
 *  - push_notifications type `wish_created` (parents, held over quiet hours).
 *
 * Additive; down() drops the tables and the push type.
 */
return new class extends Migration
{
    private const PUSH_TYPE = 'wish_created';

    private const ENVIRONMENTS = "'SANDBOX', 'PRODUCTION'";

    public function up(): void
    {
        Schema::create('shop_items', function (Blueprint $table) {
            $table->string('key', 32)->primary();
            $table->string('kind', 16);
            $table->unsignedSmallInteger('pack_size')->nullable();
            $table->string('dog_variant', 32)->nullable();
            $table->string('cat_variant', 32)->nullable();
            $table->string('product_id', 255)->unique();
            $table->jsonb('covers');
            $table->unsignedSmallInteger('sort');
            $table->timestamps();
        });
        DB::statement("ALTER TABLE shop_items ADD CONSTRAINT shop_items_kind_check CHECK (kind IN ('durable', 'consumable', 'bundle'))");

        $now = now();
        $rows = [
            ['bed', 'durable', null, 'dog_bed', 'cat_basket', 'petprep_wish_bed', ['bed'], 1],
            ['bowls', 'durable', null, 'dog_bowls', 'cat_bowls', 'petprep_wish_bowls', ['bowls'], 2],
            ['toy_1', 'durable', null, 'dog_rope', 'cat_mouse', 'petprep_wish_toy_1', ['toy_1'], 3],
            ['toy_2', 'durable', null, 'dog_squeaky', 'cat_feather', 'petprep_wish_toy_2', ['toy_2'], 4],
            ['treats', 'consumable', 10, 'dog_treats', 'cat_treats', 'petprep_wish_treats_10', ['treats'], 5],
            ['welcome_bundle', 'bundle', null, null, null, 'petprep_wish_bundle_welcome', ['bed', 'bowls', 'toy_1'], 6],
        ];
        DB::table('shop_items')->insert(array_map(fn (array $r): array => [
            'key' => $r[0], 'kind' => $r[1], 'pack_size' => $r[2], 'dog_variant' => $r[3], 'cat_variant' => $r[4],
            'product_id' => $r[5], 'covers' => json_encode($r[6]), 'sort' => $r[7], 'created_at' => $now, 'updated_at' => $now,
        ], $rows));

        Schema::create('pet_wishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();
            $table->foreignId('child_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('item_key', 32);
            $table->foreign('item_key')->references('key')->on('shop_items');
            $table->string('status', 16)->default('open');
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['child_user_id', 'status']);
            $table->index(['pet_id', 'status']);
        });
        DB::statement("ALTER TABLE pet_wishes ADD CONSTRAINT pet_wishes_status_check CHECK (status IN ('open', 'bought', 'declined', 'cancelled'))");
        DB::statement("CREATE UNIQUE INDEX pet_wishes_one_open ON pet_wishes (pet_id, item_key) WHERE status = 'open'");

        Schema::create('wish_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained('families')->cascadeOnDelete();
            $table->foreignId('purchase_event_id')->unique()->constrained('purchase_events');
            $table->string('product_id', 255);
            $table->string('item_key', 32);
            $table->foreign('item_key')->references('key')->on('shop_items');
            $table->string('store', 32)->nullable();
            $table->string('environment', 16)->nullable();
            $table->string('transaction_id', 255)->nullable();
            $table->timestamp('purchased_at');
            $table->foreignId('pet_id')->nullable()->constrained('pets')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->string('assigned_via', 16)->nullable();
            $table->string('redeem_key', 255)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 32)->nullable();
            $table->string('revoke_event_id', 128)->nullable();
            $table->timestamp('transferred_at')->nullable();
            $table->unsignedBigInteger('transferred_from_family_id')->nullable();
            $table->timestamps();

            $table->index(['family_id', 'assigned_at']);
            $table->index('transaction_id');
            $table->unique(['family_id', 'redeem_key']);
        });
        // QA #417 m3: one unrevoked credit per store transaction (the webhook skips a resend first).
        DB::statement('CREATE UNIQUE INDEX wish_credits_one_per_transaction ON wish_credits (transaction_id) WHERE transaction_id IS NOT NULL AND revoked_at IS NULL');
        DB::statement('ALTER TABLE wish_credits ADD CONSTRAINT wish_credits_environment_check CHECK (environment IS NULL OR environment IN ('.self::ENVIRONMENTS.'))');
        // pet_id may become null later (pet deleted → nullOnDelete): the credit stays used.
        DB::statement("ALTER TABLE wish_credits ADD CONSTRAINT wish_credits_assigned_check CHECK ((assigned_at IS NULL AND assigned_via IS NULL AND pet_id IS NULL) OR (assigned_at IS NOT NULL AND assigned_via IN ('parent')))");
        DB::statement("ALTER TABLE wish_credits ADD CONSTRAINT wish_credits_revoke_check CHECK ((revoked_at IS NULL AND revoke_reason IS NULL) OR (revoked_at IS NOT NULL AND revoke_reason IN ('refund')))");

        Schema::create('pet_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();
            $table->string('item_key', 32);
            $table->foreign('item_key')->references('key')->on('shop_items');
            $table->string('kind', 16);
            $table->string('variant', 32);
            $table->foreignId('wish_credit_id')->nullable()->constrained('wish_credits')->nullOnDelete();
            $table->unsignedSmallInteger('quantity_total')->default(1);
            $table->unsignedSmallInteger('quantity_left')->default(1);
            $table->timestamp('acquired_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['pet_id', 'item_key']);
        });
        DB::statement("ALTER TABLE pet_items ADD CONSTRAINT pet_items_kind_check CHECK (kind IN ('durable', 'consumable'))");
        DB::statement('ALTER TABLE pet_items ADD CONSTRAINT pet_items_quantity_check CHECK (quantity_left <= quantity_total)');
        DB::statement("CREATE UNIQUE INDEX pet_items_one_durable ON pet_items (pet_id, item_key) WHERE revoked_at IS NULL AND kind = 'durable'");

        Schema::create('pet_treat_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();
            $table->foreignId('child_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('pet_item_id')->constrained('pet_items')->cascadeOnDelete();
            $table->timestamp('given_at');
            $table->date('local_date');
            $table->timestamps();

            $table->index(['pet_id', 'local_date']);
        });

        $values = $this->allowed();
        if (! in_array(self::PUSH_TYPE, $values, true)) {
            $this->replace([...$values, self::PUSH_TYPE]);
        }
    }

    public function down(): void
    {
        DB::table('push_notifications')->where('type', self::PUSH_TYPE)->delete();
        $this->replace(array_values(array_diff($this->allowed(), [self::PUSH_TYPE])));

        Schema::dropIfExists('pet_treat_events');
        Schema::dropIfExists('pet_items');
        Schema::dropIfExists('wish_credits');
        Schema::dropIfExists('pet_wishes');
        Schema::dropIfExists('shop_items');
    }

    /** @return list<string> */
    private function allowed(): array
    {
        $row = DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'push_notifications_type_check'");
        if ($row === null) {
            throw new RuntimeException('Constraint push_notifications_type_check not found.');
        }
        preg_match_all("/'((?:[^']|'')*)'/", (string) $row->def, $matches);

        return array_values(array_unique(array_map(fn (string $v) => str_replace("''", "'", $v), $matches[1])));
    }

    /** @param  list<string>  $values */
    private function replace(array $values): void
    {
        $list = implode(', ', array_map(fn (string $v) => "'".str_replace("'", "''", $v)."'", $values));
        DB::statement('ALTER TABLE push_notifications DROP CONSTRAINT IF EXISTS push_notifications_type_check');
        // QA #417 m8: NOT VALID first (no long lock while the existing rows are checked), then validate.
        DB::statement("ALTER TABLE push_notifications ADD CONSTRAINT push_notifications_type_check CHECK (type IN ({$list})) NOT VALID");
        DB::statement('ALTER TABLE push_notifications VALIDATE CONSTRAINT push_notifications_type_check');
    }
};
