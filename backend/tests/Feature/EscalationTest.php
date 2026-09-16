<?php

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\Pet;
use App\Models\QuietHours;
use App\Models\User;
use App\Services\EscalationService;
use App\Services\PetDecayService;

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

    it('triggers illness when energy is at 0% for >6 hours', function () {
        $user = User::factory()->child()->create();
        $pet = Pet::factory()->create([
            'user_id' => $user->id,
            'hunger_level' => 100,
            'thirst_level' => 100,
            'energy_level' => 0,
            'hygiene_level' => 100,
            'energy_zero_since' => now()->subHours(7),
            'illness_until' => null,
        ]);

        $service = app(EscalationService::class);
        $service->processPetEscalation($pet);

        $pet->refresh();
        expect($pet->illness_until)->not->toBeNull();
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
