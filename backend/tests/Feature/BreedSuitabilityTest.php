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
            'consider' => ['brachycephalic_breathing'],
        ])
        ->and($suitability->for(BreedType::BorderCollie))->toBe([
            'suits' => ['active_family'],
            'consider' => ['long_daily_exercise', 'needs_mental_stimulation', 'may_herd_children', 'chews_when_bored'],
        ]);
    foreach ([BreedType::Mutt, BreedType::DomesticCat, BreedType::MaineCoon] as $breed) {
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
        ->and($frenchie['consider'])->toBe(['brachycephalic_breathing']);

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
        ->and($dachshund['consider'])->toBe(['back_spine', 'sheds', 'needs_mental_stimulation'])
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
        ->and($westie['consider'])->toBe(['sensitive_skin', 'sheds', 'frequent_grooming', 'chews_when_bored'])
        ->and($westie['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('long_daily_exercise');

    // Only the Westie carries the new chip so far.
    foreach (BreedType::cases() as $breed) {
        if ($breed !== BreedType::WestHighlandWhiteTerrier) {
            expect(app(BreedSuitability::class)->for($breed)['consider'])->not->toContain('sensitive_skin');
        }
    }

    expect(bsEntry('west_highland_white_terrier.health.sensitive_skin')['quote'])->toBe('Westies are known to suffer from skin allergies, so speak to your vet before buying a shampoo.')
        ->and(bsEntry('west_highland_white_terrier.health.sensitive_skin')['notes'])->toContain('Signs of dermatitis irritation')
        ->and(bsEntry('west_highland_white_terrier.suitability.pdsa_children')['notes'])->toContain('not small_children');
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
