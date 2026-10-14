<?php

use App\Enums\BreedType;
use App\Services\BreedSuitability;

/*
|--------------------------------------------------------------------------
| M5-R10 — "za koga je primerna": config/breed_suitability.php
|--------------------------------------------------------------------------
| Every tag is a vocabulary key of its kind, backed by source ids listed in
| docs/research/dog-data/sources.md and by data.json entries that cite them.
*/

/** Source ids (S1, S2, …) listed in sources.md. */
function bsSourceIds(): array
{
    preg_match_all('/^\| (S\d+) \|/m', (string) file_get_contents(base_path('../docs/research/dog-data/sources.md')), $m);

    return $m[1];
}

/** The data.json entry at a dotted path, or null. */
function bsEntry(string $path): ?array
{
    static $data = null;
    $data ??= json_decode((string) file_get_contents(base_path('../docs/research/dog-data/data.json')), true, flags: JSON_THROW_ON_ERROR);

    $node = $data;
    foreach (explode('.', $path) as $segment) {
        if (! is_array($node) || ! array_key_exists($segment, $node)) {
            return null;
        }
        $node = $node[$segment];
    }

    return is_array($node) ? $node : null;
}

it('has a fixed vocabulary of two kinds and never a "hypoallergenic" tag', function () {
    $vocabulary = (array) config('breed_suitability.vocabulary');

    expect($vocabulary)->not->toBe([])
        ->and(array_unique(array_values($vocabulary)))->toEqualCanonicalizing([BreedSuitability::SUITS, BreedSuitability::CONSIDER])
        ->and(BreedSuitability::vocabulary())->toBe($vocabulary);
    foreach (array_keys($vocabulary) as $tag) {
        expect($tag)->toMatch('/^[a-z][a-z_]*$/')
            ->and($tag)->not->toContain('hypoallergenic')
            ->and($tag)->not->toContain('allerg');
    }
});

it('backs every breed tag with listed sources and data.json entries that cite them', function () {
    $vocabulary = BreedSuitability::vocabulary();
    $known = bsSourceIds();
    expect($known)->toContain('S4', 'S50', 'S60', 'S65', 'S68', 'S69', 'S70');

    foreach ((array) config('breed_suitability.breeds') as $breedKey => $kinds) {
        $breed = BreedType::tryFrom((string) $breedKey);
        expect($breed)->not->toBeNull("unknown breed {$breedKey}")
            ->and(array_diff(array_keys($kinds), [BreedSuitability::SUITS, BreedSuitability::CONSIDER]))->toBe([]);

        foreach ($kinds as $kind => $tags) {
            $seen = [];
            foreach ($tags as $item) {
                $label = "{$breedKey}.{$kind}.".($item['tag'] ?? '?');
                expect($vocabulary)->toHaveKey($item['tag'])
                    ->and($vocabulary[$item['tag']])->toBe($kind, "{$label}: wrong kind")
                    ->and($seen)->not->toContain($item['tag'])
                    ->and($item['source_ids'])->toBeArray()->not->toBeEmpty()
                    ->and($item['refs'])->toBeArray()->not->toBeEmpty();
                $seen[] = $item['tag'];

                // Every ref is a data.json entry of THIS breed with a quote.
                $cited = '';
                foreach ($item['refs'] as $ref) {
                    expect($ref)->toStartWith("{$breedKey}.");
                    $entry = bsEntry($ref);
                    expect($entry)->not->toBeNull("{$label}: no data.json entry {$ref}")
                        ->and($entry['quote'] ?? null)->toBeString("{$label}: {$ref} has no quote");
                    $cited .= ' '.($entry['source_id'] ?? '').' '.($entry['notes'] ?? '').' '.implode(' ', $entry['derived_from'] ?? []);
                }
                foreach ($item['source_ids'] as $id) {
                    expect($known)->toContain($id)
                        ->and(preg_match('/\b'.$id.'\b/', $cited))->toBe(1, "{$label}: {$id} is not cited by its data.json refs");
                }
            }
        }
    }
});

it('returns tag keys per kind; breeds without sourced tags get empty lists', function () {
    $suitability = app(BreedSuitability::class);

    expect($suitability->for(BreedType::LabradorRetriever))->toBe([
        'suits' => ['active_family', 'family_pet', 'large_home', 'other_pets'],
        'consider' => ['sheds', 'long_daily_exercise', 'food_motivated_weight'],
    ])
        ->and($suitability->for(BreedType::GoldenRetriever))->toBe([
            'suits' => ['active_family', 'family_pet', 'children', 'first_time_owner', 'large_home', 'other_pets'],
            'consider' => ['long_daily_exercise', 'sheds', 'food_motivated_weight', 'frequent_grooming'],
        ])
        ->and($suitability->for(BreedType::FrenchBulldog))->toBe([
            'suits' => ['apartment', 'family_pet', 'children'],
            'consider' => ['brachycephalic_breathing', 'chases_small_pets'],
        ])
        ->and($suitability->for(BreedType::BorderCollie))->toBe([
            'suits' => ['active_family'],
            'consider' => ['long_daily_exercise', 'needs_mental_stimulation', 'may_herd_children', 'chews_when_bored'],
        ]);
    foreach ([BreedType::Mutt, BreedType::DomesticCat, BreedType::MaineCoon, BreedType::BritishShorthair, BreedType::Ragdoll, BreedType::SiberianCat, BreedType::NorwegianForestCat, BreedType::Bengal] as $breed) {
        expect($suitability->for($breed))->toBe(['suits' => [], 'consider' => []]);
    }

    // A tag outside the vocabulary or under the wrong kind never reaches the apps.
    config(['breed_suitability.breeds.mutt' => [
        'suits' => [['tag' => 'hypoallergenic'], ['tag' => 'sheds'], ['tag' => 'children']],
        'consider' => [['tag' => 'children'], ['tag' => 'sheds']],
    ]]);
    expect($suitability->for(BreedType::Mutt))->toBe(['suits' => ['children'], 'consider' => ['sheds']]);
});

it('keeps child tags for later breeds but gives the Labrador the sourced family_pet / large_home tags (David 2026-10-09)', function () {
    $vocabulary = BreedSuitability::vocabulary();

    expect($vocabulary)->toMatchArray([
        'family_pet' => BreedSuitability::SUITS,
        'large_home' => BreedSuitability::SUITS,
        'children' => BreedSuitability::SUITS,
        'small_children' => BreedSuitability::SUITS,
    ])->and($vocabulary)->not->toHaveKey('house_with_garden');

    $lab = app(BreedSuitability::class)->for(BreedType::LabradorRetriever)['suits'];
    expect($lab)->toContain('family_pet', 'large_home')
        ->not->toContain('children')
        ->not->toContain('small_children');
});

it('gives the Golden Retriever `children` (PDSA statement) but never `small_children`, and the new frequent_grooming tag (David 2026-10-09)', function () {
    $vocabulary = BreedSuitability::vocabulary();
    expect($vocabulary)->toMatchArray(['frequent_grooming' => BreedSuitability::CONSIDER])
        ->and($vocabulary)->not->toHaveKey('mouthy');

    $golden = app(BreedSuitability::class)->for(BreedType::GoldenRetriever);
    expect($golden['suits'])->toContain('children', 'first_time_owner')->not->toContain('small_children')
        ->and($golden['consider'])->toContain('frequent_grooming');

    // Only the Golden needs frequent grooming so far (Labrador RKC "Once a week", S50).
    expect(app(BreedSuitability::class)->for(BreedType::LabradorRetriever)['consider'])->not->toContain('frequent_grooming');

    // The caveat is recorded with the source: PDSA advises supervising dogs with children.
    expect(bsEntry('golden_retriever.suitability.pdsa_children')['notes'])->toContain('supervis');
});

it('gives the French Bulldog the first sourced `apartment` tag and the new brachycephalic_breathing tag, never `small_children` (David 2026-10-10)', function () {
    $vocabulary = BreedSuitability::vocabulary();
    expect($vocabulary)->toMatchArray(['brachycephalic_breathing' => BreedSuitability::CONSIDER]);

    $frenchie = app(BreedSuitability::class)->for(BreedType::FrenchBulldog);
    expect($frenchie['suits'])->toContain('apartment', 'children')->not->toContain('small_children')->not->toContain('other_pets')
        ->and($frenchie['consider'])->toBe(['brachycephalic_breathing', 'chases_small_pets']);

    // Only the flat-faced breed gets the breathing tag; only it suits a flat so far.
    foreach ([BreedType::BorderCollie, BreedType::LabradorRetriever, BreedType::GoldenRetriever] as $breed) {
        $tags = app(BreedSuitability::class)->for($breed);
        expect($tags['consider'])->not->toContain('brachycephalic_breathing')
            ->and($tags['suits'])->not->toContain('apartment');
    }

    // The caveat is recorded with the source: PDSA advises supervising play.
    expect(bsEntry('french_bulldog.behaviour.family')['notes'])->toContain('supervising');
});

it('gives the German Shepherd the new hips_hind_legs tag (welfare rule), never `children` (runbook 2026-10-10)', function () {
    expect(BreedSuitability::vocabulary())->toMatchArray(['hips_hind_legs' => BreedSuitability::CONSIDER]);

    $shepherd = app(BreedSuitability::class)->for(BreedType::GermanShepherd);
    expect($shepherd['suits'])->toBe(['active_family', 'family_pet', 'large_home'])
        ->and($shepherd['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('other_pets')
        ->and($shepherd['consider'])->toContain('hips_hind_legs', 'long_daily_exercise', 'sheds')->not->toContain('brachycephalic_breathing');

    // Only the German Shepherd carries the hind-leg tag so far.
    foreach ([BreedType::BorderCollie, BreedType::LabradorRetriever, BreedType::GoldenRetriever, BreedType::FrenchBulldog] as $breed) {
        expect(app(BreedSuitability::class)->for($breed)['consider'])->not->toContain('hips_hind_legs');
    }

    // The RKC Breed Watch concern is the quoted source; the PDSA caveat is kept with the family entry.
    expect(bsEntry('german_shepherd.health.hind_conformation')['quote'])->toBe('Incorrect hind conformation and/or poor rear movement')
        ->and(bsEntry('german_shepherd.behaviour.family')['notes'])->toContain('supervised');
});

it('gives the Cavalier the new heart_and_spine tag (welfare rule) and the apartment / children tags (runbook 2026-10-10)', function () {
    expect(BreedSuitability::vocabulary())->toMatchArray(['heart_and_spine' => BreedSuitability::CONSIDER]);

    $cavalier = app(BreedSuitability::class)->for(BreedType::CavalierKingCharlesSpaniel);
    expect($cavalier['suits'])->toBe(['apartment', 'family_pet', 'children'])
        ->and($cavalier['suits'])->not->toContain('small_children')->not->toContain('other_pets')->not->toContain('often_alone')
        ->and($cavalier['consider'])->toBe(['sheds', 'frequent_grooming', 'heart_and_spine'])
        ->and($cavalier['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('hips_hind_legs');

    // Only the Cavalier carries the heart / spine tag so far.
    foreach ([BreedType::BorderCollie, BreedType::LabradorRetriever, BreedType::GoldenRetriever, BreedType::FrenchBulldog, BreedType::GermanShepherd] as $breed) {
        expect(app(BreedSuitability::class)->for($breed)['consider'])->not->toContain('heart_and_spine');
    }

    // PDSA is the quoted source for the heart; the supervision caveat stays with the children entry.
    expect(bsEntry('cavalier_king_charles_spaniel.health.heart_mvd')['quote'])->toBe('Heart conditions (most often caused by mitral valve disease) – this is a big problem for this breed.')
        ->and(bsEntry('cavalier_king_charles_spaniel.suitability.pdsa_children')['notes'])->toContain('supervising');
});

it('gives the Beagle family_pet / sheds, chews_when_bored and no new tag (runbook 2026-10-10)', function () {
    $beagle = app(BreedSuitability::class)->for(BreedType::Beagle);
    expect($beagle['suits'])->toBe(['family_pet'])
        // Only "supervise" (S115), RKC "Small house" (S113), not alone with smaller pets.
        ->and($beagle['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('apartment')->not->toContain('other_pets')
        ->and($beagle['consider'])->toBe(['sheds', 'chews_when_bored'])
        // Obesity is common (S116) but no source says it "loves food"; not a welfare breed.
        ->and($beagle['consider'])->not->toContain('food_motivated_weight')->not->toContain('brachycephalic_breathing')->not->toContain('heart_and_spine');

    expect(bsEntry('beagle.behaviour.chewing')['source_id'])->toBe('S115')
        ->and(bsEntry('beagle.suitability.pdsa_children')['notes'])->toContain('no children');
});

it('gives the Standard Poodle children, large_home, other_pets, low_shedding / frequent_grooming and never hypoallergenic (runbook 2026-10-10)', function () {
    $poodle = app(BreedSuitability::class)->for(BreedType::StandardPoodle);
    expect($poodle['suits'])->toBe(['children', 'large_home', 'other_pets', 'low_shedding'])
        // No family statement, no small-children rating, RKC "Large house" (S120).
        ->and($poodle['suits'])->not->toContain('family_pet')->not->toContain('small_children')->not->toContain('apartment')
        ->and($poodle['consider'])->toBe(['frequent_grooming'])
        // RKC "Sheds: No" (S120) → not `sheds`; not a welfare breed.
        ->and($poodle['consider'])->not->toContain('sheds')->not->toContain('brachycephalic_breathing')
        ->and(config('breed_suitability.vocabulary'))->not->toHaveKey('hypoallergenic');

    expect(bsEntry('standard_poodle.suitability.rkc_shedding')['quote'])->toBe('Sheds: No')
        ->and(bsEntry('standard_poodle.suitability.pdsa_children')['notes'])->toContain('caveat');
});

it('gives the Dachshund the new back_spine tag (welfare rule), children, sheds and needs_mental_stimulation (runbook 2026-10-10)', function () {
    expect(BreedSuitability::vocabulary())->toMatchArray(['back_spine' => BreedSuitability::CONSIDER]);

    $dachshund = app(BreedSuitability::class)->for(BreedType::Dachshund);
    expect($dachshund['suits'])->toBe(['children'])
        // Supervise (S127), not with smaller pets (S127), RKC "Small house" (S125), does not do well alone (S127).
        ->and($dachshund['suits'])->not->toContain('small_children')->not->toContain('other_pets')->not->toContain('apartment')->not->toContain('large_home')->not->toContain('often_alone')
        ->and($dachshund['consider'])->toBe(['back_spine', 'sheds', 'needs_mental_stimulation', 'chases_small_pets'])
        // Long back, not a flat face; the Cavalier's heart / Chiari tag is a different problem.
        ->and($dachshund['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('heart_and_spine')->not->toContain('hips_hind_legs');

    // Only the Dachshund carries the back tag so far.
    foreach (BreedType::cases() as $breed) {
        if ($breed !== BreedType::Dachshund) {
            expect(app(BreedSuitability::class)->for($breed)['consider'])->not->toContain('back_spine');
        }
    }

    // PDSA is the quoted source for the spine; the supervision caveat stays with the children entry.
    expect(bsEntry('dachshund.health.back_ivdd')['quote'])->toBe('Certain breeds such as the Dachshund and French bulldog are prone to IVDD and slipped discs due to the shape of their spine.')
        ->and(bsEntry('dachshund.suitability.pdsa_children')['notes'])->toContain('supervise');
});

it('gives the Australian Shepherd active_family / family_pet / large_home, the herding and exercise chips and no welfare tag (runbook 2026-10-10)', function () {
    $aussie = app(BreedSuitability::class)->for(BreedType::AustralianShepherd);
    expect($aussie['suits'])->toBe(['active_family', 'family_pet', 'large_home'])
        // PDSA advises against smaller children (herding, S134); pets only if grown up with them; not for long hours alone.
        ->and($aussie['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('other_pets')->not->toContain('often_alone')->not->toContain('first_time_owner')
        ->and($aussie['consider'])->toBe(['long_daily_exercise', 'needs_mental_stimulation', 'may_herd_children', 'chews_when_bored', 'sheds', 'frequent_grooming'])
        // RKC Breed Watch Category 1 (S133): no welfare chip.
        ->and($aussie['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('hips_hind_legs')->not->toContain('heart_and_spine')->not->toContain('back_spine');

    expect(bsEntry('australian_shepherd.behaviour.herding_children')['quote'])->toBe('because of their strong herding instinct, they can get the urge to herd children in the home')
        ->and(bsEntry('australian_shepherd.suitability.pdsa_children')['notes'])->toContain('no children / small_children tag');
});

it('gives the Havanese apartment / family_pet / children / low_shedding, the grooming chip and no welfare tag (runbook 2026-10-10)', function () {
    $havanese = app(BreedSuitability::class)->for(BreedType::Havanese);
    expect($havanese['suits'])->toBe(['apartment', 'family_pet', 'children', 'low_shedding'])
        // No explicit small-children rating; "easy to train" is not a first-time-owner statement; 30 min only.
        ->and($havanese['suits'])->not->toContain('small_children')->not->toContain('first_time_owner')->not->toContain('active_family')->not->toContain('often_alone')
        ->and($havanese['consider'])->toBe(['frequent_grooming'])
        // RKC Breed Watch Category 1 (S138): no welfare chip.
        ->and($havanese['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('hips_hind_legs')->not->toContain('heart_and_spine')->not->toContain('back_spine')->not->toContain('long_daily_exercise');

    expect(bsEntry('havanese.suitability.fci_children')['quote'])->toBe('He loves children and plays endlessly with them.')
        ->and(bsEntry('havanese.suitability.rkc_shedding')['quote'])->toBe('Sheds: No')
        ->and(bsEntry('havanese.suitability.rkc_shedding')['notes'])->toContain('never \'hypoallergenic\'');
});

it('gives the West Highland White Terrier the new sensitive_skin tag (welfare rule), children, sheds, grooming and chewing (runbook 2026-10-10)', function () {
    expect(BreedSuitability::vocabulary())->toMatchArray(['sensitive_skin' => BreedSuitability::CONSIDER]);

    $westie = app(BreedSuitability::class)->for(BreedType::WestHighlandWhiteTerrier);
    expect($westie['suits'])->toBe(['apartment', 'family_pet', 'children'])
        // PDSA: always supervise → no small_children; prey drive → no other_pets; separation anxiety → no often_alone.
        ->and($westie['suits'])->not->toContain('small_children')->not->toContain('other_pets')->not->toContain('often_alone')->not->toContain('first_time_owner')->not->toContain('low_shedding')
        ->and($westie['consider'])->toBe(['sensitive_skin', 'sheds', 'frequent_grooming', 'chews_when_bored', 'chases_small_pets'])
        ->and($westie['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('long_daily_exercise');

    // Only the Westie and the Bulldog (Breed Watch skin-fold dermatitis, M5-R10-20) carry the chip so far.
    foreach (BreedType::cases() as $breed) {
        if (! in_array($breed, [BreedType::WestHighlandWhiteTerrier, BreedType::Bulldog], true)) {
            expect(app(BreedSuitability::class)->for($breed)['consider'])->not->toContain('sensitive_skin');
        }
    }

    expect(bsEntry('west_highland_white_terrier.health.sensitive_skin')['quote'])->toBe('Westies are known to suffer from skin allergies, so speak to your vet before buying a shampoo.')
        ->and(bsEntry('west_highland_white_terrier.health.sensitive_skin')['notes'])->toContain('Signs of dermatitis irritation')
        ->and(bsEntry('west_highland_white_terrier.suitability.pdsa_children')['notes'])->toContain('not small_children');
});

it('gives the Bernese Mountain Dog the new shorter_lifespan tag, family_pet, large_home, sheds and grooming (runbook 2026-10-10)', function () {
    expect(BreedSuitability::vocabulary())->toMatchArray(['shorter_lifespan' => BreedSuitability::CONSIDER]);

    $berner = app(BreedSuitability::class)->for(BreedType::BerneseMountainDog);
    expect($berner['suits'])->toBe(['family_pet', 'large_home'])
        // PDSA vs Woodgreen conflict on children → neither children nor small_children.
        ->and($berner['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('apartment')->not->toContain('often_alone')->not->toContain('active_family')
        ->and($berner['consider'])->toBe(['shorter_lifespan', 'sheds', 'frequent_grooming'])
        // RKC Breed Watch Category 1: no conformation welfare chip.
        ->and($berner['consider'])->not->toContain('hips_hind_legs')->not->toContain('long_daily_exercise')->not->toContain('chews_when_bored');

    // Only the Bernese, the Rottweiler (RKC "Under 10 years", M5-R10-19), the Bulldog (M5-R10-20), the
    // Cane Corso (PDSA "Under 10 years", M5-R10-23) and the Great Dane (RKC "Under 10 years", M5-R10-29) carry the chip so far.
    foreach (BreedType::cases() as $breed) {
        if (! in_array($breed, [BreedType::BerneseMountainDog, BreedType::Rottweiler, BreedType::Bulldog, BreedType::CaneCorso, BreedType::GreatDane], true)) {
            expect(app(BreedSuitability::class)->for($breed)['consider'])->not->toContain('shorter_lifespan');
        }
    }

    expect(bsEntry('bernese_mountain_dog.health.shorter_lifespan')['quote'])->toBe('Lifespan: Under 10 years')
        ->and(bsEntry('bernese_mountain_dog.suitability.pdsa_children')['quote'])->toBe('we wouldn’t recommend them for families with smaller children.')
        ->and(bsEntry('bernese_mountain_dog.suitability.woodgreen_children')['notes'])->toContain('no children tag');
});

it('gives the Siberian Husky the new secure_fencing tag, active_family, large_home, exercise, chewing, shedding and grooming (runbook 2026-10-10)', function () {
    expect(BreedSuitability::vocabulary())->toMatchArray(['secure_fencing' => BreedSuitability::CONSIDER]);

    $husky = app(BreedSuitability::class)->for(BreedType::SiberianHusky);
    expect($husky['suits'])->toBe(['active_family', 'large_home'])
        // PDSA: adult-only or teenage households, not with smaller children; not with smaller pets.
        ->and($husky['suits'])->not->toContain('family_pet')->not->toContain('children')->not->toContain('small_children')->not->toContain('other_pets')->not->toContain('often_alone')->not->toContain('first_time_owner')
        ->and($husky['consider'])->toBe(['long_daily_exercise', 'secure_fencing', 'chews_when_bored', 'sheds', 'frequent_grooming', 'chases_small_pets'])
        // Breed Watch Category 2 is body condition only ("Too fat" / "Too thin"): no conformation chip.
        ->and($husky['consider'])->not->toContain('hips_hind_legs')->not->toContain('shorter_lifespan')->not->toContain('food_motivated_weight');

    // Only the Siberian Husky carries the new chip so far.
    foreach (BreedType::cases() as $breed) {
        if ($breed !== BreedType::SiberianHusky) {
            expect(app(BreedSuitability::class)->for($breed)['consider'])->not->toContain('secure_fencing');
        }
    }

    expect(bsEntry('siberian_husky.behaviour.escaping')['quote'])->toBe('Owners need a very secure garden with high fences as Huskies are known for getting up to mischief')
        ->and(bsEntry('siberian_husky.suitability.pdsa_children')['quote'])->toBe('we wouldn’t recommend getting a Husky if you have smaller children as they could knock them over accidentally.')
        ->and(bsEntry('siberian_husky.health.breed_watch')['notes'])->toContain('Too thin');
});

it('gives the Rottweiler the new experienced_owner tag, hips_hind_legs, shorter_lifespan, family_pet, large_home, exercise and shedding (runbook 2026-10-11)', function () {
    expect(BreedSuitability::vocabulary())->toMatchArray(['experienced_owner' => BreedSuitability::CONSIDER]);

    $rottweiler = app(BreedSuitability::class)->for(BreedType::Rottweiler);
    expect($rottweiler['suits'])->toBe(['active_family', 'family_pet', 'large_home'])
        // PDSA: not for families with younger children and babies, never unattended with other pets, experienced owner.
        ->and($rottweiler['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('other_pets')->not->toContain('first_time_owner')->not->toContain('apartment')
        ->and($rottweiler['consider'])->toBe(['long_daily_exercise', 'experienced_owner', 'hips_hind_legs', 'shorter_lifespan', 'sheds'])
        // Weekly brushing only (RKC "Once a week"); not flat-faced; no breed-specific chewing.
        ->and($rottweiler['consider'])->not->toContain('frequent_grooming')->not->toContain('brachycephalic_breathing')->not->toContain('chews_when_bored');

    // Only the Rottweiler, the Cane Corso (PDSA S199, M5-R10-23), the Dobermann (PDSA S211, M5-R10-25) and the Boxer (PDSA S219, M5-R10-26) carry the chip so far.
    foreach (BreedType::cases() as $breed) {
        if (! in_array($breed, [BreedType::Rottweiler, BreedType::CaneCorso, BreedType::Dobermann, BreedType::Boxer], true)) {
            expect(app(BreedSuitability::class)->for($breed)['consider'])->not->toContain('experienced_owner');
        }
    }

    expect(bsEntry('rottweiler.behaviour.experienced_owner')['quote'])->toBe('Because they\'re so powerful, Rottweilers need an experienced owner who understands them.')
        ->and(bsEntry('rottweiler.health.breed_watch')['quote'])->toBe('Incorrect hind conformation and/or poor rear movement')
        ->and(bsEntry('rottweiler.suitability.pdsa_children')['quote'])->toBe('We wouldn\'t recommend Rottweilers for families with younger children and babies')
        ->and(bsEntry('rottweiler.lifespan.pdsa')['notes'])->toContain('conflicts');
});

it('types the API field with exactly the vocabulary (Scramble → mobile schema.ts)', function () {
    $source = (string) file_get_contents(app_path('Http/Resources/BreedCatalogResource.php'));
    preg_match('/@var array\{suits: list<([^>]+)>, consider: list<([^>]+)>\}/', $source, $m);
    expect($m)->toHaveCount(3);

    $union = fn (string $s): array => array_map(fn (string $v) => trim($v, " '"), explode('|', $s));
    $vocabulary = BreedSuitability::vocabulary();

    expect($union($m[1]))->toEqualCanonicalizing(array_keys(array_filter($vocabulary, fn ($k) => $k === BreedSuitability::SUITS)))
        ->and($union($m[2]))->toEqualCanonicalizing(array_keys(array_filter($vocabulary, fn ($k) => $k === BreedSuitability::CONSIDER)));
});

it('gives the Bulldog the flat-face and skin welfare chips, shorter_lifespan, sheds, family_pet and other_pets (runbook 2026-10-11)', function () {
    $bulldog = app(BreedSuitability::class)->for(BreedType::Bulldog);
    expect($bulldog['suits'])->toBe(['family_pet', 'other_pets'])
        // No child rating or statement; RKC "Small house", not "Flat/ Apartment".
        ->and($bulldog['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('apartment')->not->toContain('large_home')
        ->and($bulldog['consider'])->toBe(['brachycephalic_breathing', 'sensitive_skin', 'shorter_lifespan', 'sheds'])
        // Weekly brushing only (RKC "Once a week"); up to 1 hour of exercise.
        ->and($bulldog['consider'])->not->toContain('frequent_grooming')->not->toContain('long_daily_exercise');
    // The flat-faced breeds are the only ones with the breathing chip (the moderately short-faced Boxer has its own, M5-R10-26;
    // the Boston Terrier is flat-faced with a heat warning, M5-R10-31).
    foreach (BreedType::cases() as $breed) {
        if (! in_array($breed, [BreedType::FrenchBulldog, BreedType::Bulldog, BreedType::BostonTerrier], true)) {
            expect(app(BreedSuitability::class)->for($breed)['consider'])->not->toContain('brachycephalic_breathing');
        }
    }
    expect(bsEntry('bulldog.health.brachycephaly_boas')['quote'])->toBe('Bulldogs are a severely brachycephalic (flat-faced) breed.')
        ->and(bsEntry('bulldog.health.breed_watch')['notes'])->toContain('Signs of dermatitis irritation in skin folds')
        ->and(bsEntry('bulldog.behaviour.other_pets')['quote'])->toContain('if given the right socialisation when young')
        ->and(bsEntry('bulldog.lifespan.rkc')['quote'])->toBe('Lifespan: Under 10 years');
});

it('gives the German Shorthaired Pointer active_family, large_home, long_daily_exercise and sheds — no welfare chip (runbook 2026-10-11)', function () {
    $pointer = app(BreedSuitability::class)->for(BreedType::GermanShorthairedPointer);
    expect($pointer['suits'])->toBe(['active_family', 'large_home'])
        // Only a gundog-group sentence on the RKC page → no family_pet / children; RKC "Large House".
        ->and($pointer['suits'])->not->toContain('family_pet')->not->toContain('children')->not->toContain('small_children')->not->toContain('apartment')
        ->and($pointer['consider'])->toBe(['long_daily_exercise', 'sheds'])
        // RKC once a week (PDSA once or twice) → not frequent_grooming; McMillan median 13.4 y → not shorter_lifespan.
        ->and($pointer['consider'])->not->toContain('frequent_grooming')->not->toContain('shorter_lifespan')
        ->not->toContain('hips_hind_legs')->not->toContain('brachycephalic_breathing')->not->toContain('sensitive_skin');
    expect(bsEntry('german_shorthaired_pointer.exercise.adult')['quote'])->toBe('More than 2 hours per day')
        ->and(bsEntry('german_shorthaired_pointer.suitability.rkc_size_of_home')['quote'])->toBe('Large House')
        ->and(bsEntry('german_shorthaired_pointer.suitability.dogs_trust_shedding')['quote'])->toBe('Short length with moderate shedding');
});

it('gives the Yorkshire Terrier the new fragile_older_children tag, apartment, low_shedding and frequent_grooming — no welfare chip (runbook 2026-10-12)', function () {
    expect(BreedSuitability::vocabulary())->toMatchArray(['fragile_older_children' => BreedSuitability::CONSIDER]);

    $yorkie = app(BreedSuitability::class)->for(BreedType::YorkshireTerrier);
    expect($yorkie['suits'])->toBe(['apartment', 'low_shedding'])
        // PDSA: better suited to families with older children (fragile bones) → no children / small_children; no family statement.
        ->and($yorkie['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('family_pet')->not->toContain('large_home')
        ->and($yorkie['consider'])->toBe(['fragile_older_children', 'frequent_grooming'])
        // RKC Breed Watch Category 1; RKC "Sheds: No"; VetCompass median 13.56 y.
        ->and($yorkie['consider'])->not->toContain('sheds')->not->toContain('shorter_lifespan')
        ->not->toContain('hips_hind_legs')->not->toContain('brachycephalic_breathing')->not->toContain('sensitive_skin');

    // Only the Yorkshire Terrier carries the new chip so far.
    foreach (BreedType::cases() as $breed) {
        if ($breed !== BreedType::YorkshireTerrier) {
            expect(app(BreedSuitability::class)->for($breed)['consider'])->not->toContain('fragile_older_children');
        }
    }

    expect(bsEntry('yorkshire_terrier.suitability.pdsa_children')['quote'])->toBe('They are better suited to families with older children as they have very fragile bones')
        ->and(bsEntry('yorkshire_terrier.suitability.rkc_shedding')['quote'])->toBe('Sheds: No')
        ->and(bsEntry('yorkshire_terrier.suitability.rkc_grooming')['quote'])->toBe('Grooming: Every day');
});

it('gives the Cane Corso the new not_for_small_children and drools tags, active_family and the PDSA chips — no welfare chip (runbook 2026-10-12)', function () {
    expect(BreedSuitability::vocabulary())->toMatchArray([
        'not_for_small_children' => BreedSuitability::CONSIDER,
        'drools' => BreedSuitability::CONSIDER,
    ]);

    $corso = app(BreedSuitability::class)->for(BreedType::CaneCorso);
    expect($corso['suits'])->toBe(['active_family'])
        // PDSA: not with small children, adult-only homes or older children → no children / small_children / family_pet;
        // no RKC page → no large_home / apartment.
        ->and($corso['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('family_pet')->not->toContain('large_home')->not->toContain('apartment')
        ->and($corso['consider'])->toBe(['long_daily_exercise', 'not_for_small_children', 'experienced_owner', 'needs_mental_stimulation', 'shorter_lifespan', 'sheds', 'drools'])
        // No Breed Watch / vet-association conformation point; not flat-faced; weekly brush only.
        ->and($corso['consider'])->not->toContain('hips_hind_legs')->not->toContain('brachycephalic_breathing')
        ->not->toContain('sensitive_skin')->not->toContain('frequent_grooming')->not->toContain('fragile_older_children');

    // `drools` also the Great Dane (PDSA S243, M5-R10-29); `not_for_small_children` also the Dobermann (PDSA S211,
    // M5-R10-25), the Boxer (PDSA S219, M5-R10-26) and the Great Dane (PDSA S243, M5-R10-29).
    foreach (BreedType::cases() as $breed) {
        if (! in_array($breed, [BreedType::CaneCorso, BreedType::GreatDane], true)) {
            expect(app(BreedSuitability::class)->for($breed)['consider'])->not->toContain('drools');
        }
        if (! in_array($breed, [BreedType::CaneCorso, BreedType::Dobermann, BreedType::Boxer, BreedType::GreatDane], true)) {
            expect(app(BreedSuitability::class)->for($breed)['consider'])->not->toContain('not_for_small_children');
        }
    }

    expect(bsEntry('cane_corso.suitability.pdsa_children')['quote'])->toBe('We really wouldn’t recommend having a Cane Corso if you have small children')
        ->and(bsEntry('cane_corso.suitability.pdsa_drooling')['quote'])->toBe('Despite not needing much attention for their fur, Cane Corsos are known to drool.')
        ->and(bsEntry('cane_corso.lifespan.pdsa')['quote'])->toBe('Average lifespan: Under 10 years')
        ->and(bsEntry('cane_corso.exercise.adult')['quote'])->toBe('Your Cane Corso will need around two hours’ exercise every day.');
});

it('gives the Pembroke Welsh Corgi may_herd_children, sheds and frequent_grooming — no suits tag, no welfare chip (runbook 2026-10-12)', function () {
    $corgi = app(BreedSuitability::class)->for(BreedType::PembrokeWelshCorgi);
    // RKC "Small house" (not Flat/ Apartment, not Large house), 60 min, no family / children rating → no suits tag.
    expect($corgi['suits'])->toBe([])
        ->and($corgi['consider'])->toBe(['may_herd_children', 'sheds', 'frequent_grooming'])
        // RKC Breed Watch Category 1 → no welfare chip; PDSA's IVDD / hip items stay in research.
        ->and($corgi['consider'])->not->toContain('back_spine')->not->toContain('hips_hind_legs')->not->toContain('brachycephalic_breathing')
        ->not->toContain('shorter_lifespan')->not->toContain('long_daily_exercise');

    expect(bsEntry('pembroke_welsh_corgi.suitability.pdsa_children')['quote'])->toBe('They may nip at the heels of running children')
        ->and(bsEntry('pembroke_welsh_corgi.suitability.rkc_shedding')['quote'])->toBe('Sheds: Yes')
        ->and(bsEntry('pembroke_welsh_corgi.suitability.rkc_grooming')['quote'])->toBe('Grooming: More than once a week')
        ->and(bsEntry('pembroke_welsh_corgi.suitability.rkc_size_of_home')['quote'])->toBe('Size of home: Small house');
});

it('gives the Dobermann active_family, family_pet, large_home and the PDSA cautions — never children, no welfare chip (runbook 2026-10-12)', function () {
    $dobermann = app(BreedSuitability::class)->for(BreedType::Dobermann);
    expect($dobermann['suits'])->toBe(['active_family', 'family_pet', 'large_home'])
        ->and($dobermann['consider'])->toBe(['long_daily_exercise', 'not_for_small_children', 'experienced_owner', 'needs_mental_stimulation', 'sheds', 'chases_small_pets'])
        // PDSA does not recommend the breed with young children or first-time owners; high prey drive.
        ->and($dobermann['suits'])->not->toContain('children')->not->toContain('small_children')
        ->not->toContain('first_time_owner')->not->toContain('other_pets')
        // RKC Breed Watch Category 1 → no welfare chip; RKC "Over 10 years" → no shorter_lifespan.
        ->and($dobermann['consider'])->not->toContain('hips_hind_legs')->not->toContain('heart_and_spine')
        ->not->toContain('brachycephalic_breathing')->not->toContain('shorter_lifespan');

    expect(bsEntry('dobermann.suitability.rkc_family')['quote'])->toBe('However, his loyal and obedient nature equips him to be an excellent family dog, a role in which he is equally comfortable.')
        ->and(bsEntry('dobermann.suitability.pdsa_children')['quote'])->toBe('due to their large size and strong build we wouldn’t recommend a Dobermann if you have young children.')
        ->and(bsEntry('dobermann.suitability.rkc_size_of_home')['quote'])->toBe('Size of home: Large house')
        ->and(bsEntry('dobermann.exercise.adult')['quote'])->toBe('Exercise: More than 2 hours per day');
});

it('gives the Boxer the new needs_company and short_muzzle_breathing tags and the PDSA cautions — never children, never the flat-face chip (runbook 2026-10-12)', function () {
    expect(BreedSuitability::vocabulary())->toMatchArray(['needs_company' => BreedSuitability::CONSIDER, 'short_muzzle_breathing' => BreedSuitability::CONSIDER]);

    $boxer = app(BreedSuitability::class)->for(BreedType::Boxer);
    expect($boxer['suits'])->toBe(['active_family', 'family_pet', 'large_home'])
        ->and($boxer['consider'])->toBe(['long_daily_exercise', 'short_muzzle_breathing', 'not_for_small_children', 'experienced_owner', 'needs_company', 'chews_when_bored', 'sheds', 'chases_small_pets'])
        // PDSA: not with younger children or first-time owners; very high prey drive; separation anxiety.
        ->and($boxer['suits'])->not->toContain('children')->not->toContain('small_children')
        ->not->toContain('first_time_owner')->not->toContain('other_pets')->not->toContain('often_alone')
        // Breed Watch points are nostrils / muzzle only → no skin chip; RKC "Over 10 years" → no shorter_lifespan.
        ->and($boxer['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('sensitive_skin')->not->toContain('shorter_lifespan')
        ->not->toContain('hips_hind_legs')->not->toContain('heart_and_spine')->not->toContain('frequent_grooming');

    // Only the Boxer, the Boston Terrier (AKC S260, M5-R10-31), the Miniature American Shepherd (PetMD S269, M5-R10-32)
    // the Chihuahua (PDSA S277, M5-R10-33), the English Springer Spaniel (AKC S286, M5-R10-34) and the Brittany (PetMD S302, M5-R10-36) carry needs_company; short_muzzle_breathing also the Shih Tzu (M5-R10-28, no heat source).
    foreach (BreedType::cases() as $breed) {
        if (! in_array($breed, [BreedType::Boxer, BreedType::BostonTerrier, BreedType::MiniatureAmericanShepherd, BreedType::Chihuahua, BreedType::EnglishSpringerSpaniel, BreedType::Brittany], true)) {
            expect(app(BreedSuitability::class)->for($breed)['consider'])->not->toContain('needs_company');
        }
        if (! in_array($breed, [BreedType::Boxer, BreedType::ShihTzu], true)) {
            expect(app(BreedSuitability::class)->for($breed)['consider'])->not->toContain('short_muzzle_breathing');
        }
    }

    expect(bsEntry('boxer.behaviour.time_alone')['quote'])->toBe('Boxers love human company so can develop separation anxiety if left alone.')
        ->and(bsEntry('boxer.suitability.pdsa_time_alone')['quote'])->toBe("It's best to have a Boxer if someone is going to be around the house with them all day.")
        ->and(bsEntry('boxer.health.skull_shape')['quote'])->toContain('moderately brachycephalic skull shape')
        ->and(bsEntry('boxer.health.breed_watch')['quote'])->toBe('Excessively short muzzle')
        ->and(bsEntry('boxer.suitability.rkc_size_of_home')['quote'])->toBe('Size of home: Large house')
        ->and(bsEntry('boxer.exercise.adult')['quote'])->toBe('Exercise: More than 2 hours per day');
});

it('gives the Miniature Schnauzer apartment, children, low_shedding and frequent_grooming — no welfare chip, never hypoallergenic (runbook 2026-10-13)', function () {
    $schnauzer = app(BreedSuitability::class)->for(BreedType::MiniatureSchnauzer);
    expect($schnauzer['suits'])->toBe(['apartment', 'children', 'low_shedding'])
        ->and($schnauzer['consider'])->toBe(['frequent_grooming'])
        // PDSA's statement is conditional and no rating → never small_children; RKC "companion dogs" → no family_pet.
        ->and($schnauzer['suits'])->not->toContain('small_children')->not->toContain('family_pet')->not->toContain('other_pets')
        // RKC Breed Watch Category 1 → no welfare chip; RKC "Over 10 years" → no shorter_lifespan; "Sheds: No" → no sheds.
        ->and($schnauzer['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')
        ->not->toContain('sensitive_skin')->not->toContain('back_spine')->not->toContain('shorter_lifespan')->not->toContain('sheds');

    expect(bsEntry('miniature_schnauzer.suitability.rkc_size_of_home')['quote'])->toBe('Size of home: Flat/ Apartment')
        ->and(bsEntry('miniature_schnauzer.suitability.rkc_shedding')['quote'])->toBe('Sheds: No')
        ->and(bsEntry('miniature_schnauzer.suitability.pdsa_children')['quote'])->toBe("They can get on well with children if they're introduced at an early age.")
        ->and(bsEntry('miniature_schnauzer.behaviour.family')['notes'])->toContain('early introduction')
        ->and(bsEntry('miniature_schnauzer.suitability.pdsa_grooming')['quote'])->toBe('Grooming is needed 2-3 times a week');
});

it('gives chases_small_pets only to the eight breeds whose own entry quotes a high prey drive (David 2026-10-12)', function () {
    expect(BreedSuitability::vocabulary())->toMatchArray(['chases_small_pets' => BreedSuitability::CONSIDER]);

    $expected = [
        BreedType::FrenchBulldog->value => 'french_bulldog.behaviour.other_pets',
        BreedType::Dachshund->value => 'dachshund.behaviour.other_pets',
        BreedType::WestHighlandWhiteTerrier->value => 'west_highland_white_terrier.behaviour.other_pets',
        BreedType::SiberianHusky->value => 'siberian_husky.behaviour.other_pets',
        BreedType::Dobermann->value => 'dobermann.behaviour.other_pets',
        BreedType::Boxer->value => 'boxer.behaviour.other_pets',
        // M5-R10-29: PDSA S243 "a fairly strong prey drive" (strong = high, runbook §3).
        BreedType::GreatDane->value => 'great_dane.behaviour.other_pets',
        // M5-R10-36: PetMD S302 "hardwired with a high prey drive".
        BreedType::Brittany->value => 'brittany.behaviour.other_pets',
    ];

    foreach (BreedType::cases() as $breed) {
        $consider = app(BreedSuitability::class)->for($breed)['consider'];
        if (! array_key_exists($breed->value, $expected)) {
            // Beagle / Bernese / Rottweiler / Cavalier / German Shepherd: supervision advice only, no prey-drive statement.
            expect($consider)->not->toContain('chases_small_pets');

            continue;
        }

        // Appended at the end of the breed's consider list.
        expect(end($consider))->toBe('chases_small_pets');

        $entry = collect(config("breed_suitability.breeds.{$breed->value}.consider"))->firstWhere('tag', 'chases_small_pets');
        expect($entry['refs'])->toContain($expected[$breed->value]);
        foreach ($entry['refs'] as $ref) {
            // Every ref is a verbatim quote naming a high (or strong) prey drive.
            expect(bsEntry($ref)['quote'])->toMatch('/(high|strong) prey drive/');
        }
    }

    expect(bsEntry('french_bulldog.behaviour.other_pets')['quote'])->toBe('French Bulldogs do have a high prey drive.')
        ->and(bsEntry('dobermann.suitability.pdsa_other_pets')['source_id'])->toBe('S211');
});

it('gives the Shih Tzu apartment, family_pet, first_time_owner, frequent_grooming and the short-muzzle chip — no shedding chip — never hypoallergenic (runbook 2026-10-13)', function () {
    $shihTzu = app(BreedSuitability::class)->for(BreedType::ShihTzu);
    expect($shihTzu['suits'])->toBe(['apartment', 'family_pet', 'first_time_owner'])
        ->and($shihTzu['consider'])->toBe(['frequent_grooming', 'short_muzzle_breathing'])
        // PDSA only advises supervision with children → never children / small_children; pets it grew up with → no other_pets.
        ->and($shihTzu['suits'])->not->toContain('low_shedding')->not->toContain('children')->not->toContain('small_children')->not->toContain('other_pets')
        // Brachycephalic (BVA) but no Shih Tzu source names heat → the Boxer's short-muzzle chip, not the flat-face one;
        // RKC "Over 10 years" → no shorter_lifespan; RKC "Sheds: No" vs PDSA seasonal shedding → neither shedding chip;
        // no prey-drive statement.
        ->and($shihTzu['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('shorter_lifespan')
        ->not->toContain('sheds')->not->toContain('chases_small_pets');

    expect(bsEntry('shih_tzu.suitability.rkc_size_of_home')['quote'])->toBe('Size of home: Flat/ Apartment')
        ->and(bsEntry('shih_tzu.suitability.rkc_shedding')['quote'])->toBe('Sheds: No')
        ->and(bsEntry('shih_tzu.suitability.pdsa_shedding')['quote'])->toBe('Expect them to shed more in autumn and spring')
        ->and(bsEntry('shih_tzu.suitability.pdsa_first_time_owner')['quote'])->toBe('Shih Tzus are an ideal pet for first-time owners.')
        ->and(bsEntry('shih_tzu.suitability.pdsa_children')['notes'])->toContain('no children / small_children')
        ->and(bsEntry('shih_tzu.health.skull_shape')['quote'])->toContain('Shih-tzu')
        ->and(bsEntry('shih_tzu.suitability.rkc_grooming')['quote'])->toBe('Grooming: Every day');
});

it('gives the Great Dane the new eyelid_problems tag and hips_hind_legs (welfare rule), shorter_lifespan and the PDSA cautions — never children (runbook 2026-10-13)', function () {
    expect(BreedSuitability::vocabulary())->toMatchArray(['eyelid_problems' => BreedSuitability::CONSIDER]);

    $dane = app(BreedSuitability::class)->for(BreedType::GreatDane);
    expect($dane['suits'])->toBe(['active_family', 'family_pet', 'large_home'])
        // PDSA does not recommend the breed with younger children; only suggests a trainer's help for first-time owners.
        ->and($dane['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('first_time_owner')->not->toContain('other_pets')->not->toContain('apartment')
        ->and($dane['consider'])->toBe(['long_daily_exercise', 'not_for_small_children', 'shorter_lifespan', 'hips_hind_legs', 'eyelid_problems', 'chews_when_bored', 'sheds', 'drools', 'chases_small_pets'])
        // Not flat-faced; weekly brush only; "like most dogs" upset when alone is not the Boxer's warning.
        ->and($dane['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')
        ->not->toContain('frequent_grooming')->not->toContain('needs_company')->not->toContain('experienced_owner');

    // Only the Great Dane carries eyelid_problems so far.
    foreach (BreedType::cases() as $breed) {
        if ($breed !== BreedType::GreatDane) {
            expect(app(BreedSuitability::class)->for($breed)['consider'])->not->toContain('eyelid_problems');
        }
    }

    // The RKC Breed Watch points are the quoted welfare source.
    expect(bsEntry('great_dane.health.breed_watch')['quote'])->toBe('Eyelids not in normal contact with the eyeball, they are turned inwards or outwards')
        ->and(bsEntry('great_dane.health.hind_conformation')['quote'])->toBe('Incorrect hind conformation and/or poor rear movement')
        ->and(bsEntry('great_dane.health.eyelids')['quote'])->toContain('(entropion/ectropion) of the eyelids')
        ->and(bsEntry('great_dane.suitability.pdsa_children')['quote'])->toBe('we wouldn’t recommend a Great Dane if you have younger children')
        ->and(bsEntry('great_dane.suitability.rkc_size_of_home')['quote'])->toBe('Size of home: Large house')
        ->and(bsEntry('great_dane.lifespan.rkc')['quote'])->toBe('Lifespan: Under 10 years')
        ->and(bsEntry('great_dane.suitability.pdsa_drooling')['quote'])->toBe('Great Danes are, however, known for drooling!');
});

it('gives the Pomeranian the new older_children tag (AKC), apartment, family_pet, other_pets — never children and no welfare chip (runbook 2026-10-13)', function () {
    expect(BreedSuitability::vocabulary())->toMatchArray(['older_children' => BreedSuitability::CONSIDER]);

    $pom = app(BreedSuitability::class)->for(BreedType::Pomeranian);
    expect($pom['suits'])->toBe(['apartment', 'family_pet', 'other_pets'])
        ->and($pom['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('first_time_owner')
        ->and($pom['consider'])->toBe(['older_children', 'frequent_grooming', 'sheds'])
        // Not the fragile-bones chip (no A/B source names fragile bones); no welfare chip (Breed Watch points not returned).
        ->and($pom['consider'])->not->toContain('fragile_older_children')->not->toContain('not_for_small_children')
        ->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')->not->toContain('hips_hind_legs');

    // Only the Pomeranian and the Chihuahua (PDSA S277, M5-R10-33) carry older_children.
    foreach (BreedType::cases() as $breed) {
        if (! in_array($breed, [BreedType::Pomeranian, BreedType::Chihuahua], true)) {
            expect(app(BreedSuitability::class)->for($breed)['consider'])->not->toContain('older_children');
        }
    }

    expect(bsEntry('pomeranian.suitability.akc_children')['quote'])->toBe('Pomeranians tend to be a better fit for families with older children.')
        ->and(bsEntry('pomeranian.suitability.akc_other_pets')['quote'])->toBe('Poms can get along well with other animals, especially if socialized early.')
        ->and(bsEntry('pomeranian.suitability.rkc_size_of_home')['quote'])->toBe('Size of home: Flat/ Apartment')
        ->and(bsEntry('pomeranian.suitability.rkc_shedding')['quote'])->toBe('Sheds: Yes')
        ->and(bsEntry('pomeranian.suitability.rkc_breed_watch')['value'])->toBe('Category 2');
});

it('gives the Boston Terrier apartment, family_pet, children and the flat-face and company chips — never small_children, no new tag (runbook 2026-10-13)', function () {
    $boston = app(BreedSuitability::class)->for(BreedType::BostonTerrier);
    expect($boston['suits'])->toBe(['apartment', 'family_pet', 'children'])
        // Caveats (PDSA: the right socialisation as a puppy; AKC: gentle children) → never small_children.
        ->and($boston['suits'])->not->toContain('small_children')->not->toContain('other_pets')->not->toContain('often_alone')
        ->and($boston['consider'])->toBe(['brachycephalic_breathing', 'needs_company'])
        // Flat-faced with a heat warning → not the moderate short-muzzle chip; conflicting shedding sources → neither tag;
        // weekly brushing only → not frequent_grooming.
        ->and($boston['consider'])->not->toContain('short_muzzle_breathing')->not->toContain('sheds')->not->toContain('frequent_grooming')
        ->and($boston['suits'])->not->toContain('low_shedding');

    expect(bsEntry('boston_terrier.health.brachycephaly_boas')['quote'])->toBe('Boston Terriers are a brachycephalic (flat-faced) breed.')
        ->and(bsEntry('boston_terrier.exercise.heat_and_walk_timing')['quote'])->toContain('prone to overheating')
        ->and(bsEntry('boston_terrier.suitability.pdsa_children')['notes'])->toContain('the right socialisation as a puppy')
        ->and(bsEntry('boston_terrier.suitability.akc_time_alone')['quote'])->toBe('This breed doesn’t fare well when left alone for extended periods of time.')
        ->and(bsEntry('boston_terrier.suitability.rkc_shedding')['notes'])->toContain('shed minimally')
        ->and(bsEntry('boston_terrier.suitability.rkc_size_of_home')['quote'])->toBe('Size of home: Flat/ Apartment');
});

it('gives the Miniature American Shepherd family_pet, children, other_pets and the herding / company / shedding chips — never small_children, no new tag (runbook 2026-10-13)', function () {
    $mas = app(BreedSuitability::class)->for(BreedType::MiniatureAmericanShepherd);
    expect($mas['suits'])->toBe(['family_pet', 'children', 'other_pets'])
        // Caveats (AKC: may herd children; PetMD: supervise at first) → never small_children; RKC page not reachable → no apartment / large_home.
        ->and($mas['suits'])->not->toContain('small_children')->not->toContain('apartment')->not->toContain('large_home')->not->toContain('often_alone')
        // At least 1 h a day (PetMD) → not the ~2 h active_family / long_daily_exercise pair.
        ->and($mas['suits'])->not->toContain('active_family')
        ->and($mas['consider'])->toBe(['needs_mental_stimulation', 'may_herd_children', 'needs_company', 'sheds'])
        ->and($mas['consider'])->not->toContain('long_daily_exercise')->not->toContain('chases_small_pets')
        // PetMD "a couple times a week" vs AKC weekly most of the year → conflicting sources, no frequent_grooming chip.
        ->and($mas['consider'])->not->toContain('frequent_grooming');

    expect(bsEntry('miniature_american_shepherd.suitability.akc_children')['quote'])->toBe('They tend to be good with children, but may try to herd them.')
        ->and(bsEntry('miniature_american_shepherd.behaviour.time_alone')['quote'])->toBe("Their devotion to family can lead to separation anxiety when they're left alone.")
        ->and(bsEntry('miniature_american_shepherd.suitability.petmd_grooming')['quote'])->toContain('a couple times a week')
        ->and(bsEntry('miniature_american_shepherd.suitability.petmd_children_pets')['quote'])->toContain('supervised initially');
});

it('gives the Chihuahua apartment, other_pets and the older-children / company / shedding chips — never family_pet or children, no welfare chip (runbook 2026-10-13)', function () {
    $chi = app(BreedSuitability::class)->for(BreedType::Chihuahua);
    expect($chi['suits'])->toBe(['apartment', 'other_pets'])
        // AKC "aren’t recommended for homes with children" (S278) → no family_pet / children / small_children.
        ->and($chi['suits'])->not->toContain('family_pet')->not->toContain('children')->not->toContain('small_children')
        // PDSA: does not cope well with even very short periods alone (S277) → never often_alone.
        ->and($chi['suits'])->not->toContain('often_alone')->not->toContain('active_family')->not->toContain('large_home')
        ->and($chi['consider'])->toBe(['older_children', 'needs_company', 'sheds'])
        // Weekly brushing (RKC "Once a week") → no frequent_grooming; not flat-faced and no returned Breed Watch point → no welfare chip;
        // chasing unfamiliar pets is not a "high prey drive" quote → no chases_small_pets; the large-dog chip does not fit a toy dog.
        ->and($chi['consider'])->not->toContain('frequent_grooming')->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')
        ->not->toContain('chases_small_pets')->not->toContain('not_for_small_children')->not->toContain('fragile_older_children')->not->toContain('shorter_lifespan');

    expect(bsEntry('chihuahua.suitability.pdsa_children')['quote'])->toBe("Because of this, they're better suited to families with slightly older children")
        ->and(bsEntry('chihuahua.behaviour.time_alone')['quote'])->toBe('As companion dogs Chihuahuas love company and need someone around the home all the time.')
        ->and(bsEntry('chihuahua.suitability.akc_children')['quote'])->toContain('aren’t recommended for homes with children')
        ->and(bsEntry('chihuahua.suitability.rkc_grooming')['quote'])->toBe('Grooming: Once a week');
});

it('gives the English Springer Spaniel active_family / family_pet / children / other_pets and the exercise, company, shedding and grooming chips — no welfare chip (runbook 2026-10-14)', function () {
    $ess = app(BreedSuitability::class)->for(BreedType::EnglishSpringerSpaniel);
    expect($ess['suits'])->toBe(['active_family', 'family_pet', 'children', 'other_pets'])
        // PDSA names children in general, no rating for small children; RKC "Small house" → neither apartment nor large_home.
        ->and($ess['suits'])->not->toContain('small_children')->not->toContain('apartment')->not->toContain('large_home')
        // AKC: should not be left alone for long periods (S286) → never often_alone.
        ->and($ess['suits'])->not->toContain('often_alone')->not->toContain('first_time_owner')
        ->and($ess['consider'])->toBe(['long_daily_exercise', 'needs_company', 'sheds', 'frequent_grooming'])
        // Breed Watch Category 1 without points of concern → no welfare chip; no prey-drive quote; RKC "Over 10 years".
        ->and($ess['consider'])->not->toContain('hips_hind_legs')->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')
        ->not->toContain('chases_small_pets')->not->toContain('shorter_lifespan')->not->toContain('needs_mental_stimulation');

    expect(bsEntry('english_springer_spaniel.suitability.pdsa_children')['quote'])->toBe('get along well with children and other household pets.')
        ->and(bsEntry('english_springer_spaniel.behaviour.time_alone')['quote'])->toStartWith('Springers should not be left alone for long periods of time')
        ->and(bsEntry('english_springer_spaniel.suitability.rkc_grooming')['quote'])->toBe('Grooming: More than once a week')
        ->and(bsEntry('english_springer_spaniel.suitability.rkc_size_of_home')['quote'])->toBe('Size of home: Small house');
});

it('gives the Shetland Sheepdog family_pet / children and the mental-exercise, herding, grooming and shedding chips — no welfare chip (runbook 2026-10-14)', function () {
    $sheltie = app(BreedSuitability::class)->for(BreedType::ShetlandSheepdog);
    expect($sheltie['suits'])->toBe(['family_pet', 'children'])
        // PDSA names children in general, AKC prefers older children (herding) → may_herd_children, never small_children.
        ->and($sheltie['suits'])->not->toContain('small_children')->not->toContain('apartment')->not->toContain('large_home')
        // No explicit other-pets statement; RKC "Up to 1 hour per day" → no active_family.
        ->and($sheltie['suits'])->not->toContain('other_pets')->not->toContain('active_family')->not->toContain('often_alone')
        ->and($sheltie['consider'])->toBe(['needs_mental_stimulation', 'may_herd_children', 'frequent_grooming', 'sheds'])
        // Breed Watch Category 2 without a named point → no welfare chip; AKC "will chase" is no named prey drive; RKC "Over 10 years".
        ->and($sheltie['consider'])->not->toContain('hips_hind_legs')->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')
        ->not->toContain('chases_small_pets')->not->toContain('shorter_lifespan')->not->toContain('long_daily_exercise')->not->toContain('older_children');

    expect(bsEntry('shetland_sheepdog.suitability.pdsa_family')['quote'])->toBe('Shelties often fit well into family life and get along happily with children.')
        ->and(bsEntry('shetland_sheepdog.behaviour.herding_children')['quote'])->toContain('due to their herding instincts')
        ->and(bsEntry('shetland_sheepdog.suitability.rkc_grooming')['quote'])->toBe('Grooming: Every day')
        ->and(bsEntry('shetland_sheepdog.suitability.rkc_size_of_home')['quote'])->toBe('Size of home: Small house');
});

it('gives the Brittany active_family / family_pet / children and the exercise, mental-stimulation, company, shedding and prey-drive chips — no welfare chip (runbook 2026-10-14)', function () {
    $brittany = app(BreedSuitability::class)->for(BreedType::Brittany);
    expect($brittany['suits'])->toBe(['active_family', 'family_pet', 'children'])
        // PetMD "children of all ages" is no rating → never small_children; RKC "Small house" → neither apartment nor large_home.
        ->and($brittany['suits'])->not->toContain('small_children')->not->toContain('apartment')->not->toContain('large_home')
        // PetMD "other pets" conflicts with its own "high prey drive" → no other_pets; not to be left alone for long → never often_alone.
        ->and($brittany['suits'])->not->toContain('other_pets')->not->toContain('often_alone')->not->toContain('first_time_owner')
        ->and($brittany['consider'])->toBe(['long_daily_exercise', 'needs_mental_stimulation', 'needs_company', 'sheds', 'chases_small_pets'])
        // Breed Watch Category 1 → no welfare chip; RKC "More than once a week" vs AKC / PetMD weekly → no frequent_grooming; RKC "Over 10 years".
        ->and($brittany['consider'])->not->toContain('hips_hind_legs')->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')
        ->not->toContain('frequent_grooming')->not->toContain('shorter_lifespan')->not->toContain('experienced_owner');

    expect(bsEntry('brittany.behaviour.other_pets')['quote'])->toBe('The Brittany was bred to hunt birds, so they are hardwired with a high prey drive.')
        ->and(bsEntry('brittany.suitability.petmd_children')['quote'])->toBe('sweet and well-behaved with children of all ages')
        ->and(bsEntry('brittany.suitability.rkc_exercise')['quote'])->toBe('Exercise: More than 2 hours per day')
        ->and(bsEntry('brittany.suitability.rkc_size_of_home')['quote'])->toBe('Size of home: Small house');
});
