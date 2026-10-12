<?php

use App\Models\QuietHours;
use App\Services\DecayMath;
use Illuminate\Support\Carbon;

/*
| M5-F11: the decay formula shared by the tick and the water forecast
| (DecayMath). Pure — quiet hours are an unsaved model in Europe/Ljubljana
| (no family → the default timezone).
*/

function dmQuiet(array $attributes = []): QuietHours
{
    return new QuietHours(array_merge([
        'school_start' => null,
        'school_end' => null,
        'bedtime_start' => '21:00',
        'bedtime_end' => '07:00',
        'is_active' => true,
    ], $attributes));
}

function dmLocal(string $local): Carbon
{
    return Carbon::parse($local, 'Europe/Ljubljana');
}

it('weights quiet seconds at the quiet-hours multiplier', function () {
    // 20:00 → 23:00: 1 normal hour + 2 quiet hours × 0.1 = 1.2 weighted hours.
    expect(DecayMath::weightedHours(dmQuiet(), dmLocal('2026-10-04 20:00'), dmLocal('2026-10-04 23:00')))->toEqualWithDelta(1.2, 1e-9)
        ->and(DecayMath::weightedHours(null, dmLocal('2026-10-04 20:00'), dmLocal('2026-10-04 23:00')))->toEqualWithDelta(3.0, 1e-9);
});

it('clamps to 0–100 and snaps float noise onto integers', function () {
    expect(DecayMath::decay(5.0, 10.0))->toBe(0.0)
        ->and(DecayMath::decay(100.0, -5.0))->toBe(100.0)
        ->and(DecayMath::decay(60.0000000001, 0.0))->toBe(60.0)
        ->and(DecayMath::decay(70.0, 9.5))->toBe(60.5);
});

it('finds the first minute the displayed value is at or below the threshold (normal time)', function () {
    // 72 % at 17:00, −10 %/h: shows 61 at 18:09 (60.5 rounds up), 60 at 18:10.
    $at = DecayMath::firstMinuteAtOrBelow(72.0, 10.0, 60, dmLocal('2026-10-04 17:00'), dmQuiet());

    expect($at?->setTimezone('Europe/Ljubljana')->format('Y-m-d H:i:s'))->toBe('2026-10-04 18:10:00');
});

it('decays slower across quiet hours (×0.1)', function () {
    // 70.21 % at 20:30: 65.21 at 21:00, then −1 %/h → < 60.5 after 282.6 min → 01:43
    // (without quiet hours: 9.71 % at −10 %/h = 58.3 min → 21:29).
    $at = DecayMath::firstMinuteAtOrBelow(70.21, 10.0, 60, dmLocal('2026-10-04 20:30'), dmQuiet());
    $noQuiet = DecayMath::firstMinuteAtOrBelow(70.21, 10.0, 60, dmLocal('2026-10-04 20:30'), null);

    expect($at?->setTimezone('Europe/Ljubljana')->format('Y-m-d H:i'))->toBe('2026-10-05 01:43')
        ->and($noQuiet?->setTimezone('Europe/Ljubljana')->format('Y-m-d H:i'))->toBe('2026-10-04 21:29');
});

it('returns the start instant when the value already shows at or below the threshold', function () {
    $from = dmLocal('2026-10-04 17:00:30');

    expect(DecayMath::firstMinuteAtOrBelow(60.4, 10.0, 60, $from, null)?->equalTo($from))->toBeTrue();
});

it('rounds a start with seconds up to the next whole minute', function () {
    // 61 % at 17:00:30, −10 %/h: < 60.5 after 3 min → 17:03:30 → first whole minute 17:04.
    $at = DecayMath::firstMinuteAtOrBelow(61.0, 10.0, 60, dmLocal('2026-10-04 17:00:30'), null);

    expect($at?->setTimezone('Europe/Ljubljana')->format('H:i:s'))->toBe('17:04:00');
});

it('gives no time for a zero rate or beyond the 48 h horizon', function () {
    expect(DecayMath::firstMinuteAtOrBelow(90.0, 0.0, 60, dmLocal('2026-10-04 17:00'), null))->toBeNull()
        // 0.5 %/h: 90 → 60 needs 59 h — beyond the horizon.
        ->and(DecayMath::firstMinuteAtOrBelow(90.0, 0.5, 60, dmLocal('2026-10-04 17:00'), null))->toBeNull();
});
