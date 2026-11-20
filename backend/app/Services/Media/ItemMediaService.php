<?php

namespace App\Services\Media;

use App\Enums\AdminAlertKind;
use App\Enums\AiCallFailure;
use App\Enums\AiSpendPurpose;
use App\Enums\BreedType;
use App\Enums\LifeStage;
use App\Enums\Species;
use App\Enums\StageParamKey;
use App\Events\PetUpdated;
use App\Jobs\CheckItemMediaQuality;
use App\Jobs\GenerateItemMedia;
use App\Jobs\SendItemMediaAlert;
use App\Jobs\SendTreatsWaitingAlert;
use App\Jobs\StoreItemMedia;
use App\Models\AiSpendLedger;
use App\Models\ItemMedia;
use App\Models\ItemMediaNotice;
use App\Models\Pet;
use App\Models\PetItem;
use App\Models\PetLook;
use App\Models\PetMedia;
use App\Models\ShopItem;
use App\Services\AppSettingsService;
use App\Services\FalAiService;
use App\Services\LifeStageService;
use App\Services\NotificationService;
use App\Services\Shop\ItemDesigns;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\File;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * M6-W03 »AI vsebine stvari« (SHOP_SPEC §3 step 4 / §5, David 2026-11-03) — the AI content of
 * the »Želje« items. The only writer of `item_media` / `item_media_notices`.
 *
 * What is made (all through FalGateway, purpose `item_media`, production budget):
 *  - one CATALOGUE picture per design (config/shop_designs.php) — the object alone, made once
 *    (`media:item-media`, every 10 min), shown in the wish catalogues;
 *  - per owned item (its design; treats: the species' treat) and the pet's CURRENT picture
 *    (the reference image of its life stage): a KEY IMAGE (Nano Banana Pro Edit of that
 *    picture, the design's catalogue picture as the 2nd reference) and a CLIP (Kling, start
 *    frame = the key image only, `clip_seconds.item`). Bed / toys loop, bowls / treats play once.
 *    The bowls (ItemMedia::DRINK_ITEMS, David 2026-11-20 »1+A«) get a second clip from the SAME key
 *    image — the animal DRINKING from the water bowl (kind `drink_clip`): feed plays the eating clip,
 *    water the drinking clip. The drinking clip never decides `media_ready` (feed / water are core
 *    care, never locked); payload `drink_video` / `drink_video_seconds` stay null until it is shown.
 *
 * Sharing: a pet of a shared look (pool / paid breed + colour) gets the LOOK rows of
 * (look, design, stage) — made once, every later buyer of that design for that look and
 * stage costs 0 $. A pet with its own media (unique DNA) gets its own rows. A new life stage
 * (a new reference image) makes the content stale → new content for the new stage (shared
 * again). Identity of a source = its storage_path (`source_path` / `target_path`).
 *
 * Readiness (`media_ready`, payload `media.items`): content (the clip of the current picture
 * is stored) · fallback (recovery exhausted — the item is shown without that content; the key
 * image alone when only the clip failed) · no_media (the pet gets no AI media: AI off, media
 * disabled, its own picture given up) · waiting (»Na poti …«; an ACTION item's action is
 * disabled meanwhile). Free plan (S8): only the item's
 * own content — the pet's state media set is never touched. Items never touch game rules (S1).
 *
 * Self-healing like ActivityClipRetryService (PR #547): failures with http_error /
 * invalid_response / generation_failed are retried ≥ RETRY_AFTER minutes later, at most
 * MAX_AUTO_RETRIES per failure chain; fal's `no_media_generated` is never sent identically again
 * (the next prompt variant; none left → exhausted); `timed_out` → exhausted (a late webhook still
 * stores); budget / balance / switched-off failures wait for money (no retry counted). Exhausted
 * → the item is shown without that content + ONE Discord alert per run (counts only). No admin
 * click is ever needed for a durable item; /admin can Regenerate a row or »Generate missing« for
 * a pet. ACTION items (treats — {@see ACTION_ITEMS}, David 2026-11-15) are the exception: their
 * action is disabled until the clip exists ({@see actionReady()}), so an exhausted treat chain
 * keeps »Priboljšek« locked (the treats stay on the pet) until the admin regenerates it.
 *
 * Quality check (David 2026-11-04, StillQualityService): a downloaded catalogue picture / key
 * image (stills mode on) or clip (clip mode on) is a CANDIDATE (`candidate_path`) until its check
 * passes — the AI vision check for stills, the free technical check + (since 2026-11-19) the AI
 * realism check for clips — so a clip is only made from a passed key image. A file flagged by a
 * check of the stored file is never shown (ItemMedia::isShown()); a clip whose OBJECT is wrong
 * regenerates its key image (regenerateKeyImageOf()). A failed check regenerates with a fresh seed (and the next
 * prompt variant) ≤ `max_still_retries` per chain, then the row fails `quality_failed` (candidate
 * kept for »Approve anyway«) and heal() gives it up like any other failure (fallback + alert).
 * Every regeneration (admin Regenerate, Generate missing, heal, check retry) gets a fresh seed.
 */
class ItemMediaService
{
    /** Bed and toys loop; bowls and treats are played once. */
    public const LOOP_ITEMS = ['bed', 'toy_1', 'toy_2'];

    /**
     * Items whose child ACTION plays their clip (»Priboljšek«). David 2026-11-15: a feature whose
     * clip does not exist yet is disabled (»se pripravlja«) — never usable without its animation.
     * Such an item is never »shown without its content« once the recovery gave up: it stays
     * WAITING (the action stays locked, the bought treats stay on the pet) until its clip is there
     * — the next automatic chain (a new picture / stage) or the admin's Regenerate / »Approve
     * anyway« after the Discord alert. Durable items have no action of their own (bed, bowls, toys
     * only dress a scene that works without them), so they keep the fallback.
     */
    public const ACTION_ITEMS = [ShopItem::TREATS];

    /** Cache claim of the daily watchdog alert ({@see watch()}), + the Ljubljana date. */
    public const WATCHDOG_CLAIM = 'item-media:treats-watchdog:';

    public const OUTCOME_CONTENT = 'content';

    public const OUTCOME_FALLBACK = 'fallback';

    public const OUTCOME_NO_MEDIA = 'no_media';

    public const OUTCOME_WAITING = 'waiting';

    /** A running row without a fal answer for this long belongs to a dead worker. */
    public const STALE_CLAIM_MINUTES = 10;

    /** A queued request without a webhook for this long is polled at fal (free). */
    public const POLL_AFTER_MINUTES = 10;

    /** A queued request without a result for this long is given up (`timed_out`). */
    public const TIMEOUT_MINUTES = 120;

    /** QA #655 m5: rounds of our own download (each = StoreItemMedia's tries) before the row fails and is regenerated. */
    public const DOWNLOAD_ROUNDS = 3;

    /** Failures that wait for money / the switch and are never counted as a retry. */
    public const WAITING_REASONS = [AiCallFailure::BudgetDaily, AiCallFailure::BudgetMonthly, AiCallFailure::BudgetRun, AiCallFailure::FalBalance, AiCallFailure::Disabled];

    /** Failures retried up to MAX_AUTO_RETRIES times per chain. */
    public const RETRY_REASONS = [AiCallFailure::HttpError, AiCallFailure::InvalidResponse, AiCallFailure::GenerationFailed];

    public function __construct(
        private readonly PetMediaService $media,
        private readonly FalAiService $fal,
        private readonly MediaProfiles $profiles,
        private readonly PetAppearancePrompt $prompts,
        private readonly MediaDownloader $downloader,
        private readonly Mp4FastStart $fastStart,
        private readonly FalGateway $gateway,
        private readonly AiSpendGuard $guard,
        private readonly NotificationService $notifications,
    ) {}

    public static function maxAutoRetries(): int
    {
        return max(0, (int) config('media.item_media.max_auto_retries', 2));
    }

    public static function retryAfterMinutes(): int
    {
        return max(0, (int) config('media.item_media.retry_after_minutes', 30));
    }

    /** fal + the item image profile usable — otherwise no item content is made at all (no_media). */
    public function enabled(): bool
    {
        return $this->fal->isEnabled() && $this->profiles->itemImage() !== null;
    }

    /**
     * QA #655 m4: the clip profile (the state video profile at `clip_seconds.item`) is usable. While
     * it is not, no clip is made and an item whose key image is shown counts as shown (fallback
     * with the picture) — never »Na poti« for ever.
     */
    public function clipsEnabled(): bool
    {
        return $this->fal->isEnabled() && $this->profiles->clipVideo('item')->enabled;
    }

    // ──────────────────────────────────────────────────────────────
    //  Owned content of a pet + readiness
    // ──────────────────────────────────────────────────────────────

    /**
     * The items whose content the pet should show: durable items it owns (their design) and,
     * when it has an unrevoked treats pack, the treats. Keyed by item key.
     *
     * @return array<string, array{item_key: string, design: string|null, content_key: string}>
     */
    public function ownedContent(Pet $pet): array
    {
        $species = $pet->speciesValue();
        $owned = [];

        PetItem::where('pet_id', $pet->id)->whereNull('revoked_at')->orderBy('acquired_at')->orderBy('id')->get()
            ->each(function (PetItem $item) use (&$owned, $species): void {
                if ($item->item_key === ShopItem::TREATS) {
                    $owned[ShopItem::TREATS] ??= ['item_key' => ShopItem::TREATS, 'design' => null, 'content_key' => ItemDesigns::treatKey($species)];

                    return;
                }
                $key = ItemDesigns::contentKey($item->item_key, $species, $item->design);
                if ($key !== null && $item->kind === ShopItem::KIND_DURABLE) {
                    $owned[$item->item_key] = ['item_key' => $item->item_key, 'design' => $key, 'content_key' => $key];
                }
            });

        return $owned;
    }

    /**
     * The picture the pet shows now (its reference image of the current stage): the pet's image
     * slot when stored. `scope` look = the pet shows its shared look's file (the item content is
     * the look's); `source` = the row fal edits (look row or the pet's own slot).
     *
     * @return array{scope: string, look_id: int|null, stage: string|null, path: string, source: PetMedia}|null
     */
    public function pictureOf(Pet $pet, ?Collection $slots = null): ?array
    {
        $slot = $slots !== null
            ? $slots->first(fn (PetMedia $m) => $m->isImage())
            : PetMedia::query()->where('pet_id', $pet->id)->images()->first();

        if ($slot === null || ! $slot->isServable()) {
            return null;
        }

        if ($pet->pet_look_id !== null && $slot->look_media_id !== null) {
            $row = PetMedia::find($slot->look_media_id);

            if ($row === null || $row->storage_path !== $slot->storage_path || $row->life_stage === null) {
                return null; // the pet still shows an older file of the look — its fan-out follows
            }

            return ['scope' => ItemMedia::SCOPE_LOOK, 'look_id' => (int) $pet->pet_look_id, 'stage' => $row->life_stage, 'path' => (string) $slot->storage_path, 'source' => $row];
        }

        return ['scope' => ItemMedia::SCOPE_PET, 'look_id' => null, 'stage' => $slot->life_stage, 'path' => (string) $slot->storage_path, 'source' => $slot];
    }

    /**
     * The pet gets no AI media (content never comes): fal / the item profile off, media
     * disabled for the pet, or its own picture given up for good (failed, not waiting for money).
     */
    public function noMedia(Pet $pet, ?array $picture): bool
    {
        if (! $this->enabled()) {
            return true;
        }
        if ($picture !== null) {
            return false;
        }

        $waiting = array_map(fn (AiCallFailure $r) => $r->value, ReferenceImageRetryService::AUTO_RETRY_REASONS);

        return $pet->media_status === 'disabled'
            || ($pet->media_status === 'failed' && ! in_array($pet->media_error, $waiting, true));
    }

    /**
     * The state of every owned item: its design, outcome and the current rows.
     *
     * @param  Collection<int, PetMedia>|null  $slots
     *                                                 `given_up` = the recovery of the current chain is exhausted and no clip is shown (a durable
     *                                                 item is then a fallback, an ACTION item keeps waiting — locked).
     *                                                 `drink_clip` (bowls only, David 2026-11-20) = the shown drinking clip of the
     *                                                 current key image, else null — never part of the outcome.
     * @return array<string, array{item_key: string, design: string|null, content_key: string, outcome: string, stage: string|null, image: ItemMedia|null, clip: ItemMedia|null, drink_clip: ItemMedia|null, given_up: bool, given_up_row: ItemMedia|null}>
     */
    public function statesFor(Pet $pet, ?Collection $slots = null): array
    {
        $owned = $this->ownedContent($pet);
        if ($owned === []) {
            return [];
        }

        $picture = $this->pictureOf($pet, $slots);
        $noMedia = $this->noMedia($pet, $picture);
        $rows = $picture === null ? collect() : $this->rowsQuery($picture)->whereIn('design', array_column($owned, 'content_key'))->get();
        $clips = ! $noMedia && $this->clipsEnabled();

        $states = [];
        foreach ($owned as $key => $own) {
            $state = $own + ['outcome' => self::OUTCOME_WAITING, 'stage' => $picture['stage'] ?? null, 'image' => null, 'clip' => null, 'drink_clip' => null, 'given_up' => false, 'given_up_row' => null];

            if ($picture === null) {
                if ($noMedia) {
                    $state['outcome'] = self::OUTCOME_NO_MEDIA;
                }
                $states[$key] = $state; // the pet's own picture is on its way

                continue;
            }

            $image = $rows->first(fn (ItemMedia $r) => $r->design === $own['content_key'] && $r->kind === ItemMedia::KIND_IMAGE);
            $clip = $rows->first(fn (ItemMedia $r) => $r->design === $own['content_key'] && $r->kind === ItemMedia::KIND_CLIP);
            // David 2026-11-19: a flagged file (failed an enforced check of the stored file) is never shown.
            $imageCurrent = $image !== null && $image->isShown() && $image->source_path === $picture['path'];
            $clipCurrent = $imageCurrent && $clip !== null && $clip->isShown() && $clip->source_path === $image->storage_path;

            $state['image'] = $imageCurrent ? $image : null;
            $state['clip'] = $clipCurrent ? $clip : null;
            // David 2026-11-20: the bowls' drinking clip of the same key image — shown on its own, it
            // never changes the outcome (a missing / given-up drinking clip only means »no clip on Voda«).
            if (in_array($own['item_key'], ItemMedia::DRINK_ITEMS, true) && $imageCurrent) {
                $drink = $rows->first(fn (ItemMedia $r) => $r->design === $own['content_key'] && $r->kind === ItemMedia::KIND_DRINK_CLIP);
                $state['drink_clip'] = $drink !== null && $drink->isShown() && $drink->source_path === $image->storage_path ? $drink : null;
            }
            // The automatic recovery of the current chain gave up (heal(): exhausted + one alert).
            $givenUp = (! $imageCurrent && $image !== null && $image->isExhausted() && $image->target_path === $picture['path'])
                || ($imageCurrent && $clip !== null && $clip->isExhausted() && $clip->target_path === $image->storage_path);
            $state['given_up'] = ! $clipCurrent && ! $noMedia && $givenUp;
            $state['given_up_row'] = ! $state['given_up'] ? null : ($imageCurrent ? $clip : $image);
            $state['outcome'] = match (true) {
                $clipCurrent => self::OUTCOME_CONTENT,
                // AI / the item profile switched off: what is already made stays shown, else none comes.
                $noMedia => $imageCurrent ? self::OUTCOME_FALLBACK : self::OUTCOME_NO_MEDIA,
                // David 2026-11-15: an action item is never usable without its clip — it stays locked.
                $givenUp => in_array($key, self::ACTION_ITEMS, true) ? self::OUTCOME_WAITING : self::OUTCOME_FALLBACK,
                $imageCurrent && ! $clips => self::OUTCOME_FALLBACK, // clip profile off: the picture alone
                default => self::OUTCOME_WAITING,
            };
            $states[$key] = $state;
        }

        return $states;
    }

    /**
     * `media_ready` per owned item key (true unless its content is still being made).
     *
     * @return array<string, bool>
     */
    public function readiness(Pet $pet): array
    {
        return array_map(fn (array $s): bool => $s['outcome'] !== self::OUTCOME_WAITING, $this->statesFor($pet));
    }

    /**
     * The general media rule (David 2026-11-15, PRODUCT_SPEC »stanje → medij«): may the child use
     * the ACTION of $itemKey now? False while the item's clip for the pet's current picture is
     * not there (being made, waiting for the AI budget, failed, given up) — the action is
     * disabled (»se pripravlja«) and refused with 422 `media_not_ready`, like a game or the
     * school (GameMediaReadiness). True once the clip is shown, for a pet that gets no AI media
     * at all (the drawn stage — no clip will ever come) and while the clip profile is switched off
     * (the picture alone; a switch never locks a feature for clips that will not come). An item
     * the pet does not own has nothing to wait for (true — the owner check is the caller's).
     */
    public function actionReady(Pet $pet, string $itemKey): bool
    {
        return $this->readiness($pet)[$itemKey] ?? true;
    }

    /**
     * The payload's `media.items`: per owned item whose content for the current picture is
     * shown — signed URLs of OUR files (never fal URLs). Absent while it is made, for a pet
     * without AI media and for a fallback without any picture.
     *
     * The bowls (ItemMedia::DRINK_ITEMS) also carry `drink_video` / `drink_video_seconds` — the drinking
     * clip (David 2026-11-20), null until it is stored and passed its check, or when it gave up.
     *
     * @param  Collection<int, PetMedia>|null  $slots
     * @return array<string, array{image: string|null, video: string|null, video_seconds: float|null, loop: bool, drink_video?: string|null, drink_video_seconds?: float|null}>
     */
    public function payloadFor(Pet $pet, ?Collection $slots = null, ?CarbonImmutable $expires = null): array
    {
        $expires ??= $this->media->urlExpiry();
        $items = [];

        foreach ($this->statesFor($pet, $slots) as $key => $state) {
            if ($state['image'] === null) {
                continue;
            }
            $clip = $state['clip'];
            $items[$key] = [
                'image' => $this->signedUrl($state['image'], $pet, $expires),
                'video' => $clip !== null ? $this->signedUrl($clip, $pet, $expires) : null,
                'video_seconds' => $clip?->duration_seconds,
                'loop' => in_array($key, self::LOOP_ITEMS, true),
            ];
            if (in_array($key, ItemMedia::DRINK_ITEMS, true)) {
                $drink = $state['drink_clip'];
                $items[$key]['drink_video'] = $drink !== null ? $this->signedUrl($drink, $pet, $expires) : null;
                $items[$key]['drink_video_seconds'] = $drink?->duration_seconds;
            }
        }

        return $items;
    }

    /**
     * Signed URL to GET /api/media/items/{itemMedia} — the same capability rules as
     * PetMediaService::signedUrl() (relative signature, bucketed expiry, `v` per stored file).
     * Pet content carries the pet (`pet`: who may listen to it is checked when a token is
     * sent); a catalogue picture carries none (a product picture, no child data).
     */
    public function signedUrl(ItemMedia $row, ?Pet $pet = null, ?CarbonImmutable $expires = null): string
    {
        $path = URL::temporarySignedRoute(
            'media.item',
            $expires ?? $this->media->urlExpiry(),
            array_filter([
                'itemMedia' => $row->id,
                'pet' => $row->scope === ItemMedia::SCOPE_CATALOGUE ? null : $pet?->id,
                'v' => substr(sha1((string) $row->storage_path), 0, 10),
            ], fn ($v) => $v !== null),
            absolute: false,
        );

        return rtrim((string) config('app.url'), '/').$path;
    }

    /** URL fal fetches one of our item files from (start frame / 2nd reference image). */
    public function falFetchUrl(ItemMedia $row): string
    {
        return $this->signedUrl($row, null, $this->media->urlExpiry((int) config('media.storage.fal_fetch_ttl_minutes', 360)));
    }

    // ──────────────────────────────────────────────────────────────
    //  Catalogue pictures
    // ──────────────────────────────────────────────────────────────

    /**
     * The catalogue picture rows of every design (created pending when missing).
     *
     * @return Collection<string, ItemMedia> keyed by design
     */
    public function catalogueRows(): Collection
    {
        $all = ItemDesigns::all();
        $rows = ItemMedia::where('scope', ItemMedia::SCOPE_CATALOGUE)->get()->keyBy('design');

        foreach ($all as $design => $d) {
            if (! $rows->has($design)) {
                ItemMedia::query()->insertOrIgnore([[
                    'scope' => ItemMedia::SCOPE_CATALOGUE, 'item_key' => $d['item_key'], 'design' => $design,
                    'kind' => ItemMedia::KIND_PICTURE, 'status' => ItemMedia::STATUS_PENDING,
                    'created_at' => now(), 'updated_at' => now(),
                ]]);
            }
        }

        return ItemMedia::where('scope', ItemMedia::SCOPE_CATALOGUE)->get()->keyBy('design');
    }

    /**
     * Signed URL of every stored catalogue picture, keyed by design (no row created here — a
     * read path). A design without a stored picture is missing (the app shows it without one).
     *
     * @return array<string, string>
     */
    public function catalogueUrls(): array
    {
        $expires = $this->media->urlExpiry();

        return ItemMedia::where('scope', ItemMedia::SCOPE_CATALOGUE)->whereNotNull('storage_path')->get()
            ->mapWithKeys(fn (ItemMedia $r) => [$r->design => $this->signedUrl($r, null, $expires)])->all();
    }

    /**
     * The `designs` list of an item row in the catalogues: key, name (request language),
     * catalogue picture URL or null. Treats → [].
     *
     * @param  array<string, string>|null  $pictures
     * @return list<array{key: string, name: string, image: string|null}>
     */
    public function designsFor(string $itemKey, Species $species, ?array $pictures = null): array
    {
        $pictures ??= $this->catalogueUrls();

        return array_map(fn (array $d): array => [
            'key' => $d['key'],
            'name' => ItemDesigns::name($d['key']),
            'image' => $pictures[$d['key']] ?? null,
        ], ItemDesigns::for($itemKey, $species));
    }

    /** Queue every catalogue picture that is not made yet (scheduler). Returns how many were queued. */
    public function queueCatalogue(): int
    {
        if (! $this->fal->isEnabled() || $this->profiles->itemPicture() === null) {
            return 0;
        }

        $queued = 0;
        foreach ($this->catalogueRows() as $row) {
            if ($row->status === ItemMedia::STATUS_PENDING) {
                GenerateItemMedia::dispatch($row->id);
                $queued++;
            }
        }

        return $queued;
    }

    // ──────────────────────────────────────────────────────────────
    //  Queueing a pet's content
    // ──────────────────────────────────────────────────────────────

    /**
     * Make sure every owned item of the pet has (or is getting) its content for the pet's
     * current picture: creates the rows, resets stale ones (a new stage / picture) and dispatches
     * what is due. Never spends itself (the jobs go through FalGateway). Failed rows of the
     * current chain are left to heal(). Returns how many jobs were dispatched.
     */
    public function ensure(Pet $pet): int
    {
        if (! $pet->is_active || $pet->is_game_over || ! $this->enabled()) {
            return 0;
        }

        $owned = $this->ownedContent($pet);
        $picture = $owned === [] ? null : $this->pictureOf($pet);
        if ($picture === null) {
            return 0; // nothing owned, or the pet's picture is on its way (its store queues us again)
        }

        $dispatched = 0;
        foreach ($owned as $own) {
            // QA #1139 m6: a pet whose packs are all used up has no »Priboljšek« to play the clip —
            // nothing is (re)generated for it (a new stage included) until treats are bought again.
            if (! $this->actionInUse($pet, $own['item_key'])) {
                continue;
            }
            $image = $this->row($picture, $own['item_key'], $own['content_key'], ItemMedia::KIND_IMAGE);

            // A flagged key image (David 2026-11-19) is being regenerated — its clip waits for the new one.
            if ($image->isShown() && $image->source_path === $picture['path']) {
                if ($image->status === ItemMedia::STATUS_PENDING) {
                    // 2026-11-20: a fresh chain of a SHOWN key image (the one-off reopen of the bowls /
                    // bed given up before the neutral-pose / puppy-size fix — migration
                    // 2027_04_29_120000): its old file stays shown, the new one is made now (unique
                    // job, claimed once; budget-guarded at the submit).
                    GenerateItemMedia::dispatch($image->id);
                    $dispatched++;
                }
                if (! $this->clipsEnabled()) {
                    continue; // QA #655 m4: no clip while its profile is off — the picture is shown
                }
                $clip = $this->row($picture, $own['item_key'], $own['content_key'], ItemMedia::KIND_CLIP);
                $dispatched += $this->ensureRow($clip, (string) $image->storage_path) ? 1 : 0;
                // David 2026-11-20: the bowls' drinking clip from the same key image (also the backfill
                // of bowls bought before — this runs for every owner in media:item-media).
                if (in_array($own['item_key'], ItemMedia::DRINK_ITEMS, true)) {
                    $drink = $this->row($picture, $own['item_key'], $own['content_key'], ItemMedia::KIND_DRINK_CLIP);
                    $dispatched += $this->ensureRow($drink, (string) $image->storage_path) ? 1 : 0;
                }

                continue;
            }

            $dispatched += $this->ensureRow($image, $picture['path']) ? 1 : 0;
        }

        return $dispatched;
    }

    /**
     * An ACTION item of the pet has something left to use (treats: an unrevoked pack with treats
     * left). Always true for a durable item (it is shown as long as it is owned).
     */
    public function actionInUse(Pet $pet, string $itemKey): bool
    {
        return ! in_array($itemKey, self::ACTION_ITEMS, true)
            || PetItem::where('pet_id', $pet->id)->where('item_key', $itemKey)->whereNull('revoked_at')->where('quantity_left', '>', 0)->exists();
    }

    /**
     * QA #1139 M1: an ACTION item was just bought for a pet whose content chain was GIVEN UP
     * earlier (the first buyer of the shared look + stage, or an earlier pack of this pet) —
     * without this the new purchase would stay locked for ever, with no submit and no alert.
     * One fresh chain per purchase (fresh seed, counters cleared, `exhausted_at` / `alerted_at`
     * cleared so a second give-up alerts again); the submit is guarded by AiSpendGuard like every
     * other. Also a `quality_failed` row: a paid purchase is an explicit reason for one more try.
     * Called only from the redeem (QueuePetItemMedia with `purchased`), never from the scheduler.
     *
     * @param  list<string>  $itemKeys  the item keys the purchase covers
     * @return int rows reopened
     */
    public function reopenGivenUp(Pet $pet, array $itemKeys): int
    {
        $keys = array_values(array_intersect($itemKeys, self::ACTION_ITEMS));
        if ($keys === [] || ! $pet->is_active || $pet->is_game_over || ! $this->enabled()) {
            return 0;
        }

        $reopened = 0;
        foreach ($this->statesFor($pet) as $key => $state) {
            $row = $state['given_up_row'];
            if (! in_array($key, $keys, true) || ! $state['given_up'] || $row === null) {
                continue;
            }
            // Under the row lock: two purchases at once reopen it once.
            $done = DB::transaction(function () use ($row): bool {
                $locked = ItemMedia::query()->whereKey($row->id)->lockForUpdate()->first();
                if ($locked === null || $locked->status !== ItemMedia::STATUS_FAILED || $locked->exhausted_at === null) {
                    return false;
                }
                $this->resetChain($locked);

                return true;
            });
            if ($done) {
                $reopened++;
                Log::info('ItemMediaService: a given-up action item chain reopened by a new purchase', ['item_media_id' => $row->id, 'item_key' => $key]);
                GenerateItemMedia::dispatch($row->id);
            }
        }

        return $reopened;
    }

    /**
     * One row wanted from $source: done when its stored file is made from it; a running /
     * failed chain for $source is left alone (heal() decides); anything else is reset (new
     * chain) and dispatched.
     */
    private function ensureRow(ItemMedia $row, string $source): bool
    {
        if ($row->isShown() && $row->source_path === $source && $row->status !== ItemMedia::STATUS_PENDING) {
            return false;
        }

        if ($row->target_path === $source && in_array($row->status, [ItemMedia::STATUS_RUNNING, ItemMedia::STATUS_FAILED], true)) {
            return false;
        }

        if ($row->status === ItemMedia::STATUS_RUNNING && $row->request_id !== null) {
            return false; // a request for an older picture is still out — its result is stale, then we come back
        }

        if ($row->status !== ItemMedia::STATUS_PENDING || $row->target_path !== $source) {
            // David 2026-11-19: a clip that waited for its key image to be regenerated
            // (regenerateKeyImageOf) keeps the tries it used — the retry budget is per chain, not per key image.
            $this->resetChain($row, $source, $row->isClip() && $row->status === ItemMedia::STATUS_PENDING ? $row->quality_retries : 0);
        }

        GenerateItemMedia::dispatch($row->id);

        return true;
    }

    /** Start a new failure chain for $row from $source (pending, counters cleared, new generation when something was made / sent). */
    public function resetChain(ItemMedia $row, ?string $source = null, int $qualityRetries = 0): void
    {
        $bump = $row->isServable() || $row->request_id !== null || $row->status === ItemMedia::STATUS_FAILED;
        $candidate = $row->candidate_path;

        $row->update([
            // David 2026-11-04: a new chain of a row that was made / sent before never resubmits the
            // identical request (fresh seed), starts its check retries again and drops a »Needs review«
            // candidate (the stored file stays servable).
            'seed_override' => $bump ? PetMedia::freshSeed() : $row->seed_override,
            'quality_retries' => $qualityRetries,
            'candidate_path' => null,
            'candidate_bytes' => null,
            'candidate_mime' => null,
            'status' => ItemMedia::STATUS_PENDING,
            'generation' => $bump ? $row->generation + 1 : $row->generation,
            'target_path' => $source ?? $row->target_path,
            'request_id' => null,
            'status_url' => null,
            'response_url' => null,
            'source_url' => null,
            'error_reason' => null,
            'error' => null,
            'auto_retries' => 0,
            'prompt_variant' => 0,
            'download_attempts' => 0,
            'exhausted_at' => null,
            'alerted_at' => null,
            'completed_at' => null,
        ]);

        if (filled($candidate) && $candidate !== $row->storage_path) {
            $this->media->disk()->delete((string) $candidate);
        }
    }

    /**
     * The row of a pet's picture context (look rows per look + design + stage, own rows per
     * pet + design), created pending when missing (insert-or-ignore on the partial unique index).
     *
     * @param  array{scope: string, look_id: int|null, stage: string|null, path: string, source: PetMedia}  $picture
     */
    private function row(array $picture, string $itemKey, string $design, string $kind): ItemMedia
    {
        $query = $this->rowsQuery($picture)->where('design', $design)->where('kind', $kind);
        $existing = (clone $query)->first();
        if ($existing !== null) {
            return $existing;
        }

        $look = $picture['scope'] === ItemMedia::SCOPE_LOOK;
        ItemMedia::query()->insertOrIgnore([[
            'scope' => $picture['scope'],
            'pet_id' => $look ? null : $picture['source']->pet_id,
            'pet_look_id' => $look ? $picture['look_id'] : null,
            'item_key' => $itemKey,
            'design' => $design,
            'kind' => $kind,
            'life_stage' => $picture['stage'],
            'status' => ItemMedia::STATUS_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]]);

        return $query->firstOrFail();
    }

    /**
     * @param  array{scope: string, look_id: int|null, stage: string|null, path: string, source: PetMedia}  $picture
     * @return Builder<ItemMedia>
     */
    private function rowsQuery(array $picture)
    {
        return $picture['scope'] === ItemMedia::SCOPE_LOOK
            ? ItemMedia::query()->where('scope', ItemMedia::SCOPE_LOOK)->where('pet_look_id', $picture['look_id'])->where('life_stage', $picture['stage'])
            : ItemMedia::query()->where('scope', ItemMedia::SCOPE_PET)->where('pet_id', $picture['source']->pet_id);
    }

    // ──────────────────────────────────────────────────────────────
    //  Generation (GenerateItemMedia) — outside any transaction
    // ──────────────────────────────────────────────────────────────

    /**
     * Submit one row to fal (once: the claim). Returns false when the queue should retry (fal
     * was not reached — transport error before sending).
     */
    public function generate(int $rowId): bool
    {
        $row = ItemMedia::find($rowId);
        if ($row === null || ! $this->fal->isEnabled()) {
            return true;
        }

        $plan = $this->planFor($row);
        if ($plan === null) {
            return true; // its source is not there (yet) — the source's store / the scheduler queues it again
        }

        if (! $this->claim($row)) {
            return true; // another worker has it
        }

        $row->update(['target_path' => $plan['target'], 'life_stage' => $plan['stage'] ?? $row->life_stage]);

        try {
            $submitted = $plan['submit']();
        } catch (AiCallException $e) {
            $this->syncCost($row);
            if ($e->retryable() && ! $this->reachedFal($row)) {
                $row->update(['status' => ItemMedia::STATUS_PENDING]);

                return false;
            }
            $this->fail($row, $e->reason, $e->getMessage());
            Log::warning('ItemMediaService: item media not submitted', ['item_media_id' => $row->id, 'reason' => $e->reason->value]);

            return true;
        }

        $row->update([
            'request_id' => $submitted['request_id'],
            'status_url' => $submitted['status_url'],
            'response_url' => $submitted['response_url'],
            'profile' => $submitted['profile'],
            'duration_seconds' => $submitted['duration_seconds'] ?? null,
        ]);
        $this->syncCost($row);

        return true;
    }

    /**
     * What a row is made from and how it is submitted; null while its source is missing.
     *
     * @return array{target: string, stage?: string|null, submit: callable(): array<string, mixed>}|null
     */
    private function planFor(ItemMedia $row): ?array
    {
        $description = ItemDesigns::description($row->design);
        if ($description === null) {
            Log::error('ItemMediaService: unknown design', ['item_media_id' => $row->id, 'design' => $row->design]);

            return null;
        }

        if ($row->isPicture()) {
            // David 2026-11-04: a regeneration sends a fresh seed (never the same picture again).
            $seed = $row->seedFor((int) (crc32($row->design) & 0x7FFFFFFF));

            return [
                'target' => 'catalogue:'.$row->design,
                'submit' => fn (): array => $this->fal->submitItemPicture($this->prompts->itemPicturePrompt($description, $row->prompt_variant), $seed, $row->id),
            ];
        }

        $owner = $this->ownerOf($row);
        if ($owner === null) {
            return null;
        }
        [$breedKey, $traits, $seed, $sourceImage, $petId] = $owner;
        $seed = $row->seedFor($seed);
        // A pet's own rows follow its picture's stage (regenerated in place); look rows are per stage.
        $stageValue = $row->scope === ItemMedia::SCOPE_PET && $row->kind === ItemMedia::KIND_IMAGE ? $sourceImage?->life_stage : $row->life_stage;
        $stage = $stageValue !== null ? LifeStage::tryFrom($stageValue) : null;

        if ($row->kind === ItemMedia::KIND_IMAGE) {
            if ($sourceImage === null || ! $sourceImage->isServable()) {
                return null;
            }
            $picture = $this->pictureReference($row->design);
            if ($picture === false) {
                return null; // the catalogue picture is still on its way — its store queues this row again
            }
            $prompt = $this->prompts->itemImagePrompt($breedKey, $row->item_key, $description, $traits, $stage, $picture !== null, $row->checkVariant(), $this->sizeOf($breedKey, null, $stage));

            return [
                'target' => (string) $sourceImage->storage_path,
                'stage' => $stageValue,
                'submit' => fn (): array => $this->fal->submitItemImage($prompt, $this->media->falFetchUrl($sourceImage), $picture !== null ? $this->falFetchUrl($picture) : null, $seed, $petId, $row->id),
            ];
        }

        // David 2026-11-19: only from a key image that is stored AND shown — never from a flagged one
        // or the old file of a key image that is being regenerated (its store queues the clip again).
        $image = $this->siblingImage($row);
        if ($image === null || ! $image->isReady() || ! $image->isShown()) {
            return null;
        }
        $species = PetAppearancePrompt::speciesOf($breedKey);
        $clipStage = $image->life_stage !== null ? LifeStage::tryFrom($image->life_stage) : null;
        // David 2026-11-20: the bowls' drinking clip — same key image, drinking prompt.
        $prompt = $row->isDrinkClip()
            ? $this->prompts->itemDrinkClipPrompt($breedKey, $description, $traits, $clipStage, $row->checkVariant())
            : $this->prompts->itemClipPrompt($breedKey, $row->item_key, $description, $traits, $clipStage, $row->checkVariant());
        $negative = $row->isDrinkClip()
            ? $this->prompts->itemDrinkClipNegativePrompt($species)
            : $this->prompts->itemClipNegativePrompt($species, (string) $row->item_key);

        return [
            'target' => (string) $image->storage_path,
            'stage' => $image->life_stage,
            'submit' => fn (): array => $this->fal->submitItemClip($prompt, $negative, $this->falFetchUrl($image), $petId, $row->id),
        ];
    }

    /**
     * The design's stored catalogue picture to send as 2nd reference; null = send without (treats,
     * switched off, or the picture failed for good); false = wait (it is still being made).
     */
    private function pictureReference(string $design): ItemMedia|false|null
    {
        if (! (bool) config('media.item_media.picture_as_reference', true) || ItemDesigns::find($design) === null) {
            return null;
        }

        if ($this->profiles->itemPicture() === null) {
            return null;
        }

        $picture = ItemMedia::where('scope', ItemMedia::SCOPE_CATALOGUE)->where('design', $design)->first();
        if ($picture === null) {
            // Not created yet (the scheduler makes them): create + queue it, wait for it.
            $picture = $this->catalogueRows()->get($design);
            if ($picture !== null && $picture->status === ItemMedia::STATUS_PENDING) {
                GenerateItemMedia::dispatch($picture->id);
            }

            return $picture?->isServable() ? $picture : false;
        }
        if ($picture->isServable()) {
            return $picture;
        }
        if ($picture->status === ItemMedia::STATUS_PENDING) {
            GenerateItemMedia::dispatch($picture->id); // not made yet — make it now (unique job, claimed once)
        }

        // Given up → the key image is made from the description alone; otherwise wait for it.
        return $picture->isExhausted() ? null : false;
    }

    /**
     * Breed key, traits, seed, the reference image to edit (stage picture) and the pet id (ledger) of a look / pet row.
     *
     * @return array{0: string, 1: array<string, string>, 2: int, 3: PetMedia|null, 4: int|null}|null
     */
    private function ownerOf(ItemMedia $row): ?array
    {
        if ($row->scope === ItemMedia::SCOPE_LOOK) {
            $look = PetLook::find($row->pet_look_id);
            if ($look === null) {
                return null;
            }
            $image = PetMedia::query()->where('pet_look_id', $look->id)->images()->where('life_stage', $row->life_stage)->first();

            return [$look->breed_type->value, $look->traits(), $look->seed(), $image, null];
        }

        $pet = $row->pet;
        if ($pet === null) {
            return null;
        }
        [$breedKey, $traits] = $this->prompts->subjectOf($pet);
        $dna = is_array($pet->pet_dna) ? $pet->pet_dna : [];
        $image = PetMedia::query()->where('pet_id', $pet->id)->images()->first();

        return [$breedKey, $traits, (int) ($dna['seed'] ?? $pet->id), $image, $pet->id];
    }

    /** The key image row of a clip row (its start frame), null when missing. */
    public function keyImageOf(ItemMedia $clip): ?ItemMedia
    {
        return $clip->isClip() ? $this->siblingImage($clip) : null;
    }

    /**
     * David 2026-11-19: a clip check found the OBJECT wrong (floating, pasted in, too small) — that
     * comes from the clip's start frame, so a new clip from the same key image would repeat it. The
     * key image is flagged (never shown again) and regenerated as the next try of its chain (fresh
     * seed, next prompt variant, `quality_retries` + 1 — within `max_still_retries`); the clip counts
     * the try too (`quality_retries` + 1, carried into its next chain by ensureRow()) and WAITS
     * (pending, its candidate deleted) until the new key image is stored — then it follows it. With
     * `$candidate` null the clip's STORED file is the one judged (media:item-recheck): it is flagged
     * and the clip waits the same way. `$freshChain` (QA #1414 M4, media:item-recheck of a STORED
     * clip): the key image starts a NEW chain whose first try counts as one retry (like
     * media:still-check), whatever retries the old chain used — a stored clip is never remade from
     * the same bad start frame. False (nothing changed) when the key image is not a stored, shown,
     * unapproved file, has no tries left (pipeline), or the clip moved on. Every flag tells the
     * pets at once (one PetUpdated per pet — QA #1414 M1).
     */
    public function regenerateKeyImageOf(ItemMedia $clip, ?string $candidate, string $reason, bool $freshChain = false): bool
    {
        $image = $this->siblingImage($clip);
        if ($image === null || ! $image->isReady() || ! $image->isShown() || $image->quality === PetMedia::QUALITY_APPROVED
            || (! $freshChain && $image->quality_retries >= StillQualityService::maxRetries())) {
            return false;
        }

        $why = mb_substr('Its clip shows the object wrong: '.$reason, 0, 2000);
        $clipMoved = ItemMedia::query()->whereKey($clip->id)
            ->when($candidate !== null,
                fn ($q) => $q->where('status', ItemMedia::STATUS_RUNNING)->where('candidate_path', $candidate),
                fn ($q) => $q->where('status', ItemMedia::STATUS_READY)->where('storage_path', $clip->storage_path))
            ->update(array_merge([
                'status' => ItemMedia::STATUS_PENDING,
                // A new generation: its check is a new claim, never the failed verdict of this one.
                'generation' => DB::raw('generation + 1'),
                'quality_retries' => DB::raw('quality_retries + 1'),
                'request_id' => null,
                'status_url' => null,
                'response_url' => null,
                'source_url' => null,
                'candidate_path' => null,
                'candidate_bytes' => null,
                'candidate_mime' => null,
                'download_attempts' => 0,
                'error_reason' => null,
                'error' => mb_substr('Quality check failed, regenerating its key image first: '.$reason, 0, 2000),
                'updated_at' => now(),
            ], $candidate === null ? ['quality' => PetMedia::QUALITY_FLAGGED, 'quality_reason' => $why, 'flagged_at' => now()] : [])) !== 1;

        if ($clipMoved) {
            return false;
        }
        if ($candidate !== null && $candidate !== $clip->storage_path) {
            $this->media->disk()->delete($candidate);
        }

        $flagged = ItemMedia::query()->whereKey($image->id)->where('status', ItemMedia::STATUS_READY)->where('storage_path', $image->storage_path)
            ->update(['quality' => PetMedia::QUALITY_FLAGGED, 'quality_reason' => $why, 'flagged_at' => now()]) === 1;
        if ($flagged && ($fresh = $image->fresh()) !== null) {
            $this->resetChain($fresh, null, $freshChain ? min(1, StillQualityService::maxRetries()) : $image->quality_retries + 1);
            $fresh->update(['prompt_variant' => $freshChain ? 0 : min($image->prompt_variant + 1, PetAppearancePrompt::ITEM_PROMPT_VARIANTS - 1)]);
            GenerateItemMedia::dispatch((int) $fresh->id);
        }
        $this->tellPetsOf($image);

        return true;
    }

    /**
     * QA #1414 M1: a stored file of $row was hidden (flagged) — every pet that shows the row gets ONE
     * PetUpdated (`item_media_ready`, after commit) so the apps drop the file now, not at the next
     * refresh. The item reads »Na poti …« until its replacement is stored (settle() tells them again).
     */
    public function tellPetsOf(ItemMedia $row): void
    {
        foreach ($this->petsOf($row) as $pet) {
            PetUpdated::afterCommit($pet, 'item_media_ready');
        }
    }

    /**
     * QA #1414 M2: is this stored key image / clip what an active owner's pet shows NOW — a pet that
     * owns the item (an ACTION item only while it is in use) at the row's stage, whose current
     * content state has this very row as its image or clip. media:item-recheck checks only those.
     */
    public function shownToAnOwner(ItemMedia $row): bool
    {
        foreach ($this->petsOf($row) as $pet) {
            if ($pet->is_game_over || ! $this->actionInUse($pet, (string) $row->item_key)) {
                continue;
            }
            $state = $this->statesFor($pet)[$row->item_key] ?? null;
            if ($state !== null && $state['content_key'] === $row->design
                && in_array($row->id, [$state['image']?->id, $state['clip']?->id, $state['drink_clip']?->id], true)) {
                return true;
            }
        }

        return false;
    }

    /** The key image row a clip is made from (same scope, owner, design, stage). */
    private function siblingImage(ItemMedia $clip): ?ItemMedia
    {
        return ItemMedia::query()->where('scope', $clip->scope)->where('design', $clip->design)->where('kind', ItemMedia::KIND_IMAGE)
            ->when($clip->scope === ItemMedia::SCOPE_LOOK,
                fn ($q) => $q->where('pet_look_id', $clip->pet_look_id)->where('life_stage', $clip->life_stage),
                fn ($q) => $q->where('pet_id', $clip->pet_id))
            ->first();
    }

    private function claim(ItemMedia $row): bool
    {
        $claimed = ItemMedia::query()->whereKey($row->id)
            ->where(fn ($q) => $q->where('status', ItemMedia::STATUS_PENDING)
                ->orWhere(fn ($q) => $q->where('status', ItemMedia::STATUS_RUNNING)->whereNull('request_id')->whereNull('source_url')
                    ->where('updated_at', '<', now()->subMinutes(self::STALE_CLAIM_MINUTES))))
            ->update([
                'status' => ItemMedia::STATUS_RUNNING,
                'attempts' => DB::raw('attempts + 1'),
                'error_reason' => null,
                'error' => null,
                'updated_at' => now(),
            ]) === 1;

        if ($claimed) {
            $row->refresh();
        }

        return $claimed;
    }

    /** Did the last call of this row reach fal (a committed ledger row of the current attempt)? */
    private function reachedFal(ItemMedia $row): bool
    {
        $last = AiSpendLedger::query()->where('item_media_id', $row->id)->latest('id')->first();

        return $last !== null && $last->status !== AiSpendLedger::STATUS_VOID;
    }

    public function syncCost(ItemMedia $row): void
    {
        $row->update(['cost_usd' => round((float) AiSpendLedger::query()->counted()->where('item_media_id', $row->id)->sum('cost_usd'), 4)]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Webhook / poll result → download
    // ──────────────────────────────────────────────────────────────

    /**
     * The signed fal webhook for an item row (FalAiWebhookController, inside its transaction,
     * row locked by request id). Idempotent; a late success after a sweep's `timed_out` is taken.
     *
     * @param  array{ok: bool, url?: string|null, video_url?: string|null, error: string|null, reason?: AiCallFailure|null}  $result
     * @return 'recorded'|'failed'|'already_processed'
     */
    public function recordResult(ItemMedia $row, array $result): string
    {
        $lateSuccess = $row->status === ItemMedia::STATUS_FAILED && $row->source_url === null && $result['ok'];

        if (($row->status !== ItemMedia::STATUS_RUNNING && ! $lateSuccess) || $row->source_url !== null) {
            return 'already_processed';
        }

        if (! $result['ok']) {
            $this->fail($row, $result['reason'] ?? AiCallFailure::GenerationFailed, $result['error']);
            Log::warning('ItemMediaService: item media generation failed', ['item_media_id' => $row->id, 'error' => $result['error']]);

            return 'failed';
        }

        $row->update([
            'status' => ItemMedia::STATUS_RUNNING,
            'download_attempts' => 0,
            'source_url' => $result['url'] ?? $result['video_url'] ?? null,
            'error_reason' => null,
            'error' => null,
            'exhausted_at' => null,
        ]);
        StoreItemMedia::dispatch($row->id)->afterCommit();

        return 'recorded';
    }

    /** Download the row's fal result to the media disk and store it. Returns false when the queue should retry. */
    public function storeResult(int $rowId): bool
    {
        $row = ItemMedia::find($rowId);
        if ($row === null || $row->status !== ItemMedia::STATUS_RUNNING || blank($row->source_url)) {
            return true;
        }

        // David 2026-11-04: already downloaded and waiting for its check (a lost check job, re-run by
        // the sweep) — check it, never download it again.
        if (filled($row->candidate_path)) {
            CheckItemMediaQuality::dispatch((int) $row->id);

            return true;
        }

        $sourceUrl = (string) $row->source_url;
        $still = $row->isStill();

        try {
            $file = $this->downloader->download(
                $sourceUrl,
                (int) config($still ? 'media.storage.max_image_bytes' : 'media.storage.max_video_bytes'),
                (array) config($still ? 'media.storage.image_mimes' : 'media.storage.video_mimes'),
            );
        } catch (MediaDownloadException $e) {
            if (! $e->permanent) {
                return false;
            }
            $this->fail($row, AiCallFailure::InvalidResponse, $e->getMessage());

            return true;
        }

        if (! $still && $this->fastStart->process($file['path']) === Mp4FastStart::RESULT_SKIPPED) {
            Log::warning('ItemMediaService: MP4 not converted to faststart', ['item_media_id' => $row->id]);
        }

        $path = $this->pathFor($row, MediaDownloader::extensionFor($file['mime']));
        try {
            $this->media->disk()->putFileAs(dirname($path), new File($file['path']), basename($path));
        } finally {
            @unlink($file['path']);
        }

        // David 2026-11-04: checked before it is used — a still by the AI vision check, a clip by the
        // free technical check (StillQualityService); the stored file stays servable until then.
        if ($this->wantsCandidate($row)) {
            return $this->stageCandidate($row, $sourceUrl, $path, $file['bytes'], $file['mime']);
        }

        $previous = null;
        $stored = DB::transaction(function () use ($row, $sourceUrl, $path, $file, &$previous): bool {
            $locked = ItemMedia::whereKey($row->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== ItemMedia::STATUS_RUNNING || $locked->source_url !== $sourceUrl) {
                return false;
            }
            $previous = $locked->storage_path;
            $this->markStored($locked, $path, $file['bytes'], $file['mime'], null, null);

            return true;
        });

        if (! $stored) {
            if ($path !== ItemMedia::whereKey($row->id)->value('storage_path')) {
                $this->media->disk()->delete($path);
            }

            return true;
        }

        if ($previous !== null && $previous !== $path) {
            $this->media->disk()->delete($previous);
        }

        $this->afterStored($row->fresh() ?? $row);

        return true;
    }

    /**
     * David 2026-11-04: is a downloaded file of this row checked before it is used? Stills with the
     * still check on (off | shadow | enforce, fresh read), clips with the clip check on.
     */
    public function wantsCandidate(ItemMedia $row): bool
    {
        $settings = app(AppSettingsService::class);

        if ($row->isStill()) {
            return $settings->qualityStillsMode(fresh: true) !== AppSettingsService::QUALITY_OFF;
        }

        // David 2026-11-19: a clip is checked while its technical check (clip mode) OR its AI realism
        // check (the item mode, with the clip AI call on) is on.
        return $settings->qualityCheckMode(fresh: true) !== AppSettingsService::QUALITY_OFF
            || ($settings->qualityClipAi(fresh: true) && $settings->qualityStillsMode(fresh: true) !== AppSettingsService::QUALITY_OFF);
    }

    /** Remember the downloaded file as the row's candidate (the row stays running) and queue its check. Idempotent. */
    private function stageCandidate(ItemMedia $row, string $sourceUrl, string $path, int $bytes, string $mime): bool
    {
        $staged = DB::transaction(function () use ($row, $sourceUrl, $path, $bytes, $mime): bool {
            $locked = ItemMedia::whereKey($row->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== ItemMedia::STATUS_RUNNING || $locked->source_url !== $sourceUrl || filled($locked->candidate_path)) {
                return false;
            }
            $locked->update(['candidate_path' => $path, 'candidate_bytes' => $bytes, 'candidate_mime' => $mime]);

            return true;
        });

        if (! $staged) {
            $fresh = $row->fresh();
            if (! in_array($path, [$fresh?->storage_path, $fresh?->candidate_path], true)) {
                $this->media->disk()->delete($path);
            }

            return true;
        }

        CheckItemMediaQuality::dispatch((int) $row->id);

        return true;
    }

    /**
     * The check passed (or »Approve anyway«): the candidate becomes the stored file. Conditional on the
     * row still holding it in one of `$fromStatuses` (running = a fresh check, failed = an approval).
     *
     * @param  list<string>  $fromStatuses
     */
    public function promoteCandidate(ItemMedia $row, string $candidate, ?string $quality, ?string $reason, array $fromStatuses = [ItemMedia::STATUS_RUNNING]): bool
    {
        $previous = null;
        $promoted = DB::transaction(function () use ($row, $candidate, $quality, $reason, $fromStatuses, &$previous): bool {
            $locked = ItemMedia::whereKey($row->id)->lockForUpdate()->first();
            if ($locked === null || ! in_array($locked->status, $fromStatuses, true) || $locked->candidate_path !== $candidate) {
                return false;
            }
            $previous = $locked->storage_path;
            $this->markStored($locked, $candidate, (int) $locked->candidate_bytes, (string) $locked->candidate_mime, $quality, $reason);

            return true;
        });

        if (! $promoted) {
            return false;
        }

        if ($previous !== null && $previous !== $candidate) {
            $this->media->disk()->delete($previous);
        }

        $this->afterStored($row->fresh() ?? $row);

        return true;
    }

    /** Write a stored file into a LOCKED row (inside the caller's transaction). */
    private function markStored(ItemMedia $locked, string $path, int $bytes, string $mime, ?string $quality, ?string $reason): void
    {
        $locked->update([
            'status' => ItemMedia::STATUS_READY,
            'storage_path' => $path,
            'source_path' => $locked->target_path,
            'bytes' => $bytes,
            'mime' => $mime,
            'candidate_path' => null,
            'candidate_bytes' => null,
            'candidate_mime' => null,
            'quality' => $quality,
            'quality_reason' => $quality === null ? null : $reason,
            'flagged_at' => null,
            'error_reason' => null,
            'error' => null,
            'auto_retries' => 0,
            'exhausted_at' => null,
            'completed_at' => now(),
        ]);
    }

    /**
     * A failed check, retries left: a NEW request of the row (fresh seed, the next prompt variant while
     * one exists, `quality_retries` + 1). The failed candidate is deleted; the stored file stays.
     */
    public function retryAfterCheck(ItemMedia $row, string $candidate, string $reason): bool
    {
        $variant = min($row->prompt_variant + 1, PetAppearancePrompt::ITEM_PROMPT_VARIANTS - 1);
        $retried = ItemMedia::query()->whereKey($row->id)->where('status', ItemMedia::STATUS_RUNNING)->where('candidate_path', $candidate)
            ->update([
                'status' => ItemMedia::STATUS_PENDING,
                'generation' => DB::raw('generation + 1'),
                'seed_override' => PetMedia::freshSeed(),
                'quality_retries' => DB::raw('quality_retries + 1'),
                'prompt_variant' => $variant,
                'request_id' => null,
                'status_url' => null,
                'response_url' => null,
                'source_url' => null,
                'candidate_path' => null,
                'candidate_bytes' => null,
                'candidate_mime' => null,
                'download_attempts' => 0,
                'error_reason' => null,
                'error' => mb_substr('Quality check failed, regenerating automatically: '.$reason, 0, 2000),
                'updated_at' => now(),
            ]) === 1;

        if (! $retried) {
            return false;
        }

        if ($candidate !== $row->storage_path) {
            $this->media->disk()->delete($candidate);
        }
        GenerateItemMedia::dispatch((int) $row->id);

        return true;
    }

    /**
     * A failed check, no retries left: the row fails `quality_failed` with its candidate kept for
     * »Approve anyway«; heal() gives the chain up (the item is shown without it + one alert).
     */
    public function failQuality(ItemMedia $row, string $candidate, string $reason): bool
    {
        return ItemMedia::query()->whereKey($row->id)->where('status', ItemMedia::STATUS_RUNNING)->where('candidate_path', $candidate)
            ->update([
                'status' => ItemMedia::STATUS_FAILED,
                'error_reason' => AiCallFailure::QualityFailed->value,
                'error' => mb_substr('Quality check failed — needs review: '.$reason, 0, 2000),
                'completed_at' => now(),
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * David 2026-11-19 (»ne naslednji dan ne poskusi ponovno«): a DURABLE item's key image / clip (bed,
     * bowls, toys — not treats, not catalogue pictures) whose candidate failed its check after every
     * automatic try. No »Needs review« wait, no admin step: the chain is given up AT ONCE (exhausted +
     * alerted, candidate kept for an optional »Approve anyway«), so statesFor() makes the item a
     * fallback right away — the last key image that passed its check is shown as the item's picture
     * (no clip), or, when no key image passed, the item without content; `media_ready` is true. The
     * pets are told (settle()) and ONE counting Discord post per window is queued (countGiveUp()).
     */
    public function givesUpAtOnce(ItemMedia $row): bool
    {
        return ! $row->isPicture() && ! in_array($row->item_key, self::ACTION_ITEMS, true);
    }

    public function giveUpQuality(ItemMedia $row, string $candidate, string $reason): bool
    {
        $what = match (true) {
            // David 2026-11-20: a given-up drinking clip only means »nothing extra on Voda« — the item is unaffected.
            $row->isDrinkClip() => 'no drinking clip is played (the bowls and their eating clip are unaffected)',
            $row->isClip() => 'its last passed key image is shown without a clip',
            default => 'the item is shown without its picture',
        };
        $done = ItemMedia::query()->whereKey($row->id)->where('status', ItemMedia::STATUS_RUNNING)->where('candidate_path', $candidate)
            ->update([
                'status' => ItemMedia::STATUS_FAILED,
                'error_reason' => AiCallFailure::QualityFailed->value,
                'error' => mb_substr('Quality check failed after every automatic try — given up, '.$what.': '.$reason, 0, 2000),
                'completed_at' => now(),
                'exhausted_at' => now(),
                'alerted_at' => now(),
                'updated_at' => now(),
            ]) === 1;

        if ($done) {
            self::countGiveUp();
            foreach ($this->petsOf($row) as $pet) {
                $this->settle($pet);
            }
        }

        return $done;
    }

    /** Cache keys + window of the aggregated durable-item give-up alert (like StillQualityService::countAlert). */
    public const GIVE_UP_COUNT_KEY = 'item-media-give-up:count';

    public const GIVE_UP_WINDOW_KEY = 'item-media-give-up:window';

    public const GIVE_UP_WINDOW_MINUTES = 15;

    /** Count one given-up durable item row; the first of a window queues ONE SendItemMediaAlert for its end. */
    public static function countGiveUp(): void
    {
        Cache::add(self::GIVE_UP_COUNT_KEY, 0, now()->addDay());
        Cache::increment(self::GIVE_UP_COUNT_KEY);

        if (Cache::add(self::GIVE_UP_WINDOW_KEY, true, now()->addMinutes(self::GIVE_UP_WINDOW_MINUTES + 5))) {
            SendItemMediaAlert::dispatch()->delay(now()->addMinutes(self::GIVE_UP_WINDOW_MINUTES));
        }
    }

    /** The count of the window that ends now (the window closes first, so a new give-up opens the next one). */
    public static function takeGiveUpCount(): int
    {
        Cache::forget(self::GIVE_UP_WINDOW_KEY);

        return (int) Cache::pull(self::GIVE_UP_COUNT_KEY, 0);
    }

    /** Record the verdict of a stored file (media:still-check); an approved file stays approved. */
    public function recordQuality(ItemMedia $row, string $path, string $quality, ?string $reason): bool
    {
        $flag = $quality === PetMedia::QUALITY_FLAGGED;
        $done = ItemMedia::query()->whereKey($row->id)->where('storage_path', $path)
            ->where(fn ($q) => $q->whereNull('quality')->orWhere('quality', '!=', PetMedia::QUALITY_APPROVED))
            // David 2026-11-19: a file flagged from now on is HIDDEN (ItemMedia::isShown()) — the pets are told.
            ->update(['quality' => $quality, 'quality_reason' => $reason !== null ? mb_substr($reason, 0, 2000) : null, 'flagged_at' => $flag ? now() : null]) === 1;

        if ($done && $flag) {
            $this->tellPetsOf($row);
        }

        return $done;
    }

    /** `items/{id}/…` (catalogue), `looks/{look}/item-…` (shared), `{pet}/item-…` (own; deleted with the pet). */
    private function pathFor(ItemMedia $row, string $ext): string
    {
        $stage = $row->life_stage ?? 'x';

        return match ($row->scope) {
            ItemMedia::SCOPE_CATALOGUE => sprintf('items/%d/%s-g%d.%s', $row->id, $row->design, $row->generation, $ext),
            ItemMedia::SCOPE_LOOK => sprintf('looks/%d/item-%s-%s-%s-g%d.%s', $row->pet_look_id, $row->design, $row->kind, $stage, $row->generation, $ext),
            default => sprintf('%d/item-%s-%s-%s-g%d.%s', $row->pet_id, $row->design, $row->kind, $stage, $row->generation, $ext),
        };
    }

    /**
     * What follows a stored file: picture → its waiting key images; image → its clip(s); clip → the
     * pets are told. The bowls' drinking clip (David 2026-11-20) does not change the item's outcome,
     * so settle() has nothing new — its pets get one PetUpdated `item_media_ready` (the apps refetch).
     */
    private function afterStored(ItemMedia $row): void
    {
        if ($row->isPicture()) {
            ItemMedia::query()->where('kind', ItemMedia::KIND_IMAGE)->where('design', $row->design)
                ->where('status', ItemMedia::STATUS_PENDING)->orderBy('id')
                ->each(fn (ItemMedia $waiting) => GenerateItemMedia::dispatch($waiting->id));

            return;
        }

        foreach ($this->petsOf($row) as $pet) {
            $this->ensure($pet);
            $settled = $this->settle($pet);
            if ($row->isDrinkClip() && ! in_array($row->item_key, $settled, true)) {
                PetUpdated::afterCommit($pet->fresh() ?? $pet, 'item_media_ready');
            }
        }
    }

    /**
     * Active pets that show a row: its own pet, or every pet of the look that owns the item.
     *
     * @return Collection<int, Pet>
     */
    public function petsOf(ItemMedia $row): Collection
    {
        $owning = fn ($q) => $q->whereIn('id', PetItem::query()->whereNull('revoked_at')->where('item_key', $row->item_key)->select('pet_id'));

        return match ($row->scope) {
            ItemMedia::SCOPE_LOOK => Pet::query()->where('pet_look_id', $row->pet_look_id)->where('is_active', true)->where('is_game_over', false)
                ->where($owning)->orderBy('id')->get(),
            ItemMedia::SCOPE_PET => Pet::query()->whereKey($row->pet_id)->where('is_active', true)->get(),
            default => collect(),
        };
    }

    /**
     * QA #655 m5: StoreItemMedia gave up on a result whose fal URL may still be valid (network /
     * disk trouble on OUR side). The row stays `running` with its `source_url`: the sweep downloads
     * it again (free) after an hour — only after DOWNLOAD_ROUNDS rounds does it fail and get
     * regenerated (paid). An expired / refused fal URL is a permanent download error → fail at once.
     */
    public function downloadFailed(ItemMedia $row, string $message): void
    {
        $updated = ItemMedia::query()->whereKey($row->id)->where('status', ItemMedia::STATUS_RUNNING)->whereNotNull('source_url')
            ->where('download_attempts', '<', self::DOWNLOAD_ROUNDS - 1)
            ->update(['download_attempts' => DB::raw('download_attempts + 1'), 'updated_at' => now()]);

        if ($updated === 1) {
            Log::warning('ItemMediaService: download failed, the sweep tries again (no new fal call)', ['item_media_id' => $row->id, 'error' => $message]);

            return;
        }

        $fresh = $row->fresh();
        if ($fresh !== null && $fresh->status === ItemMedia::STATUS_RUNNING) {
            $this->fail($fresh, AiCallFailure::HttpError, 'Download failed: '.$message);
        }
    }

    /**
     * A row failed (submit, webhook, poll, download). A look / pet row's pets are told once its
     * recovery is exhausted (heal()).
     */
    public function fail(ItemMedia $row, ?AiCallFailure $reason, ?string $message = null): void
    {
        $row->update([
            'status' => ItemMedia::STATUS_FAILED,
            'error_reason' => $reason?->value,
            'error' => $message !== null ? mb_substr($message, 0, 2000) : $reason?->label(),
            'completed_at' => now(),
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Settle: event + push once per item and stage
    // ──────────────────────────────────────────────────────────────

    /**
     * Tell the family once per owned item and stage that its content is there (or will not
     * come — fallback / no AI media): one PetUpdated `item_media_ready` for the pet (if anything
     * new) and one `item_ready` push per item the first time (a new stage only refreshes the
     * picture — no second »new bed« push). A stage settled again with another outcome (fallback /
     * no media → content after a Regenerate) broadcasts again, without a push. Idempotent
     * (`item_media_notices`).
     *
     * @return list<string> item keys newly settled
     */
    public function settle(Pet $pet): array
    {
        $new = [];
        foreach ($this->statesFor($pet) as $key => $state) {
            if ($state['outcome'] === self::OUTCOME_WAITING) {
                continue;
            }
            $design = (string) $state['content_key'];
            $seen = ItemMediaNotice::where('pet_id', $pet->id)->where('item_key', $key)->where('design', $design)->exists();
            $inserted = ItemMediaNotice::query()->insertOrIgnore([[
                'pet_id' => $pet->id,
                'item_key' => $key,
                'design' => $design,
                'life_stage' => (string) ($state['stage'] ?? ''),
                'outcome' => $state['outcome'],
                'created_at' => now(),
            ]]);
            if ($inserted === 1) {
                $new[] = $key;
                if (! $seen && $pet->is_active && ! $pet->is_game_over) {
                    $this->notifications->itemReady($pet, $key);
                }

                continue;
            }
            // QA #655 m3: the same stage settled again with another outcome (a fallback / no-media item
            // got its content after a Regenerate / Generate missing): the apps refresh — no second push.
            $changed = ItemMediaNotice::query()->where('pet_id', $pet->id)->where('item_key', $key)->where('design', $design)
                ->where('life_stage', (string) ($state['stage'] ?? ''))->where('outcome', '!=', $state['outcome'])
                ->update(['outcome' => $state['outcome']]);
            if ($changed > 0) {
                $new[] = $key;
            }
        }

        if ($new !== []) {
            PetUpdated::afterCommit($pet->fresh() ?? $pet, 'item_media_ready');
        }

        return $new;
    }

    // ──────────────────────────────────────────────────────────────
    //  Scheduler: media:item-media
    // ──────────────────────────────────────────────────────────────

    /**
     * Lost webhooks: queued requests without a result are POLLED at fal (free) after
     * POLL_AFTER_MINUTES and given up after TIMEOUT_MINUTES; lost downloads and dead claims are
     * queued again. Never submits.
     *
     * @return array{polled: int, timed_out: int, downloads: int, reclaimed: int}
     */
    public function sweep(): array
    {
        $polled = 0;
        $timedOut = 0;
        ItemMedia::query()->where('status', ItemMedia::STATUS_RUNNING)->whereNotNull('request_id')->whereNull('source_url')
            ->where('updated_at', '<', now()->subMinutes(self::POLL_AFTER_MINUTES))->orderBy('id')
            ->each(function (ItemMedia $row) use (&$polled, &$timedOut): void {
                match ($this->poll($row)) {
                    'recorded', 'failed' => $polled++,
                    'timed_out' => $timedOut++,
                    default => null,
                };
            });

        $downloads = 0;
        ItemMedia::query()->where('status', ItemMedia::STATUS_RUNNING)->whereNotNull('source_url')
            ->where('updated_at', '<', now()->subMinutes(60))->orderBy('id')
            ->each(function (ItemMedia $row) use (&$downloads): void {
                $row->touch();
                StoreItemMedia::dispatch($row->id);
                $downloads++;
            });

        $reclaimed = 0;
        ItemMedia::query()->where('status', ItemMedia::STATUS_RUNNING)->whereNull('request_id')->whereNull('source_url')
            ->where('updated_at', '<', now()->subMinutes(self::STALE_CLAIM_MINUTES))->orderBy('id')
            ->each(function (ItemMedia $row) use (&$reclaimed): void {
                GenerateItemMedia::dispatch($row->id);
                $reclaimed++;
            });

        return ['polled' => $polled, 'timed_out' => $timedOut, 'downloads' => $downloads, 'reclaimed' => $reclaimed];
    }

    /** @return 'recorded'|'failed'|'pending'|'timed_out'|'skipped' */
    private function poll(ItemMedia $row): string
    {
        $requestId = (string) $row->request_id;
        $canPoll = $this->fal->isEnabled() && filled($row->status_url) && filled($row->response_url) && str_contains((string) $row->status_url, $requestId);
        $poll = $canPoll ? $this->gateway->poll((string) $row->status_url, (string) $row->response_url) : null;

        return DB::transaction(function () use ($row, $requestId, $poll): string {
            $locked = ItemMedia::query()->whereKey($row->id)->where('request_id', $requestId)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== ItemMedia::STATUS_RUNNING || $locked->source_url !== null) {
                return 'skipped';
            }
            if ($poll !== null && $poll['state'] === 'completed') {
                $result = $this->fal->parseWebhookResult(['status' => 'OK', 'payload' => $poll['body']], image: $locked->isStill());

                return $this->recordResult($locked, $result) === 'recorded' ? 'recorded' : 'failed';
            }
            if ($poll !== null && $poll['state'] === 'failed') {
                $error = (string) ($poll['error'] ?? 'request failed');
                $this->fail($locked, AiCallFailure::GenerationFailed, str_starts_with($error, 'fal.ai ') ? $error : 'fal.ai: '.$error);

                return 'failed';
            }
            if ($locked->updated_at !== null && $locked->updated_at->gte(now()->subMinutes(self::TIMEOUT_MINUTES))) {
                return 'pending';
            }
            $this->fail($locked, AiCallFailure::TimedOut, 'No fal result within '.self::TIMEOUT_MINUTES.' minutes.');

            return 'timed_out';
        });
    }

    /**
     * Self-healing of failed rows (never an identical request after `no_media_generated`):
     *  - waiting for money / the switch → queued again once the budget allows (no retry counted);
     *  - `no_media_generated` → the next prompt variant (≥ RETRY_AFTER minutes later), none left → exhausted;
     *  - http_error / invalid_response / generation_failed → ≤ MAX_AUTO_RETRIES per chain, then exhausted;
     *  - anything else (timed_out, …) → exhausted.
     * Exhausted rows get ONE Discord alert per run (counts only); their pets are told (fallback) —
     * except ACTION items (treats, David 2026-11-15): those stay locked (»se pripravlja«) and the
     * alert says how many need the admin's Regenerate.
     *
     * @return array{retried: int, waiting: int, exhausted: int}
     */
    public function heal(bool $dryRun = false): array
    {
        $retried = 0;
        $waiting = 0;
        $exhausted = [];
        $dueBefore = now()->subMinutes(self::retryAfterMinutes());
        $waitingReasons = array_map(fn (AiCallFailure $r) => $r->value, self::WAITING_REASONS);
        $retryReasons = array_map(fn (AiCallFailure $r) => $r->value, self::RETRY_REASONS);

        ItemMedia::query()->where('status', ItemMedia::STATUS_FAILED)->whereNull('exhausted_at')->orderBy('id')
            ->chunkById(200, function (Collection $rows) use (&$retried, &$waiting, &$exhausted, $dueBefore, $waitingReasons, $retryReasons, $dryRun): void {
                foreach ($rows as $row) {
                    /** @var ItemMedia $row */
                    if (in_array($row->error_reason, $waitingReasons, true)) {
                        if ($this->canSpendOn($row)) {
                            $retried++;
                            if (! $dryRun) {
                                $this->requeue($row, []);
                            }
                        } else {
                            $waiting++;
                        }

                        continue;
                    }

                    if ($row->completed_at !== null && $row->completed_at->gt($dueBefore)) {
                        continue; // too early for the next try
                    }

                    $noMedia = is_string($row->error) && str_contains($row->error, PetMedia::FAL_NO_MEDIA_GENERATED);
                    if ($noMedia && $row->prompt_variant + 1 < PetAppearancePrompt::ITEM_PROMPT_VARIANTS) {
                        $retried++;
                        if (! $dryRun) {
                            $this->requeue($row, ['prompt_variant' => $row->prompt_variant + 1, 'auto_retries' => $row->auto_retries + 1]);
                        }

                        continue;
                    }

                    if (! $noMedia && in_array($row->error_reason, $retryReasons, true) && $row->auto_retries < self::maxAutoRetries()) {
                        $retried++;
                        if (! $dryRun) {
                            $this->requeue($row, ['auto_retries' => $row->auto_retries + 1]);
                        }

                        continue;
                    }

                    $exhausted[] = $row;
                }
            });

        if (! $dryRun && $exhausted !== []) {
            $marked = 0;
            $locked = 0; // rows of ACTION items: their action stays disabled until an admin regenerates
            foreach ($exhausted as $row) {
                $done = ItemMedia::query()->whereKey($row->id)->where('status', ItemMedia::STATUS_FAILED)->whereNull('exhausted_at')
                    ->update(['exhausted_at' => now(), 'alerted_at' => now()]);
                $marked += $done;
                $locked += $done === 1 && $this->locksAnAction($row) ? 1 : 0;
            }
            if ($marked > 0) {
                Log::warning('ItemMediaService: item media recovery exhausted — durable items shown without their content, action items (treats) stay locked', ['count' => $marked, 'locked' => $locked]);
                SendItemMediaAlert::dispatch($marked, $locked);
            }
            foreach ($exhausted as $row) {
                if ($row->isPicture()) {
                    // Its waiting key images are made without the 2nd reference now.
                    ItemMedia::query()->where('kind', ItemMedia::KIND_IMAGE)->where('design', $row->design)
                        ->where('status', ItemMedia::STATUS_PENDING)->orderBy('id')
                        ->each(fn (ItemMedia $waiting) => GenerateItemMedia::dispatch($waiting->id));

                    continue;
                }
                foreach ($this->petsOf($row) as $pet) {
                    $this->settle($pet);
                }
            }
        }

        return ['retried' => $retried, 'waiting' => $waiting, 'exhausted' => count($exhausted)];
    }

    /** A given-up row of an ACTION item that a pet with something left to use is waiting for (QA #1139 m6). */
    private function locksAnAction(ItemMedia $row): bool
    {
        return in_array($row->item_key, self::ACTION_ITEMS, true)
            && $this->petsOf($row)->contains(fn (Pet $pet) => $this->actionInUse($pet, (string) $row->item_key));
    }

    // ──────────────────────────────────────────────────────────────
    //  Watchdog: action items that stay »preparing«
    // ──────────────────────────────────────────────────────────────

    public static function watchdogHours(): int
    {
        return max(1, (int) config('media.item_media.watchdog_hours', 2));
    }

    /**
     * QA #1139 M2: how many active pets have an ACTION item they could use (treats left) whose
     * content has been »preparing« for more than watchdogHours(). Whatever the cause: rows waiting
     * for the AI budget (never given up), a given-up chain whose alert was lost, a pet whose own
     * picture never comes (no row at all), a stuck request. »Since« = the later of the newest pack
     * with treats left, the pet's current picture and the last move of the chain's rows (a retry /
     * Regenerate in the last hours is still at work). Reads only; counts only.
     */
    public function stuckActionPets(?CarbonImmutable $now = null): int
    {
        $before = ($now ?? CarbonImmutable::now())->subHours(self::watchdogHours());
        $stuck = 0;

        Pet::query()->where('is_active', true)->where('is_game_over', false)
            ->whereIn('id', PetItem::query()->whereNull('revoked_at')->whereIn('item_key', self::ACTION_ITEMS)->where('quantity_left', '>', 0)->select('pet_id'))
            ->chunkById(100, function (Collection $chunk) use (&$stuck, $before): void {
                foreach ($chunk as $pet) {
                    $picture = $this->pictureOf($pet);
                    foreach ($this->statesFor($pet) as $key => $state) {
                        if (! in_array($key, self::ACTION_ITEMS, true) || $state['outcome'] !== self::OUTCOME_WAITING || ! $this->actionInUse($pet, $key)) {
                            continue;
                        }
                        $since = [PetItem::where('pet_id', $pet->id)->where('item_key', $key)->whereNull('revoked_at')->where('quantity_left', '>', 0)->max('acquired_at')];
                        if ($picture !== null) {
                            $since[] = $picture['source']->completed_at ?? $picture['source']->updated_at;
                            // A chain at work (pending / running) counts from its last move; a failed one from the picture.
                            $since[] = $this->rowsQuery($picture)->where('design', $state['content_key'])
                                ->whereIn('status', [ItemMedia::STATUS_PENDING, ItemMedia::STATUS_RUNNING])->max('updated_at');
                        }
                        $latest = collect($since)->filter()->map(fn ($at) => CarbonImmutable::parse($at))->max();
                        if ($latest === null || $latest->lte($before)) {
                            $stuck++;

                            break; // one pet counts once
                        }
                    }
                }
            });

        return $stuck;
    }

    /**
     * The daily watchdog of the scheduled pass: ONE Discord alert per Ljubljana day while pets
     * are stuck (the count only — no pet, family or child), none while there are none. The claim
     * is taken only when there is something to say, so the first stuck pet of a day is reported
     * by the next pass (≤ 10 min).
     *
     * @return int pets reported (0 = none stuck, or already reported today)
     */
    public function watch(): int
    {
        $stuck = $this->stuckActionPets();
        if ($stuck === 0) {
            return 0;
        }
        $tz = (string) config('system_health.game_loop_alert_timezone', 'Europe/Ljubljana');
        if (! Cache::add(self::WATCHDOG_CLAIM.CarbonImmutable::now()->setTimezone($tz)->toDateString(), true, now()->addHours(48))) {
            return 0;
        }
        Log::warning('ItemMediaService: action items (treats) are »preparing« for too long', ['pets' => $stuck, 'hours' => self::watchdogHours()]);
        SendTreatsWaitingAlert::dispatch($stuck, self::watchdogHours());

        return $stuck;
    }

    /** @param  array<string, int>  $extra */
    private function requeue(ItemMedia $row, array $extra): void
    {
        $updated = ItemMedia::query()->whereKey($row->id)->where('status', ItemMedia::STATUS_FAILED)->whereNull('exhausted_at')
            ->update(array_merge([
                'status' => ItemMedia::STATUS_PENDING,
                'generation' => $row->generation + 1,
                // David 2026-11-04: every automatic retry is a new request (fresh seed).
                'seed_override' => PetMedia::freshSeed(),
                'download_attempts' => 0,
                'request_id' => null,
                'status_url' => null,
                'response_url' => null,
                'source_url' => null,
                'error_reason' => null,
                'error' => null,
                'updated_at' => now(),
            ], $extra));

        if ($updated === 1) {
            GenerateItemMedia::dispatch($row->id);
        }
    }

    /** Today's budget / balance allow this row's call (AiSpendGuard; the submit is guarded again). */
    private function canSpendOn(ItemMedia $row): bool
    {
        if (! $this->fal->isEnabled()) {
            return false;
        }
        $profile = match ($row->kind) {
            ItemMedia::KIND_PICTURE => $this->profiles->itemPicture(),
            ItemMedia::KIND_IMAGE => $this->profiles->itemImage(),
            default => $this->profiles->clipVideo('item'),
        };

        // QA #655 m4: a switched-off profile is not »money left« — the row keeps waiting (its item is
        // shown without it: statesFor()).
        return $profile !== null && $profile->enabled && $this->guard->refusalFor($profile->estimatedCostUsd(), AiSpendPurpose::ItemMedia) === null;
    }

    /**
     * The scheduled pass (`media:item-media`, every 10 min): catalogue pictures, lost requests,
     * self-healing, then every active pet that owns items — content queued (the backfill of items
     * bought before M6-W03 included) and settled — then the daily watchdog ({@see watch()}).
     * Pets are read in chunks.
     *
     * @return array{catalogue: int, polled: int, timed_out: int, retried: int, exhausted: int, pets: int, dispatched: int, settled: int, stuck_reported: int}
     */
    public function run(): array
    {
        $catalogue = $this->queueCatalogue();
        $sweep = $this->sweep();
        $heal = $this->heal();

        $pets = 0;
        $dispatched = 0;
        $settled = 0;
        Pet::query()->where('is_active', true)->where('is_game_over', false)
            ->whereIn('id', PetItem::query()->whereNull('revoked_at')->select('pet_id'))
            ->chunkById(100, function (Collection $chunk) use (&$pets, &$dispatched, &$settled): void {
                foreach ($chunk as $pet) {
                    $pets++;
                    $dispatched += $this->ensure($pet);
                    $settled += count($this->settle($pet->fresh() ?? $pet));
                }
            });

        return [
            'catalogue' => $catalogue,
            'polled' => $sweep['polled'],
            'timed_out' => $sweep['timed_out'],
            'retried' => $heal['retried'],
            'exhausted' => $heal['exhausted'],
            'pets' => $pets,
            'dispatched' => $dispatched,
            'settled' => $settled,
            'stuck_reported' => $this->watch(),
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Admin (/admin, superadmin)
    // ──────────────────────────────────────────────────────────────

    /**
     * Regenerate one row (a new chain — also a shared look row: every pet of the look gets the
     * new file, 0 $ for them). Refused while it is being made.
     */
    public function regenerate(ItemMedia $row): bool
    {
        if ($row->status === ItemMedia::STATUS_RUNNING && $row->updated_at !== null && $row->updated_at->gt(now()->subMinutes(self::STALE_CLAIM_MINUTES))) {
            return false;
        }

        $this->resetChain($row);
        GenerateItemMedia::dispatch($row->id);

        return true;
    }

    /**
     * »Generate missing« for one pet: exhausted / failed rows of its current content start a
     * new chain, then everything missing is queued. Returns how many jobs were dispatched.
     */
    public function generateMissing(Pet $pet): int
    {
        $picture = $this->pictureOf($pet);
        if ($picture !== null) {
            $designs = array_column($this->ownedContent($pet), 'content_key');
            $this->rowsQuery($picture)->whereIn('design', $designs)->where('status', ItemMedia::STATUS_FAILED)->get()
                ->each(function (ItemMedia $row): void {
                    $this->resetChain($row);
                    GenerateItemMedia::dispatch($row->id);
                });
        }

        return $this->ensure($pet);
    }

    /**
     * Admin (QA #655 nit): what »Generate missing« would spend for this pet at most — per owned item
     * without current content: its key image (+ the design's catalogue picture when that is not made)
     * and its clip. A shared look's content is made once for every pet of the look.
     *
     * @return array{items: int, cost_usd: float, shared: bool}
     */
    /**
     * QA #736 M2: what regenerating ONE item key image costs at most — the key image, its still
     * check(s) are separate — plus its clip (made again from the new key image).
     */
    /**
     * David 2026-11-19 (a big Dobermann lay ON TOP of a small bed): the size class of a DOG breed for
     * the item prompts — from the breed's adult weight (`breed_stage_params` `adult_weight_kg`, the
     * upper end of its range) against `media.item_media.size_classes`. Null for a cat (its beds are
     * made for cats), an unknown breed or a breed without data.
     *
     * 2026-11-20 (production: »The dog is a puppy, not an extra-large dog, so the bowls are too large
     * for it« — a Dobermann PUPPY got bowls / a bed described for an extra-large adult): the adult
     * class only for an adult / senior animal (and a legacy pet without a stage, `$stage` null); a
     * puppy or young animal gets null → PetAppearancePrompt::itemSize() sizes the item for the
     * animal AS IT IS IN THE PICTURE (»this puppy«) — in the prompt and in the check's scene.
     *
     * @return array{class: string, bed_cm: int}|null
     */
    public function sizeOf(string $breedKey, ?Species $species = null, ?LifeStage $stage = null): ?array
    {
        $species ??= PetAppearancePrompt::speciesOf($breedKey);
        $type = BreedType::tryFrom($breedKey);
        if ($species !== Species::Dog || $type === null || PetAppearancePrompt::youngNoun($stage, $species) !== null) {
            return null;
        }

        $row = collect(app(LifeStageService::class)->paramsFor($type->slug()))
            ->first(fn (array $p): bool => $p['key'] === StageParamKey::AdultWeightKg->value);
        $range = is_array($row['value'] ?? null) ? array_values(array_filter($row['value'], 'is_numeric')) : [];
        if ($range === []) {
            return null;
        }

        return self::sizeClassFor((float) max($range));
    }

    /**
     * PURE: the size class of an adult weight (kg) — the first `media.item_media.size_classes` entry
     * whose `max_kg` it does not exceed (the last one above all).
     *
     * @return array{class: string, bed_cm: int}|null
     */
    public static function sizeClassFor(float $adultKg): ?array
    {
        $classes = array_values((array) config('media.item_media.size_classes', []));
        foreach ($classes as $i => $c) {
            if ($adultKg <= (float) ($c['max_kg'] ?? INF) || $i === count($classes) - 1) {
                return ['class' => (string) $c['class'], 'bed_cm' => (int) $c['bed_cm']];
            }
        }

        return null;
    }

    public function keyImageRegenerationCostUsd(): float
    {
        $image = $this->profiles->itemImage()?->estimatedCostUsd() ?? 0.0;
        // Worst case: the bowls' key image remakes two clips (eating + drinking, David 2026-11-20).
        $clip = $this->clipsEnabled() ? 2 * $this->profiles->clipVideo('item')->estimatedCostUsd() : 0.0;

        return round($image + $clip, 4);
    }

    public function missingCostFor(Pet $pet): array
    {
        $states = $this->statesFor($pet);
        $image = $this->profiles->itemImage()?->estimatedCostUsd() ?? 0.0;
        $picture = $this->profiles->itemPicture()?->estimatedCostUsd() ?? 0.0;
        $clip = $this->clipsEnabled() ? $this->profiles->clipVideo('item')->estimatedCostUsd() : 0.0;
        $pictures = ItemMedia::where('scope', ItemMedia::SCOPE_CATALOGUE)->whereNotNull('storage_path')->pluck('design')->flip();
        $items = 0;
        $cost = 0.0;
        foreach ($states as $state) {
            // David 2026-11-20: the bowls' drinking clip is one more clip when it is not shown yet.
            $drink = $clip > 0 && in_array($state['item_key'], ItemMedia::DRINK_ITEMS, true) && $state['drink_clip'] === null && $state['outcome'] !== self::OUTCOME_NO_MEDIA;
            if ($state['outcome'] === self::OUTCOME_CONTENT || $state['outcome'] === self::OUTCOME_NO_MEDIA) {
                if ($drink && $state['outcome'] === self::OUTCOME_CONTENT) {
                    $items++;
                    $cost += $clip;
                }

                continue;
            }
            $cost += $drink ? $clip : 0.0;
            $items++;
            if ($state['image'] === null) {
                $cost += $image + (ItemDesigns::find((string) $state['content_key']) !== null && ! $pictures->has($state['content_key']) ? $picture : 0.0);
            }
            $cost += $clip;
        }

        return ['items' => $items, 'cost_usd' => round($cost, 2), 'shared' => $pet->pet_look_id !== null];
    }

    /**
     * The rows an admin sees for a pet (its own rows, or its look's rows of the owned designs).
     *
     * @return Builder<ItemMedia>
     */
    public static function rowsOfPetQuery(Pet $pet)
    {
        $designs = PetItem::query()->where('pet_id', $pet->id)->pluck('design')->filter()->all();
        $designs[] = ItemDesigns::treatKey($pet->speciesValue());

        return $pet->pet_look_id !== null
            ? ItemMedia::query()->where(fn ($q) => $q->where('pet_id', $pet->id)
                ->orWhere(fn ($q) => $q->where('pet_look_id', $pet->pet_look_id)->whereIn('design', $designs)))
            : ItemMedia::query()->where('pet_id', $pet->id);
    }

    /** One stage label for admin lists. */
    public static function stageLabel(?string $stage): string
    {
        return $stage !== null ? (LifeStage::tryFrom($stage)?->value ?? $stage) : '—';
    }

    /** Admin severity of a row (for the Discord alert text / panel). */
    public static function alertKind(): AdminAlertKind
    {
        return AdminAlertKind::ItemMediaStuck;
    }
}
