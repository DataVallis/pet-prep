<?php

use App\Console\Commands\CheckDiskSpaceCommand;
use App\Enums\AdminAlertKind;
use App\Filament\Widgets\SystemHealth\SystemHealthStats;
use App\Jobs\SendDiskSpaceAlert;
use App\Models\User;
use App\Services\SystemHealth\DiskSpace;
use App\Services\SystemHealth\SystemHealthService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| DEPLOYMENT.md D31 — disk alert (incident 2026-11-04: root disk 100 %)
|--------------------------------------------------------------------------
|
| `system-health:disk-check` (hourly) posts ONE Discord `disk_space` alert per
| Europe/Ljubljana day when the root disk is ≥ 80 %, numbers only; a failed post
| releases the claim so the next hourly run retries. System health shows the tile
| yellow from 80 %, red from 90 %.
|
*/

const DS_URL = 'https://discord.com/api/webhooks/123456/secret-token-abc';
const DS_GB = 1024 ** 3;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-04 10:00:00', 'UTC'));
    Http::preventStrayRequests();
    Sleep::fake();
    config([
        'services.discord.alerts_webhook_url' => DS_URL,
        'system_health.disk_alert_percent' => 80,
        'system_health.disk_warn_percent' => 90,
        'system_health.disk_alert_timezone' => 'Europe/Ljubljana',
    ]);
    Cache::flush();
});

/** Binds a DiskSpace that reports $usedPercent of a 40 GB disk (null = size unknown). */
function dsDisk(?float $usedPercent): void
{
    app()->instance(DiskSpace::class, new class($usedPercent) extends DiskSpace
    {
        public function __construct(private readonly ?float $used) {}

        protected function freeBytes(string $path): ?float
        {
            return $this->used === null ? null : 40 * DS_GB * (1 - $this->used / 100);
        }

        protected function totalBytes(string $path): ?float
        {
            return $this->used === null ? null : 40.0 * DS_GB;
        }
    });
}

function dsFakeDiscord(int $status = 204): void
{
    Http::fake(['discord.com/api/webhooks/*' => Http::response($status === 204 ? null : ['message' => 'nope'], $status)]);
}

it('posts one danger alert with numbers only when the disk is at 80 % or more', function () {
    dsDisk(85.0);
    dsFakeDiscord();

    Artisan::call('system-health:disk-check');

    Http::assertSentCount(1);
    Http::assertSent(function (Request $r): bool {
        $embed = $r['embeds'][0];
        $fields = collect($embed['fields'])->pluck('value', 'name')->all();

        return $embed['color'] === AdminAlertKind::COLOUR_DANGER
            && str_ends_with($embed['url'], '/admin/system-health')
            && str_contains($embed['footer']['text'], 'disk_space')
            && str_contains($embed['title'], '85.0 %')
            && $fields[__('admin.disk_space.discord.used')] === '85.0 %'
            && $fields[__('admin.disk_space.discord.threshold')] === '80 %'
            && $fields[__('admin.disk_space.discord.total')] === '40.0 GB'
            && $fields[__('admin.disk_space.discord.free')] === '6.0 GB';
    });
});

it('posts at most once per Ljubljana day and again on the next day', function () {
    dsDisk(91.0);
    dsFakeDiscord();

    Artisan::call('system-health:disk-check');
    Carbon::setTestNow(Carbon::parse('2026-11-04 22:30:00', 'UTC')); // 23:30 in Ljubljana, same day
    Artisan::call('system-health:disk-check');
    Http::assertSentCount(1);

    Carbon::setTestNow(Carbon::parse('2026-11-04 23:30:00', 'UTC')); // 00:30 on 5. 11. in Ljubljana
    Artisan::call('system-health:disk-check');
    Http::assertSentCount(2);
});

it('sends nothing below the threshold', function () {
    dsDisk(79.9);
    dsFakeDiscord();

    Artisan::call('system-health:disk-check');

    Http::assertNothingSent();
    expect(Cache::has(CheckDiskSpaceCommand::claimKey()))->toBeFalse();
});

it('sends nothing and claims nothing without a Discord webhook', function () {
    config(['services.discord.alerts_webhook_url' => '']);
    dsDisk(99.0);
    Queue::fake();

    Artisan::call('system-health:disk-check');

    Queue::assertNothingPushed();
    expect(Cache::has(CheckDiskSpaceCommand::claimKey()))->toBeFalse();
});

it('does nothing when the disk size is unknown', function () {
    dsDisk(null);
    Queue::fake();

    expect(Artisan::call('system-health:disk-check'))->toBe(0);
    Queue::assertNothingPushed();
});

it('a failed post releases the day so the next hourly check retries', function () {
    dsDisk(88.0);
    dsFakeDiscord(500);

    Artisan::call('system-health:disk-check');
    expect(Cache::has(CheckDiskSpaceCommand::claimKey()))->toBeFalse();

    dsFakeDiscord();
    Artisan::call('system-health:disk-check');
    Http::assertSentCount(2);
    expect(Cache::has(CheckDiskSpaceCommand::claimKey()))->toBeTrue();
});

it('queues the alert on the low-priority alerts queue', function () {
    dsDisk(82.0);
    Queue::fake();

    Artisan::call('system-health:disk-check');

    Queue::assertPushedOn('alerts', SendDiskSpaceAlert::class);
});

it('is scheduled hourly', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'system-health:disk-check'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('11 * * * *');
});

it('System health reads the disk through DiskSpace and colours the tile by the thresholds', function (float $used, string $colour) {
    dsDisk($used);
    $this->actingAs(User::factory()->create(['role' => 'parent', 'is_superadmin' => true]));

    expect(app(SystemHealthService::class)->diskUsage()['used_percent'])->toBe($used);

    $component = Livewire::test(SystemHealthStats::class);
    $disk = collect((fn () => $this->getStats())->call($component->instance()))
        ->first(fn ($s) => $s->getLabel() === __('admin.system_health.stats.disk'));

    expect($disk->getColor())->toBe($colour)
        ->and((string) $disk->getDescription())->toContain(__('admin.system_health.stats.disk_alert', ['percent' => 80]));
})->with([
    'healthy' => [48.0, 'success'],
    'alert' => [80.0, 'warning'],
    'danger' => [93.5, 'danger'],
]);
