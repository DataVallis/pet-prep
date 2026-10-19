<?php

use App\Console\Commands\ProcessPetDecayCommand;
use App\Filament\Pages\SystemHealth;
use App\Filament\Widgets\SystemHealth\ErrorsPerHourChart;
use App\Filament\Widgets\SystemHealth\FailedJobsTable;
use App\Filament\Widgets\SystemHealth\SystemHealthStats;
use App\Http\Middleware\SetAdminLocale;
use App\Models\FailedJob;
use App\Models\Pet;
use App\Models\SystemError;
use App\Models\User;
use App\Services\SystemHealth\ErrorRecorder;
use App\Services\SystemHealth\ErrorText;
use App\Services\SystemHealth\SystemHealthService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| System health (David 2026-10-19)
|--------------------------------------------------------------------------
|
| Reported exceptions and error log lines → `system_errors` (one row per
| fingerprint, repeat = count + reopen, ≤ 1 write per fingerprint per
| minute, scrubbed, never throws). /admin/system-health shows them to the
| superadmin with resolve, failed-job retry / forget, tiles and a chart.
|
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-19 10:00:00', 'UTC'));
    // phpunit.xml turns the capture off for the rest of the suite.
    config(['system_health.capture_enabled' => true, 'system_health.isolated_connection' => false]);
    Cache::flush();
    app()->forgetInstance(ErrorRecorder::class);
});

function shAdmin(): User
{
    return User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
}

/** A throwable raised at a fixed line, so two calls share a fingerprint. */
function shBoom(string $message = 'Something broke for pet 42'): RuntimeException
{
    return new RuntimeException($message);
}

function shFailedJob(string $queue = 'default'): FailedJob
{
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid = (string) Str::uuid(),
        'connection' => 'database',
        'queue' => $queue,
        'payload' => json_encode(['uuid' => $uuid, 'displayName' => 'App\\Jobs\\StorePetMedia', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'attempts' => 3, 'data' => ['commandName' => 'App\\Jobs\\StorePetMedia', 'command' => 'x']]),
        'exception' => "RuntimeException: download failed token=abcdef123456\n#0 trace",
        'failed_at' => now(),
    ]);

    return FailedJob::where('uuid', $uuid)->sole();
}

describe('capture', function () {
    it('stores a reported exception once and counts repeats by fingerprint', function () {
        report(shBoom('Something broke for pet 42'));

        $error = SystemError::sole();
        expect($error->exception_class)->toBe(RuntimeException::class)
            ->and($error->message)->toBe('Something broke for pet 42')
            ->and($error->file)->toBe('tests/Feature/SystemHealthTest.php')
            ->and($error->occurrences)->toBe(1)
            ->and($error->first_seen_at->eq(now()))->toBeTrue()
            ->and($error->trace)->toBeArray()->not->toBeEmpty()
            ->and($error->source)->not->toBe('http');

        // Another pet id → the same fingerprint (numbers normalised); within the minute only counted.
        $this->travel(10)->seconds();
        report(shBoom('Something broke for pet 43'));
        expect(SystemError::sole()->occurrences)->toBe(1);

        // After the write window: one write adds both occurrences.
        $this->travel(61)->seconds();
        report(shBoom('Something broke for pet 44'));
        $error->refresh();
        expect(SystemError::count())->toBe(1)
            ->and($error->occurrences)->toBe(3)
            ->and($error->message)->toBe('Something broke for pet 44')
            ->and($error->last_seen_at->eq(now()))->toBeTrue();

        expect((int) DB::table('system_error_hours')->sum('occurrences'))->toBe(3);
    });

    it('keeps different errors apart', function () {
        report(new RuntimeException('a'));
        report(new LogicException('a'));
        report(new RuntimeException('a different message'));

        expect(SystemError::count())->toBe(3);
    });

    it('reopens a resolved error that happens again', function () {
        $admin = shAdmin();
        report(shBoom());
        $error = SystemError::sole();

        app(SystemHealthService::class)->resolve([$error], $admin);
        expect($error->fresh()->resolved_at)->not->toBeNull()
            ->and($error->fresh()->resolved_by)->toBe($admin->id);

        // Resolving clears the write window, so the repeat reopens at once.
        $this->travel(5)->seconds();
        report(shBoom());
        expect($error->fresh()->resolved_at)->toBeNull()
            ->and($error->fresh()->resolved_by)->toBeNull()
            ->and($error->fresh()->occurrences)->toBe(2);
    });

    it('records error log lines without an exception, with integer ids only', function () {
        Log::error('RoutineLedgerService: closing days failed', ['pet_id' => 7, 'error' => 'value too long', 'email' => 'a@b.c']);
        Log::warning('just a warning');
        Log::info('fine');

        $error = SystemError::sole();
        expect($error->level)->toBe('error')
            ->and($error->source)->toBe('log')
            ->and($error->exception_class)->toBeNull()
            ->and($error->context['ids'])->toBe(['pet_id' => 7])
            ->and(json_encode($error->context))->not->toContain('a@b.c');
    });

    it('records the scheduled routine-ledger failure that went unseen (report + Log::error)', function () {
        $e = new QueryException('pgsql', 'insert into "pet_daily_routines" ("routine_type") values (?)', ['chewing_resolved_long_value'],
            new PDOException("SQLSTATE[22001]: String data, right truncated: 7 ERROR:  value too long for type character varying(10)\nDETAIL:  Failing row contains (1, 2026-10-18, secret-child-value)."));

        report($e);

        $error = SystemError::sole();
        expect($error->exception_class)->toBe(QueryException::class)
            ->and($error->message)->toContain('value too long')
            ->and($error->message)->toContain('insert into "pet_daily_routines" ("routine_type") values (?)')
            ->and($error->message)->not->toContain('Failing row')
            ->and($error->message)->not->toContain('secret-child-value')
            ->and($error->message)->not->toContain('chewing_resolved_long_value');
    });

    it('keeps bindings out of a QueryException wrapped by another exception', function () {
        $query = new QueryException('pgsql', 'insert into "pets" ("name") values (?)', ['Secret Kid Pet'],
            new PDOException("SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key\nDETAIL:  Key (name)=(Secret Kid Pet) already exists."));
        // Like PairingService: new PairingException($e->getMessage()) with the query as previous.
        report(new RuntimeException($query->getMessage(), 0, $query));

        $message = SystemError::sole()->message;
        expect($message)->not->toContain('Secret Kid Pet')
            ->and($message)->toContain('RuntimeException ← SQLSTATE[23505]')
            ->and($message)->toContain('values (?)');
    });

    it('strips the SQL tail and quoted values when there is no QueryException link', function () {
        // A wrapper that copied the message but dropped the previous exception.
        report(new RuntimeException('SQLSTATE[22P02]: invalid input syntax for type integer: "Rex" (Connection: pgsql, SQL: select * from "pets" where "id" = Rex)'));

        $message = SystemError::sole()->message;
        expect($message)->not->toContain('Rex')
            ->and($message)->toContain('invalid input syntax for type integer: {s} (SQL omitted)');
    });

    it('replaces quoted values in the driver line of a QueryException', function () {
        report(new QueryException('pgsql', 'select * from "pets" where "id" = ?', ['Rex'],
            new PDOException('SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type bigint: "Rex"')));

        $message = SystemError::sole()->message;
        expect($message)->not->toContain('Rex')
            ->and($message)->toContain('bigint: {s}')
            ->and($message)->toContain('(SQL: select * from "pets" where "id" = ?)');
    });

    it('scrubs e-mails, tokens and secrets and truncates long messages', function () {
        config(['system_health.message_max_chars' => 200]);
        report(new RuntimeException('Login failed for parent@example.com with Bearer abc.def.ghi password=hunter2 token: 12|abcdefghijklmnopqrstuvwxyz from 10.0.0.12 '.str_repeat('x ', 300)));

        $message = SystemError::sole()->message;
        expect($message)->not->toContain('parent@example.com')
            ->and($message)->not->toContain('hunter2')
            ->and($message)->not->toContain('abcdefghijklmnopqrstuvwxyz')
            ->and($message)->not->toContain('10.0.0.12')
            ->and($message)->toContain('[email]')
            ->and($message)->toContain('Bearer [redacted]')
            ->and(mb_strlen($message))->toBeLessThanOrEqual(200);
    });

    it('stores where an HTTP error happened but never the request input or user name', function () {
        Route::post('/api/test-system-health/{thing}', fn () => throw new RuntimeException('handler blew up'))->middleware('api')->name('test.system-health');
        $parent = User::factory()->parent()->create(['name' => 'Secret Parent Name']);

        $this->actingAs($parent, 'sanctum')
            ->postJson('/api/test-system-health/5?q=hidden-query', ['child_nickname' => 'Secret Kid'])
            ->assertStatus(500);

        $error = SystemError::sole();
        $stored = json_encode($error->toArray());
        expect($error->source)->toBe('http')
            ->and($error->context)->toMatchArray(['method' => 'POST', 'route' => 'test.system-health', 'user_id' => $parent->id, 'role' => 'parent'])
            ->and($stored)->not->toContain('Secret Kid')
            ->and($stored)->not->toContain('Secret Parent Name')
            ->and($stored)->not->toContain('hidden-query')
            ->and($stored)->not->toContain($parent->email);
    });

    it('does not record validation / 404 / auth errors (not reported)', function () {
        $this->getJson('/api/user')->assertUnauthorized();
        $this->getJson('/api/definitely-not-a-route')->assertNotFound();

        expect(SystemError::count())->toBe(0);
    });

    it('never throws and pauses when the database write fails', function () {
        $db = Mockery::mock(DatabaseManager::class);
        $db->shouldReceive('connection')->once()->andThrow(new RuntimeException('database is on fire'));
        $recorder = new ErrorRecorder($db, app(CacheRepository::class));

        $recorder->recordException(shBoom('first'));
        // Paused after the failure: no second attempt (the mock allows one call only).
        $recorder->recordException(new LogicException('second'));
        $recorder->recordLog('error', 'a log line', []);

        expect(true)->toBeTrue();
    });

    it('never throws through report() when the table is missing', function () {
        DB::statement('ALTER TABLE system_errors RENAME TO system_errors_moved');

        report(shBoom());

        DB::statement('ALTER TABLE system_errors_moved RENAME TO system_errors');
        expect(SystemError::count())->toBe(0);
    });

    it('skips errors that mean the database connection is gone', function () {
        report(new QueryException('pgsql', 'select 1', [], new PDOException('SQLSTATE[08006] server closed the connection unexpectedly')));
        report(new PDOException('SQLSTATE[08006] [7] could not connect to server: Connection refused'));

        expect(SystemError::count())->toBe(0);
    });

    it('does not re-enter itself when its own write logs an error', function () {
        // Every query on system_errors logs an error: recording must not loop.
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'into system_errors ')) {
                Log::error('query hook fired');
            }
        });

        report(shBoom());

        expect(SystemError::count())->toBe(1);
    });

    it('is off with the kill switch', function () {
        config(['system_health.capture_enabled' => false]);
        report(shBoom());
        Log::error('nope');

        expect(SystemError::count())->toBe(0);
    });

    it('normalises varying values for the fingerprint', function () {
        expect(ErrorText::normalise("Pet 12 'Rex' at 0x1f uuid 3f2b1c9e-1d2a-4b3c-8d4e-5f6a7b8c9d0e"))
            ->toBe('Pet {n} {s} at {hex} uuid {uuid}');
    });
});

describe('heartbeat, prune, numbers', function () {
    it('records a scheduler heartbeat on every game-loop tick', function () {
        $health = app(SystemHealthService::class);
        expect($health->lastHeartbeat())->toBeNull()->and($health->heartbeatIsStale())->toBeTrue();

        Artisan::call(ProcessPetDecayCommand::class);

        expect($health->lastHeartbeat()?->eq(now()))->toBeTrue()
            ->and($health->heartbeatIsStale())->toBeFalse();

        $this->travel(6)->minutes();
        expect($health->heartbeatIsStale())->toBeTrue();
    });

    it('prunes resolved errors after 30 days and any error after 90 days', function () {
        $make = fn (string $fp, Carbon $lastSeen, ?Carbon $resolved) => SystemError::create([
            'fingerprint' => $fp, 'level' => 'error', 'message' => $fp, 'source' => 'other',
            'occurrences' => 1, 'first_seen_at' => $lastSeen, 'last_seen_at' => $lastSeen, 'resolved_at' => $resolved,
        ]);
        $make('resolved-old', now()->subDays(40), now()->subDays(31));
        $make('resolved-recent', now()->subDays(40), now()->subDays(29));
        $make('open-old', now()->subDays(91), null);
        $make('open-recent', now()->subDays(60), null);

        Artisan::call('system-health:prune');

        expect(SystemError::orderBy('fingerprint')->pluck('fingerprint')->all())->toBe(['open-recent', 'resolved-recent']);
    });

    it('lists active born pets whose routine ledger is stuck', function () {
        $parent = User::factory()->parent()->create();
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        $stuck = Pet::factory()->create(['user_id' => $child->id, 'born_at' => now()->subDays(10)]);
        DB::table('pets')->where('id', $stuck->id)->update(['routines_closed_through' => now()->subDays(5)->toDateString()]);

        $child2 = User::factory()->child()->create(['parent_id' => $parent->id]);
        $fine = Pet::factory()->create(['user_id' => $child2->id, 'born_at' => now()->subDays(10)]);
        DB::table('pets')->where('id', $fine->id)->update(['routines_closed_through' => now()->subDay()->toDateString()]);

        expect(app(SystemHealthService::class)->stuckLedgerPetIds()->all())->toBe([$stuck->id]);
    });

    it('builds 48 hourly buckets', function () {
        report(shBoom());
        $series = app(SystemHealthService::class)->occurrencesPerHour(48);

        expect($series)->toHaveCount(48)
            ->and(array_sum($series))->toBe(1)
            ->and(end($series))->toBe(1);
    });
});

describe('Filament page', function () {
    it('is superadmin only, English by default and Slovenian on request', function () {
        report(shBoom('Visible error for pet 1'));

        $this->actingAs(shAdmin())->get('/admin/system-health')->assertOk()
            ->assertSee('System health')->assertSee('Errors')->assertSee('Visible error for pet 1');
        $this->withCookie(SetAdminLocale::COOKIE, 'sl')->get('/admin/system-health')->assertOk()->assertSee('Zdravje sistema')->assertSee('Označi kot rešeno');

        app('auth')->forgetGuards();
        $this->actingAs(User::factory()->parent()->create())->get('/admin/system-health')->assertForbidden();
        app('auth')->forgetGuards();
        $this->actingAs(User::factory()->child()->create())->get('/admin/system-health')->assertForbidden();
    });

    it('refuses the Livewire page and its widgets for a non-superadmin', function () {
        $this->actingAs(User::factory()->parent()->create());

        Livewire::test(SystemHealth::class)->assertForbidden();
        Livewire::test(SystemHealthStats::class)->assertForbidden();
        Livewire::test(ErrorsPerHourChart::class)->assertForbidden();
        Livewire::test(FailedJobsTable::class)->assertForbidden();
        expect(SystemHealthStats::canView())->toBeFalse()
            ->and(FailedJobsTable::canView())->toBeFalse()
            ->and(ErrorsPerHourChart::canView())->toBeFalse();
    });

    it('shows a red navigation badge with open errors of the last 24 h', function () {
        $this->actingAs(shAdmin());
        expect(SystemHealth::getNavigationBadge())->toBe('0')
            ->and(SystemHealth::getNavigationBadgeColor())->toBe('success');

        report(shBoom());
        // The badge is counted once per request (badge + colour share it).
        expect(SystemHealth::getNavigationBadge())->toBe('0');
        app()->instance('request', Request::create('/admin'));
        expect(SystemHealth::getNavigationBadge())->toBe('1')
            ->and(SystemHealth::getNavigationBadgeColor())->toBe('danger');
    });

    it('marks an error resolved, filters open / resolved and bulk resolves', function () {
        $admin = shAdmin();
        report(new RuntimeException('one'));
        report(new LogicException('two'));
        report(new InvalidArgumentException('three'));
        [$one, $two, $three] = SystemError::orderBy('id')->get()->all();
        $this->actingAs($admin);

        Livewire::test(SystemHealth::class)
            ->assertCanSeeTableRecords([$one, $two, $three])
            ->callTableAction('view', $one)
            ->assertHasNoTableActionErrors()
            ->callTableAction('resolve', $one)
            ->assertNotified()
            // Default filter = open.
            ->assertCanNotSeeTableRecords([$one])
            ->filterTable('status', 'resolved')
            ->assertCanSeeTableRecords([$one])
            ->assertCanNotSeeTableRecords([$two, $three]);

        expect($one->fresh()->resolved_by)->toBe($admin->id);

        Livewire::test(SystemHealth::class)
            ->callTableBulkAction('resolve', [$two, $three]);

        expect(SystemError::open()->count())->toBe(0);
    });

    it('searches errors by message', function () {
        report(new RuntimeException('needle in the stack'));
        report(new LogicException('hay'));
        $this->actingAs(shAdmin());

        Livewire::test(SystemHealth::class)
            ->searchTable('needle')
            ->assertCanSeeTableRecords(SystemError::where('message', 'like', '%needle%')->get())
            ->assertCanNotSeeTableRecords(SystemError::where('message', 'hay')->get());
    });

    it('renders the tiles and the chart', function () {
        report(shBoom());
        shFailedJob();
        $this->actingAs(shAdmin());

        Livewire::test(SystemHealthStats::class)->assertOk()->assertSee('Failed jobs')->assertSee('Scheduler heartbeat');
        Livewire::test(ErrorsPerHourChart::class)->assertOk();
    });

    it('retries and forgets failed jobs', function () {
        config(['queue.default' => 'database', 'queue.failed.database' => config('database.default')]);
        $retry = shFailedJob('default');
        $forget = shFailedJob('notifications');
        $this->actingAs(shAdmin());

        Livewire::test(FailedJobsTable::class)
            ->assertCanSeeTableRecords([$retry, $forget])
            ->assertDontSee('abcdef123456')
            ->callTableAction('retry', $retry)
            ->assertNotified()
            ->callTableAction('forget', $forget);

        expect(FailedJob::count())->toBe(0);
        // A second retry of the same (already claimed) row pushes nothing.
        expect(app(SystemHealthService::class)->retryFailedJob($retry))->toBeFalse();
        $pushed = DB::table('jobs')->sole();
        expect($pushed->queue)->toBe('default')
            ->and(json_decode($pushed->payload, true)['attempts'])->toBe(0);
    });
});

describe('isolated connection', function () {
    // The real production path: the recorder writes over its own connection,
    // so it commits independently of the test's RefreshDatabase transaction.
    // Rows written here are committed — clean them up by hand.
    beforeEach(function () {
        config(['system_health.isolated_connection' => true]);
        app()->forgetInstance(ErrorRecorder::class);
    });

    afterEach(function () {
        DB::connection(ErrorRecorder::CONNECTION)->table('system_errors')->delete();
        DB::purge(ErrorRecorder::CONNECTION);
    });

    it('keeps the row when the caller rolls its transaction back after a failed statement', function () {
        try {
            DB::transaction(function () {
                try {
                    DB::statement('SELECT 1/0');
                } catch (QueryException $e) {
                    // The caller's transaction is now aborted (Postgres): a write on it would fail.
                    report($e);
                    throw $e;
                }
            });
        } catch (QueryException) {
            // rolled back
        }

        $rows = DB::connection(ErrorRecorder::CONNECTION)->table('system_errors')->get();
        expect($rows)->toHaveCount(1)
            ->and($rows[0]->message)->toContain('division by zero');
    });

    it('is not blocked by row locks the caller holds', function () {
        $parent = User::factory()->parent()->create();
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        $pet = Pet::factory()->create(['user_id' => $child->id]);

        DB::transaction(function () use ($pet) {
            Pet::whereKey($pet->id)->lockForUpdate()->first();
            $started = microtime(true);
            report(shBoom('while holding a pet lock'));

            expect(microtime(true) - $started)->toBeLessThan(2.0);
        });

        expect(DB::connection(ErrorRecorder::CONNECTION)->table('system_errors')->count())->toBe(1);
    });
});
