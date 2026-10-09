<?php

use App\Enums\BreedType;
use App\Enums\LifeStage;
use App\Enums\PetOrigin;
use App\Enums\PetStateEnum;
use App\Enums\Species;
use App\Models\Pet;
use App\Services\Media\MediaEntitlementService;
use App\Services\Media\PetAppearancePrompt;
use App\Services\Media\PetDnaService;

/*
|--------------------------------------------------------------------------
| M5-R06-07 — dog AI prompts are byte-identical (regression snapshot)
|--------------------------------------------------------------------------
|
| `tests/Fixtures/dog_media_prompts_snapshot.json` was recorded on `main`
| (a7b088f, after M5-R06-06b, before any M5-R06-07 change) with
| PETPREP_WRITE_SNAPSHOT=1. It holds every prompt a dog can get: the dog
| breed appearance config, deterministic DNA v2 samples (traits + image
| prompt), reference image prompts per stage × origin × DNA v1 / v2, the
| stage growth edit prompt, the legacy v1 prompt, every dog state video
| prompt (with and without traits), the negative prompts, the stage /
| origin cues and the state video sets per tier. Every value must stay
| byte-identical when cats get their own templates.
*/

const DMP_FIXTURE = __DIR__.'/../Fixtures/dog_media_prompts_snapshot.json';

/** The six classic states + the dog behaviour videos (what a dog can get). */
function dmpDogStates(): array
{
    return [PetStateEnum::Idle, PetStateEnum::Sleeping, PetStateEnum::LowEnergy, PetStateEnum::Hungry,
        PetStateEnum::Sick, PetStateEnum::Playing, PetStateEnum::Accident, PetStateEnum::Chewing];
}

/** An unsaved dog with the given DNA / stage / origin (prompts read only these). */
function dmpPet(BreedType $breed, ?array $dna, ?LifeStage $stage, ?PetOrigin $origin): Pet
{
    return (new Pet)->forceFill([
        'breed_type' => $breed->value,
        'pet_dna' => $dna,
        'life_stage' => $stage?->value,
        'origin' => $origin?->value,
    ]);
}

/** @return array<string, mixed> */
function dmpRender(): array
{
    $prompts = app(PetAppearancePrompt::class);
    $dna = app(PetDnaService::class);
    $out = [];

    foreach ([BreedType::Mutt, BreedType::BorderCollie] as $breed) {
        $key = $breed->value;
        $out["config|{$key}"] = config("breed_appearance.{$key}");

        $v1 = ['seed' => 7, 'prompt_anchor' => "Legacy anchor for {$key}, photorealistic", 'visual_traits' => ['eye_color' => 'amber']];

        foreach ([1, 42, 4_000_000_000] as $seed) {
            $sample = $dna->sample($key, $seed);
            $out["sample|{$key}|{$seed}"] = $sample;
            $out["image|{$key}|{$seed}"] = $prompts->imagePrompt($key, $sample['traits']);
            $out["describe|{$key}|{$seed}"] = $prompts->describe($key, $sample['traits']);

            $v2 = $dna->dnaFrom($key, $seed, $sample);
            foreach ([null, ...LifeStage::ordered()] as $stage) {
                foreach ([null, PetOrigin::Bought, PetOrigin::Adopted] as $origin) {
                    $s = $stage?->value ?? '-';
                    $o = $origin?->value ?? '-';
                    $out["pet_image|{$key}|{$seed}|{$s}|{$o}"] = $prompts->imagePromptForPet(dmpPet($breed, $v2, $stage, $origin));
                    if ($stage !== null) {
                        $out["stage_edit|{$key}|{$seed}|{$s}|{$o}"] = $prompts->stageEditPrompt(dmpPet($breed, $v2, $stage, $origin), $stage);
                    }
                }
            }

            foreach (dmpDogStates() as $state) {
                $out["video|{$key}|{$seed}|{$state->value}"] = $prompts->videoPrompt($key, $state, $sample['traits']);
            }
        }

        foreach ([null, ...LifeStage::ordered()] as $stage) {
            foreach ([null, PetOrigin::Bought, PetOrigin::Adopted] as $origin) {
                $s = $stage?->value ?? '-';
                $o = $origin?->value ?? '-';
                $out["v1_image|{$key}|{$s}|{$o}"] = $prompts->imagePromptForPet(dmpPet($breed, $v1, $stage, $origin));
                $out["v1_legacy|{$key}|{$s}|{$o}"] = $prompts->legacyPromptForPet(dmpPet($breed, $v1, $stage, $origin));
                if ($stage !== null) {
                    $out["v1_stage_edit|{$key}|{$s}|{$o}"] = $prompts->stageEditPrompt(dmpPet($breed, $v1, $stage, $origin), $stage);
                }
            }
        }

        foreach (dmpDogStates() as $state) {
            $out["video_no_traits|{$key}|{$state->value}"] = $prompts->videoPrompt($key, $state);
        }
    }

    foreach (dmpDogStates() as $state) {
        $out["state|{$state->value}|modifier"] = $state->promptModifier();
        $out["state|{$state->value}|description"] = $state->description();
    }

    foreach (LifeStage::ordered() as $stage) {
        $out["stage_cue|{$stage->value}"] = $stage->promptCue();
        $out["stage_cue_dog|{$stage->value}"] = $stage->promptCue(Species::Dog);
    }

    $out['origin_cue|bought'] = PetOrigin::Bought->promptCue();
    $out['origin_cue|adopted'] = PetOrigin::Adopted->promptCue();
    $out['negative'] = $prompts->negativePrompt();
    $out['video_negative'] = $prompts->videoNegativePrompt();
    $out['tier|basic'] = array_map(fn (PetStateEnum $s) => $s->value, MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_BASIC));

    return $out;
}

it('keeps every dog AI prompt byte-identical (M5-R06-07)', function () {
    $actual = dmpRender();

    if (getenv('PETPREP_WRITE_SNAPSHOT') === '1') {
        @mkdir(dirname(DMP_FIXTURE), 0777, true);
        file_put_contents(DMP_FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        $this->markTestSkipped('Snapshot written to '.DMP_FIXTURE);
    }

    expect(file_exists(DMP_FIXTURE))->toBeTrue('Record the fixture first: PETPREP_WRITE_SNAPSHOT=1');
    $expected = json_decode((string) file_get_contents(DMP_FIXTURE), true);

    // Not a vacuous snapshot: the dog sentences are in it.
    expect($expected['state|chewing|modifier'])->toContain('slipper')
        ->and($expected['negative'])->toStartWith('cartoon, illustration')
        ->and($expected['image|mutt|42'])->toStartWith('A photorealistic photograph of a single medium-sized')
        ->and(count($expected))->toBeGreaterThan(250);

    expect(json_decode(json_encode($actual), true))->toBe($expected);
});
