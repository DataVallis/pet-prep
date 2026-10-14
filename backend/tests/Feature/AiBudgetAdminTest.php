<?php

use App\Enums\AiCallFailure;
use App\Enums\AiSpendPurpose;
use App\Filament\Pages\AiBudget;
use App\Jobs\SubmitPetStateVideo;
use App\Models\AiSpendLedger;
use App\Models\AppSetting;
use App\Models\AppSettingChange;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Models\User;
use App\Services\AppSettingsService;
use App\Services\Media\AiCallException;
use App\Services\Media\AiSpendGuard;
use App\Services\Media\FalGateway;
use App\Services\Media\MediaProfiles;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| M4-07b — production AI budget in the superadmin panel (David 2026-10-14)
|--------------------------------------------------------------------------
|
| app_settings `ai_budget` = {daily_usd, monthly_usd}; null / no row → the
| env cap. AiSpendGuard reads it for every production fal call (reserve() is
| the real gate). /admin/ai-budget: superadmin only, audited, plus "retry
| blocked media now" (= the nightly media:retry, within today's budget).
|
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-14 14:00:00', 'UTC'));
    Http::preventStrayRequests();
    Storage::fake('pet_media');
    Cache::forget(FalGateway::BALANCE_FLAG_KEY);
    config([
        'services.fal_ai.key' => 'test-key',
        'media.reference_image_profile' => 'nano_banana_pro',
        'media.state_video_profile' => 'kling_v3_pro',
        'media.budget.daily_usd' => 5.0,
        'media.budget.monthly_usd' => 50.0,
        'media.budget.timezone' => 'UTC',
    ]);
    seedBreedConfigs();
});

function abAdmin(): User
{
    return User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
}

function abSpend(float $usd, string $purpose = 'state_video'): void
{
    AiSpendLedger::create(['purpose' => $purpose, 'profile' => 'kling_v3_pro', 'endpoint' => 'x/y', 'unit' => 'second', 'units' => 5, 'cost_usd' => $usd, 'status' => 'committed']);
}

/** A purchased pet with a stored reference image and one video refused by the daily budget. */
function abBlockedVideo(): PetMedia
{
    $parent = createParentUser();
    $child = createChildUser(['parent_id' => $parent->id]);
    $pet = Pet::factory()->purchased()->create(['user_id' => $child->id, 'breed_type' => 'bulldog', 'media_status' => 'ready']);
    Storage::disk('pet_media')->put("{$pet->id}/reference-g1.jpg", 'jpg');
    PetMedia::create(['pet_id' => $pet->id, 'kind' => 'image', 'status' => 'ready', 'generation' => 1, 'storage_path' => "{$pet->id}/reference-g1.jpg", 'mime' => 'image/jpeg', 'bytes' => 3]);

    return PetMedia::create(['pet_id' => $pet->id, 'kind' => 'video', 'state' => 'idle', 'status' => 'failed', 'error_reason' => 'budget_daily']);
}

describe('settings + guard', function () {
    it('uses the env caps while nothing is set in the admin', function () {
        $guard = app(AiSpendGuard::class);

        expect(app(AppSettingsService::class)->storedAiBudget())->toBe(['daily_usd' => null, 'monthly_usd' => null])
            ->and($guard->dailyCapUsd())->toBe(5.0)
            ->and($guard->monthlyCapUsd())->toBe(50.0);
    });

    it('lets the admin value override the env cap and go back to it with an empty value', function () {
        $admin = abAdmin();
        $settings = app(AppSettingsService::class);

        $settings->updateAiBudget($admin, 20.0, null);
        expect(app(AiSpendGuard::class))->dailyCapUsd()->toBe(20.0)->monthlyCapUsd()->toBe(50.0);

        $settings->updateAiBudget($admin, null, 300.0);
        expect(app(AiSpendGuard::class))->dailyCapUsd()->toBe(5.0)->monthlyCapUsd()->toBe(300.0)
            ->and(AppSettingChange::where('key', AppSettingsService::AI_BUDGET_KEY)->count())->toBe(2)
            ->and(AppSettingChange::latest('id')->first())
            ->old->toBe(['daily_usd' => 20.0, 'monthly_usd' => null])
            ->new->toBe(['daily_usd' => null, 'monthly_usd' => 300.0]);
    });

    it('records no change when the same budget is saved again', function () {
        $admin = abAdmin();
        app(AppSettingsService::class)->updateAiBudget($admin, 12.5, 100.0);
        app(AppSettingsService::class)->updateAiBudget($admin, 12.5, 100.0);

        expect(AppSettingChange::count())->toBe(1);
    });

    it('refuses negative or absurd values and stores nothing', function (?float $daily, ?float $monthly) {
        expect(fn () => app(AppSettingsService::class)->updateAiBudget(abAdmin(), $daily, $monthly))
            ->toThrow(InvalidArgumentException::class);

        expect(AppSetting::count())->toBe(0)->and(AppSettingChange::count())->toBe(0);
    })->with([
        'negative daily' => [-1.0, null],
        'daily above the ceiling' => [AppSettingsService::AI_BUDGET_MAX_DAILY_USD + 0.01, null],
        'monthly above the ceiling' => [null, AppSettingsService::AI_BUDGET_MAX_MONTHLY_USD + 1],
        'not finite' => [INF, null],
    ]);

    it('is the real gate: a fal call refused at the env cap is allowed after raising the budget', function () {
        abSpend(4.9);
        $profile = app(MediaProfiles::class)->stateVideo();

        expect(fn () => app(AiSpendGuard::class)->reserve($profile, AiSpendPurpose::StateVideo))
            ->toThrow(AiCallException::class);

        app(AppSettingsService::class)->updateAiBudget(abAdmin(), 20.0, null);

        expect(app(AiSpendGuard::class)->reserve($profile, AiSpendPurpose::StateVideo))->status->toBe(AiSpendLedger::STATUS_RESERVED);
    });

    it('can stop all production AI calls with 0, while the AI Lab budget is untouched', function () {
        config(['media.lab.daily_usd' => 3.0, 'media.lab.monthly_usd' => 30.0]);
        app(AppSettingsService::class)->updateAiBudget(abAdmin(), 0.0, null);
        $guard = app(AiSpendGuard::class);

        expect($guard->refusalFor(0.01, AiSpendPurpose::ReferenceImage))->toBe(AiCallFailure::BudgetDaily)
            ->and($guard->refusalFor(0.01, AiSpendPurpose::Lab))->toBeNull();
    });

    it('applies a change at once (the cached value is forgotten on save)', function () {
        $settings = app(AppSettingsService::class);
        expect(app(AiSpendGuard::class)->dailyCapUsd())->toBe(5.0); // cached "no row"

        $settings->updateAiBudget(abAdmin(), 25.0, null);

        expect(app(AiSpendGuard::class)->dailyCapUsd())->toBe(25.0);
    });
});

describe('admin page', function () {
    it('is only for superadmins', function () {
        $this->actingAs(abAdmin())->get('/admin/ai-budget')->assertOk()->assertSee('AI budget');
        $this->actingAs(User::factory()->parent()->create())->get('/admin/ai-budget')->assertForbidden();
    });

    it('saves the budget from the form and audits who changed it', function () {
        $admin = abAdmin();
        $this->actingAs($admin);

        Livewire::test(AiBudget::class)
            ->assertFormSet(['daily_usd' => null, 'monthly_usd' => null])
            ->fillForm(['daily_usd' => '20', 'monthly_usd' => ''])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        expect(app(AppSettingsService::class)->storedAiBudget())->toBe(['daily_usd' => 20.0, 'monthly_usd' => null])
            ->and(AppSettingChange::sole()->user_id)->toBe($admin->id);

        Livewire::test(AiBudget::class)->assertFormSet(['daily_usd' => 20.0, 'monthly_usd' => null]);
    });

    it('rejects invalid form values', function () {
        $this->actingAs(abAdmin());

        Livewire::test(AiBudget::class)
            ->fillForm(['daily_usd' => '-5'])
            ->call('save')
            ->assertHasFormErrors(['daily_usd']);

        Livewire::test(AiBudget::class)
            ->fillForm(['monthly_usd' => '999999'])
            ->call('save')
            ->assertHasFormErrors(['monthly_usd']);

        expect(AppSetting::count())->toBe(0);
    });

    it('re-queues media blocked by the budget at once after raising it', function () {
        Queue::fake([SubmitPetStateVideo::class]);
        $slot = abBlockedVideo();
        abSpend(4.9);
        $this->actingAs(abAdmin());

        // Still at the env cap: nothing fits today.
        Livewire::test(AiBudget::class)->call('retryBlocked')->assertNotified();
        Queue::assertNothingPushed();

        Livewire::test(AiBudget::class)->fillForm(['daily_usd' => '20'])->call('save');
        Livewire::test(AiBudget::class)->call('retryBlocked')->assertNotified();

        Queue::assertPushed(SubmitPetStateVideo::class, fn ($job) => $job->petMediaId === $slot->id);
        expect($slot->fresh())->status->toBe('pending')->error_reason->toBeNull();
    });

    it('queues nothing while the fal balance is marked as exhausted', function () {
        Queue::fake([SubmitPetStateVideo::class]);
        abBlockedVideo();
        Cache::put(FalGateway::BALANCE_FLAG_KEY, now()->toIso8601String());
        $this->actingAs(abAdmin());

        Livewire::test(AiBudget::class)->call('retryBlocked')->assertNotified();

        Queue::assertNothingPushed();
    });

    it('blocks non-superadmins from saving or retrying through Livewire', function () {
        $this->actingAs(User::factory()->parent()->create());

        Livewire::test(AiBudget::class)->assertForbidden();
        expect(AppSetting::count())->toBe(0);
    });
});
