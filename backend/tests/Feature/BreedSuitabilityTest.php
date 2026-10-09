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
    expect($known)->toContain('S4', 'S50', 'S60');

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
        'suits' => ['active_family', 'children', 'house_with_garden', 'other_pets'],
        'consider' => ['sheds', 'long_daily_exercise', 'food_motivated_weight'],
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

it('types the API field with exactly the vocabulary (Scramble → mobile schema.ts)', function () {
    $source = (string) file_get_contents(app_path('Http/Resources/BreedCatalogResource.php'));
    preg_match('/@var array\{suits: list<([^>]+)>, consider: list<([^>]+)>\}/', $source, $m);
    expect($m)->toHaveCount(3);

    $union = fn (string $s): array => array_map(fn (string $v) => trim($v, " '"), explode('|', $s));
    $vocabulary = BreedSuitability::vocabulary();

    expect($union($m[1]))->toEqualCanonicalizing(array_keys(array_filter($vocabulary, fn ($k) => $k === BreedSuitability::SUITS)))
        ->and($union($m[2]))->toEqualCanonicalizing(array_keys(array_filter($vocabulary, fn ($k) => $k === BreedSuitability::CONSIDER)));
});
