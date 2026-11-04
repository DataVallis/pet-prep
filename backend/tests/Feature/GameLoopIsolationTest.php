<?php

use App\Enums\AdminAlertKind;
use App\Jobs\SendGameLoopPetErrorAlert;
use App\Models\ActivityLog;
use App\Models\Pet;
use App\Models\PushNotification;
use App\Models\QuietHours;
use App\Models\User;
use App\Services\GameLoop\PetLoopGuard;
use App\Services\HygieneEventService;
use App\Services\NotificationService;
use App\Services\PetDecayService;
use App\Services\RoutineLedgerService;
use App\Services\SystemHealth\SystemHealthService;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

/*
|--------------------------------------------------------------------------
| Game loop — per-pet error isolation (David 2026-11-04, after PR #737)
|--------------------------------------------------------------------------
|
| A pet whose unit of work throws in `pets:process-decay` is rolled back alone,
| reported (System health) and skipped; every other pet still decays, escalates and
| gets its day closed in the same run — a decay failure never skips escalation.
| A failing pet is posted to Discord (`game_loop_pet_error`) at most once per day.
|
*/

const GL_URL = 'https://discord.com/api/webhooks/123456/secret-token-abc';

/** Test seam: the decay of the pet whose id is in GlFailingHygiene::$failFor throws after a write. */
class GlFailingHygiene extends HygieneEventService
{
    public static ?int $failFor = null;

    public function applyDue(Pet $pet, CarbonInterface $from, CarbonInterface $now, ?QuietHours $quietHours): ?Carbon
    {
        if ($pet->id === self::$failFor) {
            // A write inside the pet's unit — must be rolled back with it.
            DB::table('pets')->where('id', $pet->id)->update(['thirst_level' => 1]);

            throw new RuntimeException('hygiene exploded for this pet');
        }

        return parent::applyDue($pet, $from, $now, $quietHours);
    }
}

/** Test seam: the escalation of the pet whose id is in $failFor throws (after its matrix step). */
class GlFailingNotifications extends NotificationService
{
    public static ?int $failFor = null;

    public function walkReminder(Pet $pet): ?PushNotification
    {
        if ($pet->id === self::$failFor) {
            throw new LogicException('walk reminder exploded for this pet');
        }

        return parent::walkReminder($pet);
    }
}

function glPet(array $attributes = []): Pet
{
    $parent = User::factory()->parent()->create();
    withoutQuietHours($parent);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);

    return disableHygieneEvents(Pet::factory()->create(array_merge([
        'user_id' => $child->id,
        'hunger_level' => 80,
        'thirst_level' => 80,
        'energy_level' => 100,
        'hygiene_level' => 100,
        'escalation_level' => 0,
        'last_decay_at' => now()->subHour(),
    ], $attributes)));
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-04 12:00:00', 'UTC'));
    seedBreedConfigs();
    Http::preventStrayRequests();
    Sleep::fake();
    Cache::flush();
    config(['services.discord.alerts_webhook_url' => null]);
    GlFailingHygiene::$failFor = null;
    GlFailingNotifications::$failFor = null;
    app()->singleton(HygieneEventService::class, GlFailingHygiene::class);
    app()->bind(NotificationService::class, GlFailingNotifications::class);
    app()->forgetInstance(PetDecayService::class);
});

it('a pet whose decay throws is rolled back alone — the others decay and a stale level 3 is reset in the same run', function () {
    $broken = glPet(['escalation_level' => 0]);
    // The incident of 2026-11-04: level 3 left behind although every meter is > 30 %.
    $stale = glPet(['escalation_level' => 3]);
    $after = glPet();
    GlFailingHygiene::$failFor = $broken->id;
    $before = $broken->fresh()->getAttributes();

    Artisan::call('pets:process-decay');

    // The failing pet: its whole unit rolled back (the write inside it too), clock untouched.
    $b = $broken->fresh();
    expect((float) $b->thirst_level)->toBe(80.0)
        ->and($b->last_decay_at->equalTo(Carbon::parse($before['last_decay_at'])))->toBeTrue();

    // Pets before and after it: decayed (mutt −8 %/h hunger) and escalated.
    expect($stale->fresh()->displayMetric('hunger_level'))->toBe(72)
        ->and($stale->fresh()->escalation_level)->toBe(0)
        ->and($after->fresh()->displayMetric('hunger_level'))->toBe(72);

    // Counted for the run; escalation of the broken pet itself still ran.
    expect(app(PetLoopGuard::class)->failuresPerStage())->toBe(['decay' => 1])
        ->and(app(PetLoopGuard::class)->failedPets())->toBe([$broken->id => ['stage' => 'decay', 'exception' => RuntimeException::class]]);
});

it('a pet whose escalation throws does not stop escalation for the pets after it', function () {
    $broken = glPet(['hunger_level' => 25, 'energy_level' => 10, 'last_decay_at' => now()]);
    $stale = glPet(['escalation_level' => 3, 'last_decay_at' => now()]);
    GlFailingNotifications::$failFor = $broken->id;

    Artisan::call('pets:process-decay');

    // The broken pet's matrix step (level 1 + log row) was rolled back with its unit.
    expect($broken->fresh()->escalation_level)->toBe(0)
        ->and(ActivityLog::where('pet_id', $broken->id)->count())->toBe(0)
        ->and($stale->fresh()->escalation_level)->toBe(0)
        ->and(app(PetLoopGuard::class)->failuresPerStage())->toBe(['escalation' => 1]);
});

it('records the failure in System health once per run', function () {
    config(['system_health.capture_enabled' => true]);
    $broken = glPet();
    glPet();
    GlFailingHygiene::$failFor = $broken->id;

    Artisan::call('pets:process-decay');

    $rows = DB::table('system_errors')->get();
    $exception = $rows->firstWhere('exception_class', RuntimeException::class);
    $summary = $rows->first(fn ($r) => str_starts_with($r->message, 'Game loop: pet processing failed'));
    expect($exception)->not->toBeNull()
        ->and((int) $exception->occurrences)->toBe(1)
        ->and($summary)->not->toBeNull()
        ->and((int) $summary->occurrences)->toBe(1);
});

it('queues one Discord alert per failing pet per day — not every minute', function () {
    config(['services.discord.alerts_webhook_url' => GL_URL]);
    Queue::fake([SendGameLoopPetErrorAlert::class]);
    $broken = glPet();
    GlFailingHygiene::$failFor = $broken->id;

    Artisan::call('pets:process-decay');
    Carbon::setTestNow(now()->addMinute());
    Artisan::call('pets:process-decay');
    Carbon::setTestNow(now()->addMinute());
    Artisan::call('pets:process-decay');

    Queue::assertPushed(SendGameLoopPetErrorAlert::class, 1);
    Queue::assertPushed(SendGameLoopPetErrorAlert::class, fn ($job) => array_keys($job->pets) === [$broken->id]
        && $job->pets[$broken->id] === ['stage' => 'decay', 'exception' => RuntimeException::class]);

    // Next Ljubljana day (00:00 local = 23:00 UTC): one more.
    Carbon::setTestNow(Carbon::parse('2026-11-04 23:01:00', 'UTC'));
    Artisan::call('pets:process-decay');
    Queue::assertPushed(SendGameLoopPetErrorAlert::class, 2);
});

it('posts pet id, stage and exception class only — and releases the claims when Discord fails', function () {
    config(['services.discord.alerts_webhook_url' => GL_URL]);
    Http::fake(['discord.com/api/webhooks/*' => Http::sequence()->push(null, 204)->push(['message' => 'nope'], 400)]);
    $broken = glPet();
    GlFailingHygiene::$failFor = $broken->id;

    Artisan::call('pets:process-decay');

    Http::assertSentCount(1);
    Http::assertSent(function (Request $r) use ($broken): bool {
        $embed = $r['embeds'][0];
        $text = json_encode($r->data());

        return str_contains($embed['footer']['text'], AdminAlertKind::GameLoopPetError->value)
            && $embed['color'] === AdminAlertKind::COLOUR_DANGER
            && $embed['fields'][0]['name'] === __('admin.game_loop.discord.pet', ['id' => $broken->id])
            && $embed['fields'][0]['value'] === 'decay · RuntimeException'
            && ! str_contains($text, 'exploded'); // never the exception message
    });

    // A failed post releases the day's claim → the next run tries again.
    Cache::flush();
    Artisan::call('pets:process-decay');
    Http::assertSentCount(2);
    expect(Cache::has(PetLoopGuard::claimKey($broken->id)))->toBeFalse();
});

it('a stage that throws as a whole does not skip the later stages or the heartbeat', function () {
    $pet = glPet(['escalation_level' => 3, 'last_decay_at' => now()]);
    app()->instance(PetDecayService::class, Mockery::mock(PetDecayService::class, function ($m) {
        $m->shouldReceive('processAllActivePets')->andThrow(new RuntimeException('decay query failed'));
    }));
    $ledger = Mockery::mock(RoutineLedgerService::class);
    $ledger->shouldReceive('closeDueDays')->once()->andReturn(['pets' => 0, 'days' => 0]);
    app()->instance(RoutineLedgerService::class, $ledger);

    expect(Artisan::call('pets:process-decay'))->toBe(1);

    expect($pet->fresh()->escalation_level)->toBe(0)
        ->and(app(SystemHealthService::class)->lastHeartbeat()?->equalTo(now()))->toBeTrue();
});
