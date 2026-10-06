<?php

use App\Models\Pet;
use App\Services\BehaviourEventService;
use App\Services\HygieneEventService;
use Tests\TestCase;

/*
| The suite pins the hygiene / chewing RNG salt (Tests\TestCase): CI makes a
| fresh APP_KEY per run, and the production default salt is the app key, so
| without the pin random event times changed from run to run (flaky
| FamilyModelTest, 2026-10-06).
*/

it('pins the hygiene and chewing RNG salt for the whole suite, whatever APP_KEY is', function () {
    $pet = (new Pet)->forceFill(['id' => 4242]);
    $draws = fn (): array => [
        app(HygieneEventService::class)->randomizerFor($pet, '2026-10-06')->nextFloat(),
        app(BehaviourEventService::class)->randomizerFor($pet, '2026-10-06')->nextFloat(),
    ];

    $first = $draws();
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    app()->forgetInstance(HygieneEventService::class);
    app()->forgetInstance(BehaviourEventService::class);

    expect($draws())->toBe($first)
        ->and($first[0])->toBe((new HygieneEventService(TestCase::RNG_SALT))->randomizerFor($pet, '2026-10-06')->nextFloat())
        // The per-test helpers still override the suite salt.
        ->and(useHygieneSalt('other')->randomizerFor($pet, '2026-10-06')->nextFloat())->not->toBe($first[0])
        ->and(useBehaviourSalt('other')->randomizerFor($pet, '2026-10-06')->nextFloat())->not->toBe($first[1]);
});
