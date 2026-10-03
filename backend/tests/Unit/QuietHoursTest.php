<?php

use App\Models\QuietHours;
use Illuminate\Support\Carbon;

/*
| Pure unit tests for the quiet-hours window logic (no database needed).
*/

function quietHours(array $attributes = []): QuietHours
{
    return new QuietHours(array_merge([
        'school_start' => '08:00',
        'school_end' => '13:00',
        'bedtime_start' => '22:00',
        'bedtime_end' => '06:00',
        'is_active' => true,
    ], $attributes));
}

it('is quiet during school hours and bedtime, including across midnight', function (string $time, bool $expected) {
    expect(quietHours()->isQuietNow(Carbon::parse("2026-10-02 {$time}", 'Europe/Ljubljana')))->toBe($expected);
})->with([
    'before school' => ['07:59', false],
    'school starts' => ['08:00', true],
    'during school' => ['10:30', true],
    'school ends (exclusive)' => ['13:00', false],
    'afternoon' => ['17:00', false],
    'bedtime starts' => ['22:00', true],
    'after midnight' => ['02:15', true],
    'bedtime ends (exclusive)' => ['06:00', false],
]);

it('is never quiet when disabled', function () {
    expect(quietHours(['is_active' => false])->isQuietNow(Carbon::parse('2026-10-02 10:00', 'Europe/Ljubljana')))->toBeFalse();
});

it('ignores windows with a missing bound', function () {
    expect(quietHours(['school_end' => null])->isQuietNow(Carbon::parse('2026-10-02 10:00', 'Europe/Ljubljana')))->toBeFalse();
});
