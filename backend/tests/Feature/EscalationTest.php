<?php

use App\Models\ActivityLog;
use App\Models\Pet;
use App\Models\QuietHours;
use App\Models\User;
use App\Services\EscalationService;
use App\Services\PetDecayService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Escalation Matrix & Neglect Mechanics Tests
|--------------------------------------------------------------------------
*/

describe('EscalationService - 3-tier escalation matrix', function () {
    it('triggers Phase 1 soft warning when a metric drops to 30%', function () {
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->create([
            'user_id' => $user->id,
            'hunger_level' => 30,
            'thirst_level' => 100,
            'energy_level' => 100,
            'hygiene_level' => 100,
            'escalation_level' => 0,
        ]);

        $service = app(EscalationService::class);
        $service->processPetEscalation($pet);

        $pet->refresh();
        expect($pet->escalation_level)->toBe(1);

        // Should have logged an ignored_warning activity
        $log = ActivityLog::where('pet_id', $pet->id)->where('value', 30)->first();
        expect($log)->not->toBeNull();
    });

    it('triggers Phase 2 critical alert when a metric drops to 10%', function () {
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->create([
            'user_id' => $user->id,
            'hunger_level' => 10,
            'thirst_level' => 100,
            'energy_level' => 100,
            'hygiene_level' => 100,
            'escalation_level' => 0,
        ]);

        $service = app(EscalationService::class);
        $service->processPetEscalation($pet);

        $pet->refresh();
        expect($pet->escalation_level)->toBe(2);
    });

    it('triggers Phase 3 parent alarm when metric is 0% for >1 hour', function () {
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->create([
            'user_id' => $user->id,
            'hunger_level' => 0,
            'thirst_level' => 100,
            'energy_level' => 100,
            'hygiene_level' => 100,
            'escalation_level' => 0,
            'hunger_zero_since' => now()->subHours(2),
        ]);

        $service = app(EscalationService::class);
        $service->processPetEscalation($pet);

        $pet->refresh();
        expect($pet->escalation_level)->toBe(3);
    });

    it('does not trigger Phase 3 if metric has been at 0% for less than 1 hour', function () {
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->create([
            'user_id' => $user->id,
            'hunger_level' => 0,
            'thirst_level' => 100,
            'energy_level' => 100,
            'hygiene_level' => 100,
            'escalation_level' => 0,
            'hunger_zero_since' => now()->subMinutes(30),
        ]);

        $service = app(EscalationService::class);
        $service->processPetEscalation($pet);

        $pet->refresh();
        // Should escalate to Phase 2 (10% threshold) but not Phase 3
        expect($pet->escalation_level)->toBe(2);
    });

    it('resets escalation level when metrics recover', function () {
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->create([
            'user_id' => $user->id,
            'hunger_level' => 80,
            'thirst_level' => 80,
            'energy_level' => 80,
            'hygiene_level' => 80,
            'escalation_level' => 2,
        ]);

        $service = app(EscalationService::class);
        $service->processPetEscalation($pet);

        $pet->refresh();
        expect($pet->escalation_level)->toBe(0);
    });

    it('escalates progressively from Phase 1 to Phase 2', function () {
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->create([
            'user_id' => $user->id,
            'hunger_level' => 25,
            'thirst_level' => 100,
            'energy_level' => 100,
            'hygiene_level' => 100,
            'escalation_level' => 0,
        ]);

        $service = app(EscalationService::class);

        // Phase 1 at 25%
        $service->processPetEscalation($pet);
        expect($pet->fresh()->escalation_level)->toBe(1);

        // Drop to 10% → should escalate to Phase 2
        $pet->update(['hunger_level' => 10]);
        $service->processPetEscalation($pet->fresh());
        expect($pet->fresh()->escalation_level)->toBe(2);
    });
});

describe('EscalationService - illness state', function () {
    it('triggers illness when hygiene is at 0% for >6 hours', function () {
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->create([
            'user_id' => $user->id,
            'hunger_level' => 100,
            'thirst_level' => 100,
            'energy_level' => 100,
            'hygiene_level' => 0,
            'hygiene_zero_since' => now()->subHours(7),
            'illness_until' => null,
        ]);

        $service = app(EscalationService::class);
        $service->processPetEscalation($pet);

        $pet->refresh();
        expect($pet->illness_until)->not->toBeNull();
        expect($pet->pet_state->value)->toBe('sick');
        // Illness lockout is 12 hours
        expect($pet->illness_until->isFuture())->toBeTrue();
    });

    it('does not trigger illness, phase 3 or game over from energy at 0 % (daily walk rule)', function () {
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->create([
            'user_id' => $user->id,
            'hunger_level' => 100,
            'thirst_level' => 100,
            'energy_level' => 0,
            'hygiene_level' => 100,
            'energy_zero_since' => now()->subHours(30),
            'illness_until' => null,
        ]);

        $service = app(EscalationService::class);
        $service->processPetEscalation($pet);

        $pet->refresh();
        expect($pet->illness_until)->toBeNull();
        expect($pet->is_game_over)->toBeFalse();
        expect($pet->escalation_level)->toBe(2); // ≤ 10 % reminder outside quiet hours only
    });

    it('does not trigger illness if metric has been at 0% for less than 6 hours', function () {
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->create([
            'user_id' => $user->id,
            'hygiene_level' => 0,
            'hygiene_zero_since' => now()->subHours(3),
            'illness_until' => null,
        ]);

        $service = app(EscalationService::class);
        $service->processPetEscalation($pet);

        $pet->refresh();
        expect($pet->illness_until)->toBeNull();
    });

    it('does not trigger illness during quiet hours', function () {
        $parent = User::factory()->parent()->create();
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);

        QuietHours::create([
            'parent_id' => $parent->id,
            'school_start' => '00:00',
            'school_end' => '23:59',
            'is_active' => true,
        ]);

        $pet = Pet::factory()->create([
            'user_id' => $child->id,
            'hygiene_level' => 0,
            'hygiene_zero_since' => now()->subHours(7),
            'illness_until' => null,
        ]);

        $service = app(EscalationService::class);
        $service->processPetEscalation($pet);

        $pet->refresh();
        expect($pet->illness_until)->toBeNull();
    });

    it('does not re-trigger illness if pet is already ill', function () {
        $user = User::factory()->child()->create();
        $existingIllnessUntil = now()->addHours(5);

        $pet = Pet::factory()->create([
            'user_id' => $user->id,
            'hygiene_level' => 0,
            'hygiene_zero_since' => now()->subHours(10),
            'illness_until' => $existingIllnessUntil,
            'pet_state' => 'sick',
        ]);

        $service = app(EscalationService::class);
        $result = $service->processPetEscalation($pet);

        // Should not re-trigger (illness already active)
        $pet->refresh();
        expect($pet->illness_until->toIso8601String())->toBe($existingIllnessUntil->toIso8601String());
    });
});

describe('EscalationService - game over / virtual shelter protocol', function () {
    it('triggers game over when any metric is at 0% for 24 continuous hours', function () {
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->create([
            'user_id' => $user->id,
            'hunger_level' => 0,
            'hunger_zero_since' => now()->subHours(25),
            'is_active' => true,
            'is_game_over' => false,
        ]);

        $service = app(EscalationService::class);
        $service->processPetEscalation($pet);

        $pet->refresh();
        expect($pet->is_game_over)->toBeTrue();
        expect($pet->is_active)->toBeFalse();
        expect($pet->escalation_level)->toBe(3);
    });

    it('does not trigger game over if metric has been at 0% for less than 24 hours', function () {
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->create([
            'user_id' => $user->id,
            'hunger_level' => 0,
            'hunger_zero_since' => now()->subHours(20),
            'is_game_over' => false,
        ]);

        $service = app(EscalationService::class);
        $service->processPetEscalation($pet);

        $pet->refresh();
        expect($pet->is_game_over)->toBeFalse();
    });

    it('does not process escalation for game-over pets', function () {
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->create([
            'user_id' => $user->id,
            'is_game_over' => true,
            'is_active' => false,
            'escalation_level' => 3,
        ]);

        $service = app(EscalationService::class);
        $result = $service->processPetEscalation($pet);

        expect($result)->toBeFalse();
    });
});

describe('EscalationService - processAllActivePets', function () {
    it('processes multiple active pets', function () {
        $users = User::factory()->child()->count(3)->create();

        foreach ($users as $user) {
            Pet::factory()->create([
                'user_id' => $user->id,
                'hunger_level' => 25,
            ]);
        }

        $service = app(EscalationService::class);
        $result = $service->processAllActivePets();

        expect($result['processed'])->toBe(3);
        // All 3 pets have hunger at 25% (≤30%) → Phase 1 escalation
        expect($result['escalated'])->toBe(3);
    });
});

/*
| Thresholds follow the displayed (rounded half-up) value — decision
| 2026-10-03: what the child sees is what counts.
*/
function thresholdPet(float $hunger, int $escalationLevel = 0): Pet
{
    return Pet::factory()->create([
        'user_id' => User::factory()->child()->create()->id,
        'hunger_level' => $hunger,
        'thirst_level' => 100,
        'energy_level' => 100,
        'hygiene_level' => 100,
        'escalation_level' => $escalationLevel,
    ]);
}

describe('EscalationService - thresholds use the displayed value', function () {
    it('fires phase 1 at 30.4 (shows 30 %)', function () {
        $pet = thresholdPet(30.4);

        app(EscalationService::class)->processPetEscalation($pet);

        expect($pet->fresh()->escalation_level)->toBe(1);
        expect(ActivityLog::where('pet_id', $pet->id)->where('value', 30)->exists())->toBeTrue();
    });

    it('does not fire phase 1 at 30.5 (shows 31 %)', function () {
        $pet = thresholdPet(30.5);

        app(EscalationService::class)->processPetEscalation($pet);

        expect($pet->fresh()->escalation_level)->toBe(0);
        expect(ActivityLog::where('pet_id', $pet->id)->exists())->toBeFalse();
    });

    it('fires phase 2 at 10.4 (shows 10 %)', function () {
        $pet = thresholdPet(10.4);

        app(EscalationService::class)->processPetEscalation($pet);

        expect($pet->fresh()->escalation_level)->toBe(2);
    });

    it('stays at phase 1 for 10.5 (shows 11 %)', function () {
        $pet = thresholdPet(10.5);

        app(EscalationService::class)->processPetEscalation($pet);

        expect($pet->fresh()->escalation_level)->toBe(1);
    });

    it('does not reset the level while the value still shows 30 %', function () {
        $pet = thresholdPet(30.3, escalationLevel: 1);

        app(EscalationService::class)->processPetEscalation($pet);

        expect($pet->fresh()->escalation_level)->toBe(1);
    });

    it('resets the level once the value shows 31 %', function () {
        $pet = thresholdPet(30.5, escalationLevel: 1);

        app(EscalationService::class)->processPetEscalation($pet);

        expect($pet->fresh()->escalation_level)->toBe(0);
    });
});

describe('Neglect counts from the displayed 0 % (decay + escalation)', function () {
    beforeEach(function () {
        Carbon::setTestNow('2026-10-05 07:00:00');
        seedBreedConfigs();
    });

    it('starts the neglect clocks at 0.4 hygiene: phase 3 after 1 h, illness after 6 h', function () {
        $pet = Pet::factory()->create([
            'user_id' => User::factory()->child()->create()->id,
            'hunger_level' => 100,
            'thirst_level' => 100,
            'energy_level' => 100,
            'hygiene_level' => 0.4,
        ]);
        disableHygieneEvents($pet);
        // Hunger out of the way; hygiene 0.4 would only reach a precise 0
        // after 16 min, so illness at exactly +6 h proves the clock started
        // at the displayed 0 %.
        DB::table('breed_configs')->update(['hunger_decay_rate' => 0]);

        $decay = app(PetDecayService::class);
        $escalation = app(EscalationService::class);
        $tick = function () use ($pet, $decay, $escalation): Pet {
            $fresh = Pet::findOrFail($pet->id);
            $decay->processPetDecay($fresh);
            $escalation->processPetEscalation($fresh->refresh());

            return $fresh->refresh();
        };

        $start = now()->copy();
        Pet::whereKey($pet->id)->update(['last_decay_at' => $start->copy()->subSecond()]);
        $first = $tick();
        expect($first->hygiene_level)->toBeGreaterThan(0.0);
        expect($first->hygiene_zero_since?->equalTo($start))->toBeTrue();

        Carbon::setTestNow($start->copy()->addHour());
        expect($tick()->escalation_level)->toBe(3);

        Carbon::setTestNow($start->copy()->addHours(6));
        expect($tick()->isIll())->toBeTrue();
    });

    it('ends the game after 24 h at a displayed 0 % hunger', function () {
        $pet = Pet::factory()->create([
            'user_id' => User::factory()->child()->create()->id,
            'hunger_level' => 0.3,
            'thirst_level' => 100,
            'energy_level' => 100,
            'hygiene_level' => 100,
            'hunger_zero_since' => now()->subHours(24),
        ]);

        app(EscalationService::class)->processPetEscalation($pet);

        expect($pet->fresh()->is_game_over)->toBeTrue();
    });
});
