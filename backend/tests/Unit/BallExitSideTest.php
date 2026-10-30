<?php

use App\Services\Media\SchoolClipDerivativeService as D;

/*
|--------------------------------------------------------------------------
| Games phase 1 §8.4 — the ball's exit side, pure decision (synthetic frames)
|--------------------------------------------------------------------------
| exitSideFrom(): the last frame is the empty room; the latest frame (from the
| middle on) that still shows the dog decides left / right by the dog's
| horizontal centre; anything unclear is `center`.
*/

const BES_W = 64;
const BES_H = 64;

/** A textured "room" (deterministic), grey bytes row by row. */
function besRoom(): array
{
    $px = [];
    for ($y = 0; $y < BES_H; $y++) {
        for ($x = 0; $x < BES_W; $x++) {
            $px[] = 60 + (($x * 7 + $y * 13) % 40);
        }
    }

    return $px;
}

/** The room with a bright 10 × 16 "dog" whose left edge is at $x (may be partly outside). */
function besFrame(?int $x, int $y = 30, int $w = 10, int $h = 16, int $shift = 0): string
{
    $px = array_map(fn (int $v) => min(255, $v + $shift), besRoom());
    if ($x !== null) {
        for ($yy = $y; $yy < min(BES_H, $y + $h); $yy++) {
            for ($xx = max(0, $x); $xx < min(BES_W, $x + $w); $xx++) {
                $px[$yy * BES_W + $xx] = 230;
            }
        }
    }

    return pack('C*', ...$px);
}

/** @param list<int|null> $xs dog position per frame (null = gone); the last frame is appended empty */
function besClip(array $xs): array
{
    return [...array_map(fn (?int $x) => besFrame($x), $xs), besFrame(null)];
}

it('says left when the dog runs out on the left', function () {
    // 24 frames: from the middle (x 27) to out of the frame on the left.
    $xs = array_map(fn (int $i) => 27 - (int) round($i * 1.7), range(0, 22));
    expect(D::exitSideFrom(besClip($xs), BES_W, BES_H))->toBe('left');
});

it('says right when the dog runs out on the right', function () {
    $xs = array_map(fn (int $i) => 27 + (int) round($i * 1.7), range(0, 22));
    expect(D::exitSideFrom(besClip($xs), BES_W, BES_H))->toBe('right');
});

it('uses the latest frame that still shows the dog (gone before the last quarter)', function () {
    // Leaves on the right after frame 15 of 24 (before the last quarter); the rest is the empty room.
    $xs = [...array_map(fn (int $i) => 27 + (int) round($i * 2.4), range(0, 15)), ...array_fill(0, 7, null)];
    expect(D::exitSideFrom(besClip($xs), BES_W, BES_H))->toBe('right');
});

it('says center when the dog stays in the middle (runs into the distance)', function () {
    $xs = array_fill(0, 23, 27);
    expect(D::exitSideFrom(besClip($xs), BES_W, BES_H))->toBe('center');
});

it('says center when no frame from the middle on shows the dog', function () {
    $xs = [...array_fill(0, 6, 27), ...array_fill(0, 17, null)];
    expect(D::exitSideFrom(besClip($xs), BES_W, BES_H))->toBe('center');
});

it('says center when the whole scene changes (camera / light), not a dog', function () {
    $frames = [...array_map(fn () => besFrame(null, shift: 80), range(0, 22)), besFrame(null)];
    expect(D::exitSideFrom($frames, BES_W, BES_H))->toBe('center');
});

it('says center when the dog ends near an edge but moved there from the other side', function () {
    // 13 + 10 frames + the empty room → the middle frame (index 12) is the last of the first group.
    // Agreeing: middle on the left (x 0), end on the right (x 40) → right.
    expect(D::exitSideFrom(besClip([...array_fill(0, 13, 0), ...array_fill(0, 10, 40)]), BES_W, BES_H))->toBe('right');
    // Agreeing: middle on the right (x 50), end left of centre (x 14) → left.
    expect(D::exitSideFrom(besClip([...array_fill(0, 13, 50), ...array_fill(0, 10, 14)]), BES_W, BES_H))->toBe('left');
    // Contrary: ends on the left side (x 16, centre ≈ 0.33) but came from further left (x 0) → unclear.
    expect(D::exitSideFrom(besClip([...array_fill(0, 13, 0), ...array_fill(0, 10, 16)]), BES_W, BES_H))->toBe('center');
});

it('is center for too few or malformed frames', function () {
    expect(D::exitSideFrom([besFrame(10), besFrame(null)], BES_W, BES_H))->toBe('center')
        ->and(D::exitSideFrom(['ab', 'cd', 'ef'], BES_W, BES_H))->toBe('center');
});
