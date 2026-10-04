<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Family model (M2-01, ADR-012, David 2026-10-04).
 *
 * New: families (timezone), family_user (one family per user, role
 * parent|child), family_invites (second-parent invite codes), pet_caretakers
 * (child ↔ pet, at most one ACTIVE pet per child — partial unique index on a
 * trigger-maintained copy of pets.is_active), pet_daily_steps (each child's
 * own step count per family-local day), pets.family_id, quiet_hours.family_id,
 * users.pairing_pet_id (child PIN that joins an existing pet),
 * activities_log.actor_user_id (the child who acted; null = system).
 *
 * Backfill (no existing row is modified except the new columns):
 *  - every parent → their own family (timezone = users.timezone) + member;
 *  - every child with parent_id → member of that parent's family;
 *  - a child with pets but no parent → a family of its own;
 *  - pets.family_id = owner child's family; one caretaker row from
 *    pets.user_id (requires_contract = pet still unborn: born pets are
 *    grandfathered, M1-07b);
 *  - quiet_hours.family_id = the parent's family;
 *  - activities_log.actor_user_id = pets.user_id for child actions
 *    (fed/watered/walked/cleaned/signed_contract); escalation rows stay null.
 *
 * users.parent_id, users.timezone and pets.user_id stay (deprecated, kept in
 * sync by the app) so current mobile builds keep working.
 *
 * Fails without writing anything if a child already has two active pets
 * (the new invariant) — fix the data first, see ADR-012 "Migration".
 *
 * Deletes never cascade from a family into child data: pets.family_id,
 * family_user.family_id and quiet_hours.family_id are RESTRICT (a family
 * with members, pets or settings cannot be deleted by accident); only open
 * invite codes go with their family.
 *
 * Legacy pets owned by a PARENT account (admin artefacts — the child API
 * never worked for them) go into that parent's family WITHOUT a caretaker
 * row ("only children are caretakers" holds for all data); their ids are
 * printed by the migration so they can be fixed by hand.
 */
return new class extends Migration
{
    private const CHILD_ACTIONS = ['fed_pet', 'watered_pet', 'walked_pet', 'cleaned_poop', 'signed_contract'];

    public function up(): void
    {
        $duplicates = DB::table('pets')
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->havingRaw('count(*) > 1')
            ->pluck('user_id');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Family model migration: children with more than one active pet (user ids: '
                .$duplicates->implode(', ').'). Deactivate the extra pets first (ADR-012).');
        }

        Schema::create('families', function (Blueprint $table) {
            $table->id();
            $table->string('timezone', 64)->default('Europe/Ljubljana');
            $table->timestamps();
        });

        Schema::create('family_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained('families')->restrictOnDelete();
            // One family per user (MVP).
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('role', 16);
            $table->timestamps();

            $table->index(['family_id', 'role']);
        });
        DB::statement("ALTER TABLE family_user ADD CONSTRAINT family_user_role_check CHECK (role IN ('parent', 'child'))");

        Schema::create('family_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained('families')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('code', 16)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('used_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('pets', function (Blueprint $table) {
            $table->foreignId('family_id')->nullable()->after('user_id')->constrained('families')->restrictOnDelete();
        });

        Schema::table('quiet_hours', function (Blueprint $table) {
            $table->foreignId('family_id')->nullable()->unique()->after('parent_id')->constrained('families')->restrictOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            // Child PIN that joins an existing pet (null = the PIN creates a new pet).
            $table->foreignId('pairing_pet_id')->nullable()->after('pin_expires_at')->constrained('pets')->nullOnDelete();
        });

        Schema::table('activities_log', function (Blueprint $table) {
            $table->foreignId('actor_user_id')->nullable()->after('pet_id')->constrained('users')->nullOnDelete();
            $table->index(['actor_user_id', 'created_at']);
        });

        Schema::create('pet_caretakers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Copy of pets.is_active, maintained by triggers (below), so the
            // database can enforce "one active pet per child".
            $table->boolean('pet_is_active')->default(true);
            // A caretaker who joined an existing pet must sign their own
            // contract before acting; false = grandfathered (pet born
            // before contracts existed, M1-07b).
            $table->boolean('requires_contract')->default(true);
            $table->timestamps();

            $table->unique(['pet_id', 'user_id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION pet_caretakers_copy_pet_is_active() RETURNS trigger AS $$
            BEGIN
                -- FOR SHARE: a concurrent is_active change waits for this insert.
                SELECT is_active INTO NEW.pet_is_active FROM pets WHERE id = NEW.pet_id FOR SHARE;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER pet_caretakers_copy_pet_is_active
                BEFORE INSERT OR UPDATE ON pet_caretakers
                FOR EACH ROW EXECUTE FUNCTION pet_caretakers_copy_pet_is_active();

            CREATE OR REPLACE FUNCTION pets_sync_caretaker_is_active() RETURNS trigger AS $$
            BEGIN
                UPDATE pet_caretakers SET pet_is_active = NEW.is_active WHERE pet_id = NEW.id;
                RETURN NULL;
            END
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER pets_sync_caretaker_is_active
                AFTER UPDATE OF is_active ON pets
                FOR EACH ROW WHEN (OLD.is_active IS DISTINCT FROM NEW.is_active)
                EXECUTE FUNCTION pets_sync_caretaker_is_active();

            CREATE UNIQUE INDEX pet_caretakers_one_active_pet_per_child
                ON pet_caretakers (user_id) WHERE pet_is_active;
        SQL);

        Schema::create('pet_daily_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('local_date');
            $table->unsignedInteger('steps')->default(0);
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();

            $table->unique(['pet_id', 'user_id', 'local_date']);
            $table->index(['user_id', 'local_date']);
        });

        $this->backfill();

        DB::statement('ALTER TABLE pets ALTER COLUMN family_id SET NOT NULL');
    }

    private function backfill(): void
    {
        $now = now();
        $familyOf = [];

        $newFamily = function (?string $timezone) use ($now): int {
            return (int) DB::table('families')->insertGetId([
                'timezone' => $timezone ?: 'Europe/Ljubljana',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        };
        $addMember = function (int $familyId, int $userId, string $role) use ($now): void {
            DB::table('family_user')->insert([
                'family_id' => $familyId,
                'user_id' => $userId,
                'role' => $role,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        };

        DB::table('users')->where('role', 'parent')->orderBy('id')
            ->select(['id', 'timezone'])
            ->each(function (object $parent) use (&$familyOf, $newFamily, $addMember): void {
                $familyOf[$parent->id] = $newFamily($parent->timezone);
                $addMember($familyOf[$parent->id], (int) $parent->id, 'parent');
            });

        DB::table('users')->where('role', 'child')->orderBy('id')
            ->select(['id', 'parent_id', 'timezone'])
            ->each(function (object $child) use (&$familyOf, $newFamily, $addMember): void {
                if ($child->parent_id !== null && isset($familyOf[$child->parent_id])) {
                    $familyOf[$child->id] = $familyOf[$child->parent_id];
                } elseif (DB::table('pets')->where('user_id', $child->id)->exists()) {
                    $familyOf[$child->id] = $newFamily($child->timezone);
                } else {
                    return; // Unpaired child without a pet: joins a family at pairing.
                }
                $addMember($familyOf[$child->id], (int) $child->id, 'child');
            });

        $parentOwned = [];
        DB::table('pets')->orderBy('id')->select(['id', 'user_id', 'born_at'])
            ->each(function (object $pet) use (&$familyOf, &$parentOwned, $newFamily, $addMember, $now): void {
                $ownerId = (int) $pet->user_id;
                if (! isset($familyOf[$ownerId])) {
                    // Owner is neither a parent-linked child nor handled above
                    // (e.g. a pet owned by a parent account): own family.
                    $familyOf[$ownerId] = $newFamily(null);
                    $role = DB::table('users')->where('id', $ownerId)->value('role') === 'parent' ? 'parent' : 'child';
                    $addMember($familyOf[$ownerId], $ownerId, $role);
                }

                DB::table('pets')->where('id', $pet->id)->update(['family_id' => $familyOf[$ownerId]]);

                if (DB::table('users')->where('id', $ownerId)->value('role') !== 'child') {
                    $parentOwned[] = $pet->id; // no caretaker: only children care for pets

                    return;
                }

                DB::table('pet_caretakers')->insert([
                    'pet_id' => $pet->id,
                    'user_id' => $ownerId,
                    'requires_contract' => $pet->born_at === null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

        if ($parentOwned !== []) {
            $message = 'Family model migration: pets owned by a parent account got a family but no caretaker (fix by hand): pet ids '
                .implode(', ', $parentOwned);
            Log::warning($message);
            if (app()->runningInConsole() && ! app()->runningUnitTests()) {
                fwrite(STDERR, $message.PHP_EOL);
            }
        }

        DB::table('quiet_hours')->orderBy('id')->select(['id', 'parent_id'])
            ->each(function (object $row) use (&$familyOf): void {
                if (isset($familyOf[$row->parent_id])) {
                    DB::table('quiet_hours')->where('id', $row->id)->update(['family_id' => $familyOf[$row->parent_id]]);
                }
            });

        DB::statement(
            'UPDATE activities_log SET actor_user_id = pets.user_id FROM pets
             WHERE pets.id = activities_log.pet_id AND activities_log.activity_type IN ('
            .implode(', ', array_map(fn (string $t): string => "'{$t}'", self::CHILD_ACTIONS)).')'
        );
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS pets_sync_caretaker_is_active ON pets;
            DROP FUNCTION IF EXISTS pets_sync_caretaker_is_active();
            DROP TRIGGER IF EXISTS pet_caretakers_copy_pet_is_active ON pet_caretakers;
            DROP FUNCTION IF EXISTS pet_caretakers_copy_pet_is_active();
        SQL);

        Schema::dropIfExists('pet_daily_steps');
        Schema::dropIfExists('pet_caretakers');

        Schema::table('activities_log', function (Blueprint $table) {
            $table->dropIndex(['actor_user_id', 'created_at']);
            $table->dropConstrainedForeignId('actor_user_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pairing_pet_id');
        });
        Schema::table('quiet_hours', function (Blueprint $table) {
            $table->dropConstrainedForeignId('family_id');
        });
        Schema::table('pets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('family_id');
        });

        Schema::dropIfExists('family_invites');
        Schema::dropIfExists('family_user');
        Schema::dropIfExists('families');
    }
};
