<?php

use App\Enums\PushType;
use App\Enums\Species;
use App\Services\Push\PushCopy;

/*
|--------------------------------------------------------------------------
| M5-R06-06 — dog push texts are byte-identical (regression snapshot)
|--------------------------------------------------------------------------
|
| `tests/Fixtures/dog_push_texts_snapshot.json` was recorded on `main`
| (after M5-R06-05, before any M5-R06-06 change) with
| PETPREP_WRITE_SNAPSHOT=1. It holds every push body a dog can get: every
| push type a dog can be sent (not the cat-only play / litter reminders) ×
| metric × audience × locale × M3-12 variant (phase 1 / 2 only; the cat-only
| scratcher variants are left out — a dog never scratches). Every value must
| stay byte-identical, both with the default species and with Species::Dog
| passed explicitly.
|
| Re-recorded once on purpose (M5-R06-06b, David 2026-10-09): the Slovenian
| parent alarm is formal ("Vaš otrok …") and the new tidy-first / *_first_wait
| variants were added. Every other value is unchanged from the first recording.
*/

const DPT_FIXTURE = __DIR__.'/../Fixtures/dog_push_texts_snapshot.json';

/** @return array<string, string> */
function dptRender(?Species $species): array
{
    $types = array_values(array_filter(PushType::cases(), fn (PushType $t): bool => ! in_array($t, [PushType::PlayReminder, PushType::LitterReminder], true)));
    $metrics = [null, 'hunger', 'thirst', 'hygiene', 'energy', 'walk', 'no_trial'];
    $variants = [null, PushCopy::VARIANT_WAIT, PushCopy::VARIANT_CLEAN_FIRST, PushCopy::VARIANT_TIDY, PushCopy::VARIANT_CLEAN_AND_TIDY,
        // M5-R06-06b (David 2026-10-09): tidy first + the *_first_wait variants.
        PushCopy::VARIANT_TIDY_FIRST, PushCopy::VARIANT_CLEAN_AND_TIDY_FIRST,
        PushCopy::VARIANT_CLEAN_FIRST_WAIT, PushCopy::VARIANT_TIDY_FIRST_WAIT, PushCopy::VARIANT_CLEAN_AND_TIDY_FIRST_WAIT];

    $out = [];
    foreach ($types as $type) {
        $phase = in_array($type, [PushType::SoftWarning, PushType::CriticalAlert], true);
        foreach ($metrics as $metric) {
            foreach (['child', 'parent'] as $audience) {
                foreach (['en', 'sl'] as $locale) {
                    foreach ($phase ? $variants : [null] as $variant) {
                        $key = implode('|', [$type->value, $metric ?? '-', $audience, $locale, $variant ?? '-']);
                        $out[$key] = $species === null
                            ? PushCopy::body($type, $metric, $audience, $locale, $variant, ['time' => '17:00'])
                            : PushCopy::body($type, $metric, $audience, $locale, $variant, ['time' => '17:00'], $species);
                    }
                }
            }
        }
    }
    $out['title|en'] = PushCopy::title('en');
    $out['title|sl'] = PushCopy::title('sl');

    return $out;
}

it('keeps every dog push text byte-identical (M5-R06-06)', function () {
    $actual = dptRender(null);

    if (getenv('PETPREP_WRITE_SNAPSHOT') === '1') {
        @mkdir(dirname(DPT_FIXTURE), 0777, true);
        file_put_contents(DPT_FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        $this->markTestSkipped('Snapshot written to '.DPT_FIXTURE);
    }

    expect(file_exists(DPT_FIXTURE))->toBeTrue('Record the fixture first: PETPREP_WRITE_SNAPSHOT=1');
    $expected = json_decode((string) file_get_contents(DPT_FIXTURE), true);

    // Not a vacuous snapshot: the spec sentences are in it.
    expect($expected['soft_warning|hunger|child|sl|-'])->toBe('Tvoj kuža te milo gleda in kaže na posodo s hrano.')
        ->and($expected['parent_intervention_alarm|hunger|parent|sl|-'])->toStartWith('Vaš otrok danes ni poskrbel za psa.')
        ->and(count($expected))->toBeGreaterThan(400);

    expect($actual)->toBe($expected);
    expect(dptRender(Species::Dog))->toBe($expected);
});
