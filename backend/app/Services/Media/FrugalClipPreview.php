<?php

namespace App\Services\Media;

use App\Enums\Species;
use App\Models\Pet;
use App\Models\PetLook;
use App\Models\PetMedia;
use Carbon\CarbonImmutable;
use Illuminate\Http\File;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Frugal clips — ZERO-COST preview (David 2026-10-30): before the superadmin switch
 * `frugal_clips` is turned on, David judges savings B and C on clips that are ALREADY
 * STORED: `idle_to_sleeping` reversed (what `sleeping_to_idle` would become) and the
 * ping-pong of the first 3 s of the `sleeping` / `low_energy` / `sick` loops (what a 3 s
 * loop would look like). Made with the same ffmpeg recipes as the real derived clips
 * (SchoolClipDerivativeService::ffmpegArgs, full size, CRF 21) — no fal call, nothing
 * through AiSpendGuard / ai_spend_ledger, no table row.
 *
 * Files go under PREFIX on the media disk (overwritten by the next preview of the same
 * row, deleted after `media.state_media.frugal_preview_keep_hours` by the daily
 * `media:frugal-preview --prune`); they are never in a payload and never shown to children.
 * The signed URLs (`media.frugal-preview`, like the quality-check candidate / contact sheet)
 * are issued only by the console command `media:frugal-preview`, i.e. to a superadmin — they
 * are CAPABILITY URLs: whoever has one can open that preview until it expires (QA #451 m8),
 * so they are never posted anywhere shared.
 *
 * Dogs only (David 2026-10-30, QA #451 major 1). ffmpeg runs niced + memory-capped and under
 * the shared heavy lock, like the derived clips.
 */
class FrugalClipPreview
{
    public const PREFIX = 'previews/frugal';

    /** The length of a frugal short loop (media.state_media.clip_seconds.state_short). */
    public const SHORT_SECONDS = 3;

    public function __construct(
        private readonly PetMediaService $media,
        private readonly SchoolClipDerivativeService $derivatives,
        private readonly MediaClipAnalyzer $analyzer,
    ) {}

    /** `previews/frugal/{row id}-{key}.mp4` — deterministic, so the route needs no table. */
    public static function pathFor(int $mediaId, string $key): string
    {
        return self::PREFIX.'/'.$mediaId.'-'.$key.'.mp4';
    }

    /** @return list<string> preview keys: the reversed transition + one ping-pong per low-motion loop */
    public static function keys(): array
    {
        return [
            ...array_keys(MediaEntitlementService::FRUGAL_REVERSED_TRANSITIONS),
            ...array_map(fn (string $s) => $s.'_'.SchoolClipDerivativeService::SUFFIX_LOOP, MediaEntitlementService::shortLoopStates()),
        ];
    }

    /**
     * The stored rows a preview is made from: the files a look (all its stages) or a pet
     * shows — always the row OWNING the file (a pool pet's slot → its look row).
     *
     * @return Collection<int, PetMedia>
     */
    public function sources(?int $lookId, ?int $petId): Collection
    {
        $wanted = fn ($q) => $q->where(fn ($q) => $q
            ->where(fn ($q) => $q->where('kind', PetMedia::KIND_TRANSITION)->whereIn('state', array_values(MediaEntitlementService::FRUGAL_REVERSED_TRANSITIONS)))
            ->orWhere(fn ($q) => $q->where('kind', PetMedia::KIND_VIDEO)->whereIn('state', MediaEntitlementService::shortLoopStates())))
            ->whereNotNull('storage_path');

        if ($lookId !== null) {
            $look = PetLook::find($lookId);
            if ($look === null || $look->breed_type->species() !== Species::Dog) {
                return new Collection;
            }

            return PetMedia::query()->where('pet_look_id', $lookId)->where($wanted)->orderBy('life_stage')->orderBy('id')->get();
        }

        if (Pet::find((int) $petId)?->speciesValue() !== Species::Dog) {
            return new Collection;
        }

        return PetMedia::query()->where('pet_id', (int) $petId)->where($wanted)->orderBy('id')->get()
            ->map(fn (PetMedia $m) => $m->look_media_id !== null ? PetMedia::find($m->look_media_id) : $m)
            ->filter(fn (?PetMedia $m) => $m !== null && filled($m->storage_path))
            ->unique('id')
            ->values();
    }

    /**
     * Make the previews and return them with a signed URL each.
     *
     * @return list<array{source_id: int, state: string, life_stage: string|null, key: string, ok: bool, seconds: float|null, url: string|null, error: string|null}>
     */
    public function make(?int $lookId, ?int $petId, int $minutes): array
    {
        $expires = CarbonImmutable::now()->addMinutes(max(1, $minutes));
        $out = [];

        foreach ($this->sources($lookId, $petId) as $source) {
            $reverse = $source->isTransition();
            $key = $reverse
                ? (string) array_search((string) $source->state, MediaEntitlementService::FRUGAL_REVERSED_TRANSITIONS, true)
                : $source->state.'_'.SchoolClipDerivativeService::SUFFIX_LOOP;
            $row = ['source_id' => (int) $source->id, 'state' => (string) $source->state, 'life_stage' => $source->life_stage, 'key' => $key,
                'ok' => false, 'seconds' => null, 'url' => null, 'error' => null];

            try {
                $seconds = $this->render($source, $key, $reverse);
                $row = ['ok' => true, 'seconds' => $seconds, 'url' => $this->signedUrl((int) $source->id, $key, $expires)] + $row;
            } catch (Throwable $e) {
                $row['error'] = mb_substr($e->getMessage(), 0, 300);
            }

            $out[] = $row;
        }

        return $out;
    }

    /**
     * Delete previews older than `media.state_media.frugal_preview_keep_hours` (QA #451 m7; the
     * daily `media:frugal-preview --prune`). Returns how many files were deleted.
     */
    public function prune(): int
    {
        $disk = $this->media->disk();
        $before = CarbonImmutable::now()->subHours(max(1, (int) config('media.state_media.frugal_preview_keep_hours', 24)))->getTimestamp();
        $deleted = 0;

        foreach ($disk->files(self::PREFIX) as $path) {
            if ($disk->lastModified($path) < $before) {
                $disk->delete($path);
                $deleted++;
            }
        }

        return $deleted;
    }

    /** Signed URL to GET /api/media/{media}/frugal-preview/{key} (absolute, like the derived clips). */
    public function signedUrl(int $mediaId, string $key, CarbonImmutable $expires): string
    {
        $path = URL::temporarySignedRoute('media.frugal-preview', $expires, ['media' => $mediaId, 'key' => $key], absolute: false);

        return rtrim((string) config('app.url'), '/').$path;
    }

    /** @return float|null the preview's length in seconds */
    private function render(PetMedia $source, string $key, bool $reverse): ?float
    {
        if (! $this->analyzer->available()) {
            throw new \RuntimeException('ffmpeg / ffprobe not available');
        }

        $in = $this->derivatives->localCopy((string) $source->storage_path);
        if ($in === null) {
            throw new \RuntimeException('source file missing on the media disk');
        }
        $out = $in.'-preview.mp4';

        try {
            $probe = $this->derivatives->probe($in);
            if ($probe === null || $probe['frames'] < 3) {
                throw new \RuntimeException('source not measurable (ffprobe)');
            }

            // C: the first 3 s only — what a frugal short loop would have been.
            $maxFrames = $reverse ? null : (int) round(self::SHORT_SECONDS * ($probe['fps'] > 0 ? $probe['fps'] : 24.0));
            $args = [...SchoolClipDerivativeService::limits(), ...SchoolClipDerivativeService::ffmpegArgs(
                (string) config('media.quality_check.ffmpeg', 'ffmpeg'), $in, $out, $probe,
                $reverse ? SchoolClipDerivativeService::MODE_REVERSE : SchoolClipDerivativeService::MODE_LOOP,
                $maxFrames, homeScreen: true,
            )];
            // One heavy ffmpeg job at a time on the host (waits for a running derivative / compression).
            $result = Cache::lock(SchoolClipDerivativeService::HEAVY_LOCK, 300)->block(120,
                fn () => Process::timeout(SchoolClipDerivativeService::PROCESS_TIMEOUT_SECONDS)->run($args));
            if (! $result->successful() || ! is_file($out) || filesize($out) === 0) {
                throw new \RuntimeException('ffmpeg failed: '.mb_substr(trim($result->errorOutput()), -300));
            }

            $made = $this->derivatives->probe($out);
            $path = self::pathFor((int) $source->id, $key);
            $this->media->disk()->putFileAs(dirname($path), new File($out), basename($path));

            return $made === null ? null : round($made['duration'], 3);
        } finally {
            foreach ([$in, $out] as $tmp) {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        }
    }
}
