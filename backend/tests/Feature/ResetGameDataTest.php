<?php

use App\Console\Commands\ResetGameDataCommand;
use App\Models\ActivityLog;
use App\Models\AiSpendLedger;
use App\Models\ChildLoginPin;
use App\Models\FamilyInvite;
use App\Models\Pet;
use App\Models\PetContract;
use App\Models\PetDailyStep;
use App\Models\PetMedia;
use App\Models\User;
use App\Services\FamilyService;
use App\Services\GameDataResetService;
use App\Services\Media\PetMediaService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/*
|--------------------------------------------------------------------------
| petprep:reset-game-data — production reset before the beta (D17)
|--------------------------------------------------------------------------
| Every schema table is classified (keep / partial / delete); dry run changes
| nothing; --execute refuses without the long flag, a backup, maintenance mode
| (production), an admin to keep or the typed host; with them it deletes every
| game row + media file and keeps superadmins, their tokens and breed data
| (incl. edits); a second run is a no-op.
*/

const RG_FLAGS = [
    '--execute' => true,
    '--i-understand-this-deletes-all-game-data' => true,
    '--backup-done' => '/opt/petprep/backups/pre-reset/pre-reset_db_test.sql.gz',
];

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake('pet_media');
    config(['app.url' => 'https://api.petprep.si', 'media.storage.reset_expected_root' => Storage::disk('pet_media')->path('')]);
    seedBreedConfigs();
    seedStageParams();
});

/**
 * Realistic data in EVERY table the reset deletes: two families (one of them
 * owned by a superadmin who also plays as a parent), PIN children, pets with
 * media files + history, activity, routines, play, training, push, payments,
 * AI spend + AI Lab, tokens, sessions, cache and queue rows, and breed edits by
 * an admin and by a (later deleted) non-admin.
 *
 * @return array{admin: User, admin2: User, parent: User, child: User, pet: Pet, adminEdit: int, userEdit: int}
 */
function rgSeed(): array
{
    // Superadmin who also uses the app as a parent (has a family + child + pet).
    $admin = User::factory()->parent()->create(['is_superadmin' => true, 'email' => 'admin@example.com']);
    DB::table('users')->where('id', $admin->id)->update(['pairing_pin' => '123456', 'pin_expires_at' => now()->addHour()]);
    $adminChild = User::factory()->pinOnlyChild()->create(['parent_id' => $admin->id, 'name' => 'Nika']);
    $adminPet = Pet::factory()->purchased()->create(['user_id' => $adminChild->id]);

    // Ordinary family.
    $parent = User::factory()->parent()->create(['email' => 'mama@example.com', 'name' => 'Mama Ana']);
    $child = User::factory()->pinOnlyChild()->create(['parent_id' => $parent->id, 'name' => 'Maja']);
    $pet = Pet::factory()->purchased()->withPetDna()->create(['user_id' => $child->id]);
    $family = app(FamilyService::class)->familyOf($parent);

    // Second superadmin whose deprecated parent_id points at the ordinary
    // parent: users.parent_id CASCADEs — the reset must not take the admin.
    $admin2 = User::factory()->parent()->create(['is_superadmin' => true, 'email' => 'ops@example.com']);
    DB::table('users')->where('id', $admin2->id)->update(['parent_id' => $parent->id]);

    $now = now();
    $today = $now->toDateString();

    foreach ([$pet, $adminPet] as $p) {
        Storage::disk('pet_media')->put("{$p->id}/reference-g1.jpg", "\xFF\xD8\xFF\xE0".str_repeat("\x00", 64));
        Storage::disk('pet_media')->put("{$p->id}/video-idle-g1.mp4", "\x00\x00\x00\x18ftypmp42".str_repeat("\x00", 32));
        PetMedia::create([
            'pet_id' => $p->id, 'kind' => 'image', 'state' => null, 'status' => 'ready', 'generation' => 1,
            'storage_path' => "{$p->id}/reference-g1.jpg", 'mime' => 'image/jpeg', 'bytes' => 68, 'profile' => 'nano_banana_pro',
        ]);
    }
    Storage::disk('pet_media')->put("{$pet->id}/history/reference-g0.jpg", "\xFF\xD8\xFF\xE0".str_repeat("\x00", 16));
    rgInsert('pet_media_history', [
        'pet_id' => $pet->id, 'kind' => 'image', 'generation' => 0, 'storage_path' => "{$pet->id}/history/reference-g0.jpg",

    ]);

    // M4-10: a shared look of the free-pet pool (game data too) with its stored image.
    $lookId = rgInsert('pet_looks', ['breed_type' => 'mutt', 'pool_index' => 1, 'trait_fingerprint' => str_repeat('a', 64), 'dna' => json_encode(['version' => 2])]);
    Storage::disk('pet_media')->put("looks/{$lookId}/reference-puppy-g1.jpg", "\xFF\xD8\xFF\xE0".str_repeat("\x00", 16));
    PetMedia::create([
        'pet_look_id' => $lookId, 'kind' => 'image', 'life_stage' => 'puppy', 'status' => 'ready', 'generation' => 1,
        'storage_path' => "looks/{$lookId}/reference-puppy-g1.jpg", 'mime' => 'image/jpeg', 'bytes' => 20,
    ]);

    $image = PetMedia::where('pet_id', $pet->id)->firstOrFail();
    $runId = rgInsert('media_lab_runs', [
        'kind' => 'image', 'profiles' => json_encode(['nano_banana_pro']), 'user_id' => $admin->id,
    ]);
    $resultId = rgInsert('media_lab_results', [
        'media_lab_run_id' => $runId, 'kind' => 'image', 'profile' => 'nano_banana_pro', 'endpoint' => 'fal-ai/x', 'prompt' => 'a dog',

    ]);
    AiSpendLedger::create([
        'purpose' => 'reference_image', 'profile' => 'nano_banana_pro', 'endpoint' => 'fal-ai/x', 'unit' => 'image',
        'units' => 1, 'cost_usd' => 0.15, 'status' => 'committed', 'request_id' => 'req-'.$pet->id,
        'pet_id' => $pet->id, 'pet_media_id' => $image->id,
    ]);
    rgInsert('ai_spend_ledger', [
        'purpose' => 'lab', 'profile' => 'nano_banana_pro', 'endpoint' => 'fal-ai/x', 'unit' => 'image', 'units' => 1,
        'cost_usd' => 0.15, 'status' => 'committed', 'media_lab_result_id' => $resultId,
    ]);

    PetContract::create(['pet_id' => $pet->id, 'user_id' => $child->id, 'signature_format' => 'svg_path', 'signature' => 'M1 1 L2 2', 'signed_at' => $now]);
    ActivityLog::create(['pet_id' => $pet->id, 'actor_user_id' => $child->id, 'activity_type' => 'fed_pet', 'value' => 40]);
    PetDailyStep::create(['pet_id' => $pet->id, 'user_id' => $child->id, 'local_date' => $today, 'steps' => 1200]);
    rgInsert('pet_daily_walks', ['pet_id' => $pet->id, 'local_date' => $today, 'steps' => 1200, 'goal' => 5000, 'achieved' => false]);
    rgInsert('pet_daily_routines', [
        'pet_id' => $pet->id, 'local_date' => $today, 'routine_type' => 'feed', 'slot' => 1, 'opens_at' => $now, 'due_at' => $now,
        'status' => 'done', 'actor_user_id' => $child->id,
    ]);
    rgInsert('pet_hygiene_events', ['pet_id' => $pet->id, 'local_date' => $today, 'scheduled_at' => $now]);
    rgInsert('pet_status_periods', ['pet_id' => $pet->id, 'kind' => 'hard_stop', 'started_at' => $now->copy()->subHour(), 'ended_at' => $now]);
    rgInsert('pet_play_events', [
        'pet_id' => $pet->id, 'kind' => 'cuddle', 'source' => 'free', 'local_date' => $today, 'status' => 'done',
        'completed_at' => $now, 'completed_by' => $child->id,
    ]);
    rgInsert('pet_training_skills', ['pet_id' => $pet->id, 'command' => 'sit']);
    rgInsert('pet_training_sessions', [
        'public_id' => (string) Str::uuid(), 'pet_id' => $pet->id, 'user_id' => $child->id, 'command' => 'sit', 'local_date' => $today,
        'started_at' => $now, 'ends_at' => $now->copy()->addMinute(), 'expires_at' => $now->copy()->addMinutes(2), 'duration_ms' => 60000,
        'schedule' => json_encode([]),
    ]);
    // M5-R06-04: cat care sessions (wand play) go with the game data too.
    rgInsert('pet_care_sessions', [
        'public_id' => (string) Str::uuid(), 'pet_id' => $pet->id, 'user_id' => $child->id, 'kind' => 'wand_play', 'local_date' => $today,
        'started_at' => $now, 'ends_at' => $now->copy()->addMinute(), 'expires_at' => $now->copy()->addMinutes(2), 'duration_ms' => 60000,
        'schedule' => json_encode([]),
    ]);

    setQuietHours(['parent_id' => $parent->id, 'bedtime_start' => '21:00', 'bedtime_end' => '07:00', 'is_active' => true]);
    FamilyInvite::create(['family_id' => $family->id, 'created_by' => $parent->id, 'code' => strtoupper(Str::random(8)), 'expires_at' => $now->copy()->addDay()]);
    ChildLoginPin::create(['family_id' => $family->id, 'child_user_id' => $child->id, 'created_by' => $parent->id, 'pin_hash' => hash_hmac('sha256', '123456', 'k'), 'expires_at' => $now->copy()->addMinutes(15)]);

    $parentToken = $parent->createToken('iPhone', ['parent']);
    $child->createToken('iPad', ['child']);
    $admin->createToken('iPhone', ['parent']);
    $deviceId = rgInsert('device_push_tokens', [
        'user_id' => $parent->id, 'expo_push_token' => 'ExponentPushToken[test]', 'platform' => 'ios',
        'personal_access_token_id' => $parentToken->accessToken->id,
    ]);
    $pushId = rgInsert('push_notifications', [
        'idempotency_key' => (string) Str::uuid(), 'pet_id' => $pet->id, 'type' => 'soft_warning',
        'recipients' => json_encode([$parent->id]), 'status' => 'sent',
    ]);
    rgInsert('push_tickets', ['push_notification_id' => $pushId, 'device_push_token_id' => $deviceId, 'status' => 'ok']);

    $purchaseId = rgInsert('purchase_events', [
        'event_id' => 'evt-'.Str::random(8), 'type' => 'NON_RENEWING_PURCHASE', 'payload' => json_encode(['x' => 1]),
        'family_id' => $family->id,
    ]);
    rgInsert('challenge_credits', [
        'family_id' => $family->id, 'purchase_event_id' => $purchaseId, 'product_id' => 'petprep_challenge_12w', 'purchased_at' => $now,
        'pet_id' => $pet->id, 'assigned_at' => $now, 'assigned_via' => 'parent',
    ]);

    rgInsert('sessions', ['id' => Str::random(40), 'user_id' => $admin->id, 'payload' => 'x', 'last_activity' => $now->timestamp]);
    rgInsert('password_reset_tokens', ['email' => 'mama@example.com', 'token' => 'hash', 'created_at' => $now]);
    rgInsert('cache', ['key' => 'petprep:throttle', 'value' => 'i:1;', 'expiration' => $now->timestamp + 60]);
    rgInsert('cache_locks', ['key' => 'petprep:lock', 'owner' => 'x', 'expiration' => $now->timestamp + 60]);
    rgInsert('jobs', ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => $now->timestamp, 'created_at' => $now->timestamp]);
    rgInsert('job_batches', ['id' => (string) Str::uuid(), 'name' => 'b', 'total_jobs' => 1, 'pending_jobs' => 1, 'failed_jobs' => 0, 'failed_job_ids' => '[]', 'created_at' => $now->timestamp]);
    rgInsert('failed_jobs', ['uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x']);

    // Breed edits (Filament): one by the admin, one by a user that goes.
    $param = DB::table('breed_stage_params')->orderBy('id')->first();
    DB::table('breed_stage_params')->where('id', $param->id)->update(['updated_by' => $admin->id]);
    $edit = fn (int $userId): int => rgInsert('breed_stage_param_changes', [
        'breed_stage_param_id' => $param->id, 'breed_slug' => $param->breed_slug, 'stage' => $param->stage,
        'age_from_months' => $param->age_from_months, 'key' => $param->key, 'action' => 'updated', 'user_id' => $userId,
        'old' => json_encode(['value' => 1]), 'new' => json_encode(['value' => 2]),
    ]);
    $adminEdit = $edit($admin->id);
    $userEdit = $edit($parent->id);

    foreach (GameDataResetService::DELETE_ORDER as $table) {
        expect(DB::table($table)->count())->toBeGreaterThan(0, "seed must fill {$table}");
    }

    return ['admin' => $admin, 'admin2' => $admin2, 'parent' => $parent, 'child' => $child, 'pet' => $pet, 'adminEdit' => $adminEdit, 'userEdit' => $userEdit];
}

/** Raw insert with timestamps where the table has them; returns the id when there is one. */
function rgInsert(string $table, array $row): int
{
    foreach (['created_at', 'updated_at'] as $column) {
        if (! array_key_exists($column, $row) && Schema::hasColumn($table, $column)) {
            $row[$column] = now();
        }
    }

    if (! Schema::hasColumn($table, 'id') || in_array($table, ['job_batches', 'sessions'], true)) {
        DB::table($table)->insert($row);

        return 0;
    }

    return (int) DB::table($table)->insertGetId($row);
}

/** Row count of every table in the schema. @return array<string, int> */
function rgCounts(): array
{
    $counts = [];
    foreach (app(GameDataResetService::class)->schemaTables() as $table) {
        $counts[$table] = DB::table($table)->count();
    }

    return $counts;
}

/** Full content of a table, order-independent. @return list<string> */
function rgSnapshot(string $table, ?Closure $filter = null): array
{
    return DB::table($table)->when($filter, $filter)->get()
        ->map(fn ($row) => json_encode($row))->sort()->values()->all();
}

function rgMaintenance(bool $down): void
{
    app()->instance(MaintenanceMode::class, new class($down) implements MaintenanceMode
    {
        public function __construct(private bool $down) {}

        public function activate(array $payload): void {}

        public function deactivate(): void {}

        public function active(): bool
        {
            return $this->down;
        }

        public function data(): array
        {
            return [];
        }
    });
}

it('classifies every table of the schema exactly once', function () {
    $service = app(GameDataResetService::class);
    $lists = array_merge(GameDataResetService::KEEP, GameDataResetService::PARTIAL, GameDataResetService::DELETE_ORDER);
    // PARTIAL tables also appear in DELETE_ORDER (their deletable rows go there).
    $classified = array_values(array_unique($lists));

    expect($service->unclassifiedTables())->toBe([], 'new table? add it to KEEP, PARTIAL or DELETE_ORDER in GameDataResetService')
        ->and(array_diff($classified, $service->schemaTables()))->toBe([], 'a classified table no longer exists — remove it from the lists')
        ->and(array_intersect(GameDataResetService::KEEP, GameDataResetService::DELETE_ORDER))->toBe([])
        ->and(array_diff(GameDataResetService::PARTIAL, GameDataResetService::DELETE_ORDER))->toBe([])
        ->and(count(GameDataResetService::DELETE_ORDER))->toBe(count(array_unique(GameDataResetService::DELETE_ORDER)));
});

it('refuses (dry run and execute) while a table is unclassified', function () {
    rgSeed();
    Schema::create('zz_future_feature', fn ($t) => $t->id());
    $before = rgCounts();

    $this->artisan('petprep:reset-game-data')
        ->expectsOutputToContain('Unclassified tables: zz_future_feature')
        ->assertFailed();
    $this->artisan('petprep:reset-game-data', RG_FLAGS + ['--no-interaction' => true])->assertFailed();

    expect(rgCounts())->toBe($before);
    expect(fn () => app(GameDataResetService::class)->execute())->toThrow(RuntimeException::class, 'zz_future_feature');
});

it('dry run prints counts but changes nothing and prints no personal data', function () {
    $s = rgSeed();
    $before = rgCounts();
    $files = Storage::disk('pet_media')->allFiles();

    $this->artisan('petprep:reset-game-data')
        ->expectsOutputToContain('Dry run — nothing was changed')
        ->expectsOutputToContain('Pet media files to delete: 6')
        ->expectsOutputToContain("Superadmins kept: 2 (ids {$s['admin']->id}, {$s['admin2']->id})")
        ->expectsOutputToContain('Breed edits by non-admin users: 1')
        ->doesntExpectOutputToContain('@example.com')
        ->doesntExpectOutputToContain('Maja')
        ->doesntExpectOutputToContain('Mama Ana')
        ->assertSuccessful();

    expect(rgCounts())->toBe($before)
        ->and(Storage::disk('pet_media')->allFiles())->toBe($files);

    $plan = app(GameDataResetService::class)->plan();
    expect($plan['delete']['users'])->toBe(User::count() - 2)
        ->and($plan['keep']['users'])->toBe(2)
        ->and($plan['keep']['personal_access_tokens'])->toBe(1)
        ->and($plan['delete']['pets'])->toBe(2);
});

it('refuses --execute without the long flag, without a backup, without admins, or with a wrong host', function () {
    rgSeed();
    $before = rgCounts();

    $this->artisan('petprep:reset-game-data', ['--execute' => true, '--no-interaction' => true])
        ->expectsOutputToContain('--i-understand-this-deletes-all-game-data')
        ->assertFailed();
    $this->artisan('petprep:reset-game-data', ['--execute' => true, '--i-understand-this-deletes-all-game-data' => true, '--no-interaction' => true])
        ->expectsOutputToContain('no fresh backup')
        ->assertFailed();
    $this->artisan('petprep:reset-game-data', RG_FLAGS)
        ->expectsQuestion('This deletes ALL game data on api.petprep.si (env: testing). Type the host to confirm', 'petprep.si')
        ->expectsOutputToContain('Confirmation did not match')
        ->assertFailed();

    expect(rgCounts())->toBe($before);

    DB::table('users')->update(['is_superadmin' => false]);
    $this->artisan('petprep:reset-game-data', RG_FLAGS + ['--no-interaction' => true])
        ->expectsOutputToContain('no superadmin account')
        ->assertFailed();
    expect(fn () => app(GameDataResetService::class)->execute())->toThrow(RuntimeException::class, 'No superadmin');
    expect(DB::table('pets')->count())->toBe(2);
});

it('refuses in production unless the app is in maintenance mode', function () {
    rgSeed();
    $this->app['env'] = 'production';
    rgMaintenance(false);

    $this->artisan('petprep:reset-game-data', RG_FLAGS + ['--no-interaction' => true])
        ->expectsOutputToContain('maintenance mode')
        ->assertFailed();
    expect(DB::table('pets')->count())->toBe(2);

    rgMaintenance(true);
    $this->artisan('petprep:reset-game-data', RG_FLAGS + ['--no-interaction' => true])->assertSuccessful();
    expect(DB::table('pets')->count())->toBe(0);
});

it('deletes all game data and media, keeps admins, their tokens and breed data; a rerun is a no-op', function () {
    $s = rgSeed();
    $adminIds = [$s['admin']->id, $s['admin2']->id];
    $keptBreed = [
        'migrations' => rgSnapshot('migrations'),
        'breed_configs' => rgSnapshot('breed_configs'),
        'breed_stage_params' => rgSnapshot('breed_stage_params'),
        'breed_stage_param_changes' => rgSnapshot('breed_stage_param_changes', fn ($q) => $q->where('id', '!=', $s['userEdit'])),
    ];
    $adminToken = PersonalAccessToken::where('tokenable_id', $s['admin']->id)->sole();

    $this->artisan('petprep:reset-game-data', RG_FLAGS)
        ->expectsQuestion('This deletes ALL game data on api.petprep.si (env: testing). Type the host to confirm', 'api.petprep.si')
        ->expectsOutputToContain('Done. Kept 2 superadmin(s)')
        ->expectsOutputToContain('Media files deleted: 6')
        ->assertSuccessful();

    foreach (GameDataResetService::DELETE_ORDER as $table) {
        if (! in_array($table, GameDataResetService::PARTIAL, true)) {
            expect(DB::table($table)->count())->toBe(0, "{$table} must be empty");
        }
    }

    // Admins stay — without family, parent link or pairing — and keep their own token.
    expect(User::orderBy('id')->pluck('id')->all())->toBe($adminIds)
        ->and(User::whereIn('id', $adminIds)->whereNotNull('parent_id')->count())->toBe(0)
        ->and(User::whereIn('id', $adminIds)->where(fn ($q) => $q->whereNotNull('pairing_pin')->orWhereNotNull('pin_expires_at'))->count())->toBe(0)
        ->and(User::where('id', $s['admin']->id)->value('email'))->toBe('admin@example.com')
        ->and(PersonalAccessToken::pluck('id')->all())->toBe([$adminToken->id]);

    // Breed reference data untouched; the non-admin's edit stays without author.
    foreach ($keptBreed as $table => $snapshot) {
        $filter = $table === 'breed_stage_param_changes' ? fn ($q) => $q->where('id', '!=', $s['userEdit']) : null;
        expect(rgSnapshot($table, $filter))->toBe($snapshot, "{$table} changed");
    }
    expect(DB::table('breed_stage_param_changes')->where('id', $s['userEdit'])->value('user_id'))->toBeNull()
        ->and(DB::table('breed_stage_param_changes')->where('id', $s['adminEdit'])->value('user_id'))->toBe($s['admin']->id);

    expect(Storage::disk('pet_media')->allFiles())->toBe([])
        ->and(Storage::disk('pet_media')->directories())->toBe([]);

    // Second run: nothing left to delete.
    $after = rgCounts();
    $result = app(GameDataResetService::class)->execute();
    expect(array_sum($result['deleted']))->toBe(0)
        ->and($result['media']['files'])->toBe(0)
        ->and(rgCounts())->toBe($after);

    $this->artisan('petprep:reset-game-data', RG_FLAGS + ['--no-interaction' => true])
        ->expectsOutputToContain('Media files deleted: 0')
        ->assertSuccessful();
    expect(rgCounts())->toBe($after);
});

it('lets an admin start a new family after the reset', function () {
    $s = rgSeed();
    $this->artisan('petprep:reset-game-data', RG_FLAGS + ['--no-interaction' => true])->assertSuccessful();

    $admin = User::findOrFail($s['admin']->id);
    expect(app(FamilyService::class)->familyOf($admin))->toBeNull();

    $family = app(FamilyService::class)->ensureFamilyFor($admin);
    expect($family->exists)->toBeTrue();

    // A new tester registers with the same e-mail as a deleted parent.
    $tester = User::factory()->parent()->create(['email' => 'mama@example.com']);
    expect($tester->id)->toBeGreaterThan($s['parent']->id);
});

it('keeps the long flag name in sync with the runbook', function () {
    expect(ResetGameDataCommand::LONG_FLAG)->toBe('i-understand-this-deletes-all-game-data');
});

it('rolls the whole DB step back when a DELETE fails mid-way (nothing deleted, media untouched)', function () {
    rgSeed();
    $before = rgCounts();
    $files = Storage::disk('pet_media')->allFiles();

    // `pets` comes after ~20 tables in DELETE_ORDER: those deletes must be undone too.
    DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION rg_block_delete() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN RAISE EXCEPTION 'rg blocked delete'; END $$;
        CREATE TRIGGER rg_block_delete BEFORE DELETE ON pets FOR EACH ROW EXECUTE FUNCTION rg_block_delete();
        SQL);

    $this->artisan('petprep:reset-game-data', RG_FLAGS + ['--no-interaction' => true])
        ->expectsOutputToContain('nothing was deleted')
        ->assertExitCode(1);

    expect(rgCounts())->toBe($before)
        ->and(Storage::disk('pet_media')->allFiles())->toBe($files);
});

it('reports a failure after the commit separately (exit 2) and a rerun finishes the cleanup', function () {
    rgSeed();
    $root = Storage::disk('pet_media')->path('');
    $broken = Mockery::mock(Filesystem::class);
    $broken->shouldReceive('path')->andReturn($root);
    $broken->shouldReceive('allFiles')->andReturn(['1/reference-g1.jpg']);
    $broken->shouldReceive('size')->andReturn(68);
    $broken->shouldReceive('directories')->andReturn(['1']);
    $broken->shouldReceive('files')->andReturn([]);
    $broken->shouldReceive('deleteDirectory')->andThrow(new RuntimeException('disk is read-only'));
    $this->mock(PetMediaService::class, fn ($m) => $m->shouldReceive('disk')->andReturn($broken));

    $this->artisan('petprep:reset-game-data', RG_FLAGS + ['--no-interaction' => true])
        ->expectsOutputToContain('The DB reset is COMMITTED')
        ->expectsOutputToContain('Rerun the same --execute command')
        ->assertExitCode(ResetGameDataCommand::EXIT_POST_COMMIT_FAILED);

    expect(DB::table('pets')->count())->toBe(0)
        ->and(Storage::disk('pet_media')->allFiles())->not->toBe([]); // the broken disk deleted nothing

    // Disk works again: the rerun deletes no rows and empties the media disk.
    $this->app->forgetInstance(PetMediaService::class);
    $this->app->offsetUnset(PetMediaService::class);
    $this->artisan('petprep:reset-game-data', RG_FLAGS + ['--no-interaction' => true])->assertExitCode(0);
    expect(Storage::disk('pet_media')->allFiles())->toBe([]);
});

it('refuses when the media disk is not the directory the wrapper archives', function () {
    rgSeed();
    $before = rgCounts();

    config(['media.storage.reset_expected_root' => '/var/www/html/storage/app/pet-media']);
    $this->artisan('petprep:reset-game-data')
        ->expectsOutputToContain('expected /var/www/html/storage/app/pet-media')
        ->assertFailed();
    $this->artisan('petprep:reset-game-data', RG_FLAGS + ['--no-interaction' => true])->assertFailed();
    expect(fn () => app(GameDataResetService::class)->execute())->toThrow(RuntimeException::class, 'Media disk root');

    config(['media.storage.reset_expected_root' => Storage::disk('pet_media')->path(''), 'filesystems.disks.pet_media.driver' => 's3']);
    $this->artisan('petprep:reset-game-data', RG_FLAGS + ['--no-interaction' => true])
        ->expectsOutputToContain('is not a local disk')
        ->assertFailed();

    expect(rgCounts())->toBe($before);
});

it('prints the media root in the dry run', function () {
    rgSeed();
    $root = rtrim(Storage::disk('pet_media')->path(''), '/');

    $this->artisan('petprep:reset-game-data')
        ->expectsOutputToContain("in {$root}.")
        ->assertSuccessful();
});
