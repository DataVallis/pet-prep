<?php

use App\Services\Media\Mp4FastStart;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

/*
 * Incident 2026-10-20: fal's Kling MP4s arrived with the index (moov) after the
 * media data; the app's player could not open them. Mp4FastStart moves moov
 * first and shifts every chunk offset by the moov size.
 */

function fsBox(string $type, string $body): string
{
    return pack('N', 8 + strlen($body)).$type.$body;
}

function fsFull(string $type, string $body): string
{
    return fsBox($type, "\x00\x00\x00\x00".$body);
}

/**
 * ftyp + free + mdat(two chunks) + moov (one trak with stco, one with co64).
 *
 * @return array{0: string, 1: list<string>} file bytes, chunk payloads
 */
function fsMovieMoovLast(): array
{
    $chunks = ['CHUNK-ONE-video', 'CHUNK-TWO-audio!!'];
    $ftyp = fsBox('ftyp', 'isom'.pack('N', 512).'isomiso2avc1mp41');
    $free = fsBox('free', '');
    $mdat = fsBox('mdat', $chunks[0].$chunks[1]);
    $first = strlen($ftyp) + strlen($free) + 8;
    $second = $first + strlen($chunks[0]);

    $trak = fn (string $table) => fsBox('trak', fsFull('tkhd', str_repeat("\x00", 80)).fsBox('mdia', fsBox('minf', fsBox('stbl', $table))));
    $moov = fsBox('moov',
        fsFull('mvhd', str_repeat("\x00", 96))
        .$trak(fsFull('stco', pack('N', 1).pack('N', $first)))
        .$trak(fsFull('co64', pack('N', 1).pack('J', $second)))
        .fsBox('udta', 'not-a-box')
    );

    return [$ftyp.$free.$mdat.$moov, $chunks];
}

/** @return list<int> chunk offsets in table order */
function fsOffsets(string $data): array
{
    preg_match_all('/(stco|co64)\x00\x00\x00\x00\x00\x00\x00\x01/', $data, $m, PREG_OFFSET_CAPTURE);

    return array_map(fn ($hit) => $hit[0] === 'stco'
        ? unpack('N', substr($data, $hit[1] + 12, 4))[1]
        : unpack('J', substr($data, $hit[1] + 12, 8))[1], $m[1]);
}

it('moves moov before mdat and shifts stco / co64 offsets onto the same bytes', function () {
    [$data, $chunks] = fsMovieMoovLast();
    $fast = new Mp4FastStart;

    expect($fast->needsFastStart($data))->toBeTrue();

    $out = $fast->convert($data);

    expect($out)->not->toBeNull()
        ->and(strlen($out))->toBe(strlen($data))
        ->and($fast->needsFastStart($out))->toBeFalse()
        ->and(strpos($out, 'moov'))->toBeLessThan(strpos($out, 'mdat'))
        ->and(substr($out, 4, 4))->toBe('ftyp');

    [$a, $b] = fsOffsets($out);
    expect(substr($out, $a, strlen($chunks[0])))->toBe($chunks[0])
        ->and(substr($out, $b, strlen($chunks[1])))->toBe($chunks[1]);
});

it('leaves a faststart file untouched and refuses what it cannot parse', function () {
    [$data] = fsMovieMoovLast();
    $fast = new Mp4FastStart;
    $already = $fast->convert($data);

    expect($fast->convert($already))->toBe($already)
        ->and($fast->convert('not an mp4 at all'))->toBeNull()
        ->and($fast->convert(substr($data, 0, -5)))->toBeNull();
});

it('rewrites a file in place only when needed', function () {
    [$data] = fsMovieMoovLast();
    $path = tempnam(sys_get_temp_dir(), 'fs');
    file_put_contents($path, $data);
    $fast = new Mp4FastStart;

    expect($fast->fileLayout($path))->toBeTrue()
        ->and($fast->process($path))->toBe(Mp4FastStart::RESULT_FIXED)
        ->and($fast->fileLayout($path))->toBeFalse()
        ->and($fast->process($path))->toBe(Mp4FastStart::RESULT_ALREADY)
        ->and(file_exists($path.'.faststart'))->toBeFalse();

    file_put_contents($path, 'garbage');
    expect($fast->process($path))->toBe(Mp4FastStart::RESULT_SKIPPED)
        ->and(file_get_contents($path))->toBe('garbage');

    @unlink($path);
});

it('media:faststart fixes stored videos (looks too) and leaves the rest', function () {
    Storage::fake('pet_media');
    [$data] = fsMovieMoovLast();
    $disk = Storage::disk('pet_media');
    $disk->put('looks/5/idle-puppy-g1.mp4', $data);
    $disk->put('12/idle-g1.mp4', (new Mp4FastStart)->convert($data));
    $disk->put('12/reference-g1.jpg', 'jpeg');

    $this->artisan('media:faststart --dry-run')->assertSuccessful()
        ->expectsOutputToContain('would fix looks/5/idle-puppy-g1.mp4');
    expect($disk->get('looks/5/idle-puppy-g1.mp4'))->toBe($data);

    $this->artisan('media:faststart')->assertSuccessful()
        ->expectsOutputToContain('Fixed 1, already faststart 1, skipped 0.');
    expect((new Mp4FastStart)->needsFastStart($disk->get('looks/5/idle-puppy-g1.mp4')))->toBeFalse();
});

it('is scheduled hourly', function () {
    $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command.' '.$e->expression);

    expect($events->first(fn ($e) => str_contains($e, 'media:faststart')))->toContain('17 * * * *');
});
