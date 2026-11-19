<?php

use App\Enums\PetStateEnum;
use App\Enums\Species;
use App\Models\PetMedia;
use App\Services\Media\MediaClipAnalyzer;
use App\Services\Media\MediaVisionCheck;
use App\Services\Media\PetAppearancePrompt;
use App\Services\Shop\ItemDesigns;

/*
|--------------------------------------------------------------------------
| Calm sleep clips + bed fit (David 2026-11-19)
|--------------------------------------------------------------------------
| »ne naslednji dan ne poskusi ponovno. moramo to izpilit, da v 3 promptih pride ok.« The bed clip
| of shared look #11 (Staffordshire Bull Terrier puppy) failed 3× — the puppy woke, lifted its
| head and flailed its tail: the prompts ASKED for motion (»clearly visible breathing, an ear
| twitching, a small shift of a paw«, »dreaming twitches«). Sleep clips are now calm, calmer with
| every retry, with a sleep negative prompt; the bed key image sits on the floor with a contact
| shadow under its whole base, and the item rubric fails a floating / too-sharp object.
*/

const CSP_BANNED = ['clearly visible', 'twitches', 'ear twitch', 'shift of a paw', 'dreaming', 'lively'];

function cspTraits(Species $species): array
{
    return $species === Species::Cat ? ['coat_color' => 'blue', 'coat_length' => 'short'] : [];
}

it('every sleep motion (state loop, classic state video, bed clip — dog and cat) is calm, never asks for motion', function () {
    $prompts = app(PetAppearancePrompt::class);

    foreach ([['mutt', Species::Dog], ['british_shorthair', Species::Cat]] as [$breed, $species]) {
        $traits = cspTraits($species);
        $texts = [
            'loopMotion' => PetStateEnum::Sleeping->loopMotion($species),
            'modifier' => PetStateEnum::Sleeping->promptModifier($species),
            'itemClipIntent' => PetAppearancePrompt::itemClipIntent('bed', $species),
        ];
        foreach ([0, 1, 2] as $variant) {
            $texts["loop{$variant}"] = $prompts->loopVideoPrompt($breed, PetStateEnum::Sleeping, $traits, null, $variant);
            $texts["bed{$variant}"] = $prompts->itemClipPrompt($breed, 'bed', 'a round grey bed', $traits, null, $variant);
        }
        $texts['classic'] = $prompts->videoPrompt($breed, PetStateEnum::Sleeping, $traits);

        foreach ($texts as $name => $text) {
            if (str_ends_with($name, '2')) {
                // The last retry: the shortest, most static wording.
                expect($text)->toContain('does not move at all except slow, gentle breathing')->toContain('eyes closed, head resting');
            } else {
                expect($text)->toContain('completely still')->toContain('only slow, gentle, even breathing')
                    ->toContain('the eyes stay closed the whole time')->toContain('no lifting the head');
            }
            foreach (CSP_BANNED as $banned) {
                expect(str_contains(strtolower($text), $banned))->toBeFalse("{$species->value} {$name} contains »{$banned}«");
            }
        }
        if ($species === Species::Cat) {
            expect(strtolower(implode(' ', $texts).$prompts->sleepNegativePrompt(Species::Cat)))->not->toMatch('/\bdog\b/');
        }
    }
});

it('sleep clip variants escalate: 0 calm, 1 + »perfectly still«, 2 the shortest, most static; beyond 2 stays at 2', function () {
    $prompts = app(PetAppearancePrompt::class);
    [$v0, $v1, $v2, $v9] = array_map(fn (int $v) => $prompts->itemClipPrompt('mutt', 'bed', 'a round grey dog bed', [], null, $v), [0, 1, 2, 9]);

    expect($v0)->not->toContain('Perfectly still')->toContain('(a round grey dog bed)')
        ->and($v1)->toContain('Perfectly still, like a photograph in which only the gentle breathing moves')
        ->and($v2)->toStartWith('A still, calm video of the same')->toContain('does not move at all except slow, gentle breathing')
        ->and($v9)->toBe($v2)
        ->and($v0.$v1.$v2)->toContain('The dog stays lying inside the bed')
        ->and(strlen($v2))->toBeLessThan(strlen($v1));

    // Other items keep their wording (variant 2 = variant 1).
    expect($prompts->itemClipPrompt('mutt', 'toy_1', 'a rope toy', [], null, 2))->toBe($prompts->itemClipPrompt('mutt', 'toy_1', 'a rope toy', [], null, 1))
        ->and($prompts->itemClipPrompt('mutt', 'toy_1', 'a rope toy'))->toContain('clearly visible');
});

it('the sleep negative prompt names waking, head lifting, tail and camera movement; other clips keep theirs', function () {
    $prompts = app(PetAppearancePrompt::class);

    foreach ([Species::Dog, Species::Cat] as $species) {
        $neg = $prompts->sleepNegativePrompt($species);
        expect($neg)->toContain('waking up')->toContain('opening eyes')->toContain('lifting the head')->toContain('tail wagging')
            ->toContain('jerky movement')->toContain('sudden motion')->toContain('getting up')->toContain('camera movement')
            ->not->toContain('still image')->not->toContain('frozen frame')
            ->and($prompts->itemClipNegativePrompt($species, 'bed'))->toBe($neg)
            ->and($prompts->itemClipNegativePrompt($species, 'toy_1'))->toBe($prompts->loopNegativePrompt($species))
            ->and($prompts->loopNegativePrompt($species))->toContain('frozen frame');
    }
});

it('the Sleeping loop is calmer on the clip check\'s one automatic regeneration (sleepVariant)', function () {
    $slot = new PetMedia(['generation' => 3]);
    expect($slot->sleepVariant())->toBe(0);
    $slot->quality_retry_generation = 3;
    expect($slot->sleepVariant())->toBe(1);
    $slot->generation = 4;
    expect($slot->sleepVariant())->toBe(0);
});

it('the bed / bowls key image: floor contact along the whole base, same softness as the room; the last try leads with it', function () {
    $prompts = app(PetAppearancePrompt::class);
    $bed = ItemDesigns::description('dog_bed_round_grey');
    [$v0, $v1, $v2] = array_map(fn (int $v) => $prompts->itemImagePrompt('staffordshire_bull_terrier', 'bed', $bed, [], null, true, $v), [0, 1, 2]);

    foreach ([$v0, $v1, $v2] as $p) {
        expect($p)->toContain('The bed\'s bottom edge touches the floor along its whole length and sinks slightly under the dog\'s weight')
            ->toContain('a soft contact shadow lies under the entire base, darkest right where it meets the floor')
            ->toContain('never sharper than the floor around it')->toContain('no bright outline or halo');
    }
    expect($v0)->not->toContain('Most important')->and($v1)->not->toContain('Most important')
        ->and($v2)->toStartWith('Most important: the object stands ON the floor of this room like a real object');

    $bowls = $prompts->itemImagePrompt('mutt', 'bowls', 'two steel bowls', [], null, false, 0);
    expect($bowls)->toContain('Each bowl stands flat on the floor with its whole base touching it')->toContain('never sharper than the floor around them');

    // Toys / treats: no contact sentence, variant 2 = variant 1.
    expect($prompts->itemImagePrompt('mutt', 'toy_1', 'a rope toy', [], null, false, 2))->toBe($prompts->itemImagePrompt('mutt', 'toy_1', 'a rope toy', [], null, false, 1))
        ->and(PetAppearancePrompt::itemContact('toy_1', 'dog'))->toBe('');
});

it('the item rubrics fail a partial contact shadow, a single contact point and an object sharper than its floor; a sleep clip that wakes fails', function () {
    $vision = app(MediaVisionCheck::class);
    $still = $vision->stillPrompt(MediaVisionCheck::RUBRIC_ITEM, Species::Dog, PetAppearancePrompt::itemScene('bed', Species::Dog, 'a round grey dog bed'));
    $clip = $vision->itemClipPrompt(Species::Dog, PetAppearancePrompt::itemClipIntent('bed', Species::Dog), 'lying curled up asleep in a round grey dog bed');
    $petClip = $vision->prompt(Species::Dog, PetStateEnum::Sleeping->promptModifier(), false);

    foreach ([$still, $clip] as $p) {
        expect($p)->toContain('soft contact shadow under the WHOLE base')
            ->toContain('false if the shadow is only at one point or one side, if the object touches the floor at a single point')
            ->toContain('false if the object is noticeably sharper or crisper than the floor it stands on, has a bright outline or halo');
    }
    expect($clip)->toContain('when the dog should stay asleep and still, only gentle breathing is right — false if it wakes up, opens its eyes, lifts its head')
        ->toContain('sound asleep and completely still')->not->toContain('ear twitch')
        ->and($petClip)->toContain('only gentle breathing is right')->toContain('sound asleep and completely still')
        // Still ONE call with the same keys (cheap).
        ->and(MediaVisionCheck::STILL_KEYS[MediaVisionCheck::RUBRIC_ITEM])->toBe(['one_animal', 'legs_ok', 'no_ghost_parts', 'scene_ok', 'clean', 'item_grounded', 'item_integrated', 'scale_ok']);
});

it('a calm sleep clip passes the technical motion check (low-motion threshold for sleeping and the bed)', function () {
    // QA measured a 2 px breathing motion at YDIF ~0.06 — calm breathing; the low-motion minimum is 0.03.
    $m = ['duration' => 5.0, 'frames' => 41, 'diffs' => array_fill(0, 40, 0.06), 'end_ssim' => null];
    $low = MediaClipAnalyzer::evaluate($m, 5.0, false, lowMotion: true);
    $normal = MediaClipAnalyzer::evaluate($m, 5.0, false, lowMotion: false);

    expect(config('media.quality_check.low_motion_states'))->toContain('sleeping')
        ->and(config('media.quality_check.low_motion_items'))->toContain('bed')
        ->and($low['ok'])->toBeTrue()
        ->and($normal['ok'])->toBeFalse();
});
