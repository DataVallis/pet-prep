<?php

use App\Filament\Resources\BreedConfigResource\Pages\CreateBreedConfig;
use App\Filament\Resources\BreedConfigResource\Pages\EditBreedConfig;
use App\Filament\Resources\BreedConfigResource\Pages\ListBreedConfigs;
use App\Models\BreedConfig;
use App\Models\Pet;
use App\Models\User;
use App\Services\HygieneEventService;
use App\Services\PetActivityService;
use App\Services\PetDecayService;
use Database\Seeders\BreedConfigsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Breed config owns every tunable (M1-06)
|--------------------------------------------------------------------------
|
| breed_configs: daily_steps_required, hunger/thirst decay rates,
| poops_per_day, feed_windows, water_times_per_day, water_min_gap_minutes.
| Services must read these values — changing a row changes the game.
*/

function configuredPet(string $breed = 'mutt'): Pet
{
    seedBreedConfigs();
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->create(['user_id' => $child->id, 'breed_type' => $breed]));
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-05 07:00:00');
});

describe('BreedConfigsSeeder', function () {
    it('seeds the spec values for both breeds', function () {
        seedBreedConfigs();

        $mutt = BreedConfig::where('breed_slug', 'mutt')->firstOrFail();
        $collie = BreedConfig::where('breed_slug', 'border-collie')->firstOrFail();

        expect([
            $mutt->daily_steps_required, $mutt->hunger_decay_rate, $mutt->thirst_decay_rate, $mutt->poops_per_day,
            $mutt->feed_windows, $mutt->water_times_per_day, $mutt->water_min_gap_minutes, $mutt->premium_unlock,
        ])->toBe([4000, 8.0, 10.0, 1, [['06:00', '10:00'], ['17:00', '21:00']], 3, 180, false]);

        expect([
            $collie->daily_steps_required, $collie->hunger_decay_rate, $collie->thirst_decay_rate, $collie->poops_per_day,
            $collie->feed_windows, $collie->water_times_per_day, $collie->water_min_gap_minutes, $collie->premium_unlock,
        ])->toBe([10000, 12.0, 15.0, 2, [['06:00', '10:00'], ['17:00', '21:00']], 3, 180, true]);
    });

    it('is insert-only: never overwrites an existing breed, only adds missing ones', function () {
        seedBreedConfigs();
        $createdAt = BreedConfig::where('breed_slug', 'mutt')->firstOrFail()->getRawOriginal('created_at');
        BreedConfig::where('breed_slug', 'mutt')->update(['thirst_decay_rate' => 99]);
        BreedConfig::where('breed_slug', 'border-collie')->delete();

        Carbon::setTestNow('2026-10-06 07:00:00');
        (new BreedConfigsSeeder)->run();

        // 9 dogs (M5-R10 Labrador, M5-R10-02 Golden, M5-R10-03 French Bulldog, M5-R10-04 German Shepherd, M5-R10-05 Cavalier, M5-R10-06 Beagle, M5-R10-07 Standard Poodle) + 2 cats (M5-R06-01).
        expect(BreedConfig::count())->toBe(11);
        $mutt = BreedConfig::where('breed_slug', 'mutt')->firstOrFail();
        expect($mutt->thirst_decay_rate)->toBe(99.0);
        expect(BreedConfig::where('breed_slug', 'border-collie')->firstOrFail()->thirst_decay_rate)->toBe(15.0);
        expect($mutt->getRawOriginal('created_at'))->toBe($createdAt);
    });

    it('gives a row inserted without the new columns the mutt defaults (migration backfill)', function () {
        DB::table('breed_configs')->insert([
            'breed_slug' => 'legacy', 'daily_steps_required' => 5000, 'hunger_decay_rate' => 8,
            'premium_unlock' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $legacy = BreedConfig::where('breed_slug', 'legacy')->firstOrFail();
        expect($legacy->thirst_decay_rate)->toBe(10.0);
        expect($legacy->poops_per_day)->toBe(1);
        expect($legacy->feed_windows)->toBe(BreedConfig::DEFAULT_FEED_WINDOWS);
        expect($legacy->water_times_per_day)->toBe(3);
        expect($legacy->water_min_gap_minutes)->toBe(180);
    });

    it('rejects invalid tunables at the database level', function (array $values) {
        seedBreedConfigs();

        expect(fn () => DB::table('breed_configs')->where('breed_slug', 'mutt')->update($values))
            ->toThrow(QueryException::class);
    })->with([
        'negative thirst rate' => [['thirst_decay_rate' => -1]],
        'too many poops' => [['poops_per_day' => 11]],
        'negative water gap' => [['water_min_gap_minutes' => -5]],
        'feed windows not a list' => [['feed_windows' => '{"a": 1}']],
    ]);
});

describe('Services read the breed config (no hard-coded rates)', function () {
    it('decays thirst at the configured rate', function () {
        $pet = configuredPet();
        BreedConfig::where('breed_slug', 'mutt')->update(['thirst_decay_rate' => 20]);

        Carbon::setTestNow(now()->addHour());
        app(PetDecayService::class)->processPetDecay($pet);

        expect($pet->fresh()->thirst_level)->toEqualWithDelta(80.0, 1e-9);
    });

    it('decays thirst at 15 %/h for a border collie from the seeded row', function () {
        $pet = configuredPet('border_collie');

        Carbon::setTestNow(now()->addHours(2));
        app(PetDecayService::class)->processPetDecay($pet);

        expect($pet->fresh()->thirst_level)->toEqualWithDelta(70.0, 1e-9);
        expect($pet->fresh()->hunger_level)->toEqualWithDelta(76.0, 1e-9);
    });

    it('decays hunger at the configured rate', function () {
        $pet = configuredPet();
        BreedConfig::where('breed_slug', 'mutt')->update(['hunger_decay_rate' => 2.5]);

        Carbon::setTestNow(now()->addHours(4));
        app(PetDecayService::class)->processPetDecay($pet);

        expect($pet->fresh()->hunger_level)->toEqualWithDelta(90.0, 1e-9);
    });

    it('schedules the configured number of hygiene events per day', function () {
        $pet = configuredPet();
        BreedConfig::where('breed_slug', 'mutt')->update(['poops_per_day' => 5]);

        $times = app(HygieneEventService::class)->scheduleDay($pet, '2026-10-06', null, BreedConfig::forBreed($pet->breed_type)->poops_per_day);

        expect($times)->toHaveCount(5);
    });

    it('computes energy from the configured daily step goal', function () {
        $pet = configuredPet();
        BreedConfig::where('breed_slug', 'mutt')->update(['daily_steps_required' => 2000]);
        Pet::whereKey($pet->id)->update(['energy_level' => 0]);

        $result = app(PetActivityService::class)->recordSteps($pet, 500, now());

        expect($result->energyLevel)->toBe(25);
    });
});

describe('Filament BreedConfigResource', function () {
    beforeEach(function () {
        seedBreedConfigs();
        actingAs(User::factory()->create(['role' => 'parent', 'is_superadmin' => true]));
    });

    it('lists the new tunables', function () {
        Livewire::test(ListBreedConfigs::class)
            ->assertCanSeeTableRecords(BreedConfig::all())
            ->assertSee('06:00–10:00, 17:00–21:00');
    });

    it('shows and saves every tunable, including feeding windows', function () {
        $mutt = BreedConfig::where('breed_slug', 'mutt')->firstOrFail();

        $component = Livewire::test(EditBreedConfig::class, ['record' => $mutt->getRouteKey()])
            ->assertFormSet([
                'thirst_decay_rate' => 10.0,
                'poops_per_day' => 1,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
            ]);

        expect(array_values($component->get('data.feed_windows')))
            ->toBe([['start' => '06:00', 'end' => '10:00'], ['start' => '17:00', 'end' => '21:00']]);

        $component->set('data.feed_windows', ['a' => ['start' => '07:00', 'end' => '09:30']])
            ->fillForm([
                'thirst_decay_rate' => 12.5,
                'poops_per_day' => 2,
                'water_times_per_day' => 4,
                'water_min_gap_minutes' => 120,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $mutt->refresh();
        expect($mutt->thirst_decay_rate)->toBe(12.5);
        expect($mutt->poops_per_day)->toBe(2);
        expect($mutt->water_times_per_day)->toBe(4);
        expect($mutt->water_min_gap_minutes)->toBe(120);
        expect($mutt->feed_windows)->toBe([['07:00', '09:30']]);
    });

    it('validates feeding window times', function () {
        $mutt = BreedConfig::where('breed_slug', 'mutt')->firstOrFail();

        Livewire::test(EditBreedConfig::class, ['record' => $mutt->getRouteKey()])
            ->set('data.feed_windows', ['a' => ['start' => '25:00', 'end' => '09:30']])
            ->call('save')
            ->assertHasFormErrors(['feed_windows.a.start']);

        expect($mutt->fresh()->feed_windows)->toBe(BreedConfig::DEFAULT_FEED_WINDOWS);
    });

    it('requires at least one feeding window', function () {
        $mutt = BreedConfig::where('breed_slug', 'mutt')->firstOrFail();

        Livewire::test(EditBreedConfig::class, ['record' => $mutt->getRouteKey()])
            ->set('data.feed_windows', [])
            ->call('save')
            ->assertHasFormErrors(['feed_windows']);

        expect($mutt->fresh()->feed_windows)->toBe(BreedConfig::DEFAULT_FEED_WINDOWS);
    });

    it('rejects overlapping feeding windows, also over midnight', function (array $windows) {
        $mutt = BreedConfig::where('breed_slug', 'mutt')->firstOrFail();

        Livewire::test(EditBreedConfig::class, ['record' => $mutt->getRouteKey()])
            ->set('data.feed_windows', $windows)
            ->call('save')
            ->assertHasFormErrors(['feed_windows']);

        expect($mutt->fresh()->feed_windows)->toBe(BreedConfig::DEFAULT_FEED_WINDOWS);
    })->with([
        'plain overlap' => [['a' => ['start' => '06:00', 'end' => '10:00'], 'b' => ['start' => '09:00', 'end' => '12:00']]],
        'contained' => [['a' => ['start' => '06:00', 'end' => '21:00'], 'b' => ['start' => '17:00', 'end' => '18:00']]],
        'overnight overlaps morning' => [['a' => ['start' => '22:00', 'end' => '07:00'], 'b' => ['start' => '06:00', 'end' => '10:00']]],
    ]);

    it('accepts touching and overnight windows that do not overlap', function () {
        $mutt = BreedConfig::where('breed_slug', 'mutt')->firstOrFail();

        Livewire::test(EditBreedConfig::class, ['record' => $mutt->getRouteKey()])
            ->set('data.feed_windows', [
                'a' => ['start' => '06:00', 'end' => '10:00'],
                'b' => ['start' => '10:00', 'end' => '12:00'],
                'c' => ['start' => '22:00', 'end' => '02:00'],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($mutt->fresh()->feed_windows)->toBe([['06:00', '10:00'], ['10:00', '12:00'], ['22:00', '02:00']]);
    });

    it('creates a breed with all tunables', function () {
        Livewire::test(CreateBreedConfig::class)
            ->fillForm([
                // Not an enum breed (the Beagle became one in M5-R10-06).
                'breed_slug' => 'test-hound',
                'daily_steps_required' => 7000,
                'hunger_decay_rate' => 9,
                'thirst_decay_rate' => 11,
                'poops_per_day' => 1,
                'water_times_per_day' => 3,
                'water_min_gap_minutes' => 180,
            ])
            ->set('data.feed_windows', ['a' => ['start' => '06:30', 'end' => '09:00']])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = BreedConfig::where('breed_slug', 'test-hound')->firstOrFail();
        expect($created->thirst_decay_rate)->toBe(11.0);
        expect($created->feed_windows)->toBe([['06:30', '09:00']]);
    });
});
