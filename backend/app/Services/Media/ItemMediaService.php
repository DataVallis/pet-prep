<?php

namespace App\Services\Media;

use App\Enums\AdminAlertKind;
use App\Enums\AiCallFailure;
use App\Enums\AiSpendPurpose;
use App\Enums\LifeStage;
use App\Enums\Species;
use App\Events\PetUpdated;
use App\Jobs\GenerateItemMedia;
use App\Jobs\SendItemMediaAlert;
use App\Jobs\StoreItemMedia;
use App\Models\AiSpendLedger;
use App\Models\ItemMedia;
use App\Models\ItemMediaNotice;
use App\Models\Pet;
use App\Models\PetItem;
use App\Models\PetLook;
use App\Models\PetMedia;
use App\Models\ShopItem;
use App\Services\FalAiService;
use App\Services\NotificationService;
use App\Services\Shop\ItemDesigns;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\File;
use Illuminate\Support\Collection;
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
 * disabled, its own picture given up) · waiting (»Na poti …«). Free plan (S8): only the item's
 * own content — the pet's state media set is never touched. Items never touch game rules (S1).
 *
 * Self-healing like ActivityClipRetryService (PR #547): failures with http_error /
 * invalid_response / generation_failed are retried ≥ RETRY_AFTER minutes later, at most
 * MAX_AUTO_RETRIES per failure chain; fal's `no_media_generated` is never sent identically again
 * (the next prompt variant; none left → exhausted); `timed_out` → exhausted (a late webhook still
 * stores); budget / balance / switched-off failures wait for money (no retry counted). Exhausted
 * → the item is shown without that content + ONE Discord alert per run (counts only). No admin
 * click is ever needed; /admin can Regenerate a row or »Generate missing« for a pet.
 */
class ItemMediaService
{
    /** Bed and toys loop; bowls and treats are played once. */
    public const LOOP_ITEMS = ['bed', 'toy_1', 'toy_2'];

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

    /** fal + both item profiles usable — otherwise no item content is made at all. */
    public function enabled(): bool
    {
        return $this->fal->isEnabled() && $this->profiles->itemImage() !== null;
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
     * @return array<string, array{item_key: string, design: string|null, content_key: string, outcome: string, stage: string|null, image: ItemMedia|null, clip: ItemMedia|null}>
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

        $states = [];
        foreach ($owned as $key => $own) {
            $state = $own + ['outcome' => self::OUTCOME_WAITING, 'stage' => $picture['stage'] ?? null, 'image' => null, 'clip' => null];

            if ($noMedia) {
                $states[$key] = ['outcome' => self::OUTCOME_NO_MEDIA] + $state;

                continue;
            }
            if ($picture === null) {
                $states[$key] = $state; // the pet's own picture is on its way

                continue;
            }

            $image = $rows->first(fn (ItemMedia $r) => $r->design === $own['content_key'] && $r->kind === ItemMedia::KIND_IMAGE);
            $clip = $rows->first(fn (ItemMedia $r) => $r->design === $own['content_key'] && $r->kind === ItemMedia::KIND_CLIP);
            $imageCurrent = $image !== null && $image->isServable() && $image->source_path === $picture['path'];
            $clipCurrent = $imageCurrent && $clip !== null && $clip->isServable() && $clip->source_path === $image->storage_path;

            $state['image'] = $imageCurrent ? $image : null;
            $state['clip'] = $clipCurrent ? $clip : null;
            $state['outcome'] = match (true) {
                $clipCurrent => self::OUTCOME_CONTENT,
                ! $imageCurrent && $image !== null && $image->isExhausted() && $image->target_path === $picture['path'] => self::OUTCOME_FALLBACK,
                $imageCurrent && $clip !== null && $clip->isExhausted() && $clip->target_path === $image->storage_path => self::OUTCOME_FALLBACK,
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
     * The payload's `media.items`: per owned item whose content for the current picture is
     * shown — signed URLs of OUR files (never fal URLs). Absent while it is made, for a pet
     * without AI media and for a fallback without any picture.
     *
     * @param  Collection<int, PetMedia>|null  $slots
     * @return array<string, array{image: string|null, video: string|null, video_seconds: float|null, loop: bool}>
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
            $image = $this->row($picture, $own['item_key'], $own['content_key'], ItemMedia::KIND_IMAGE);

            if ($image->isServable() && $image->source_path === $picture['path']) {
                $clip = $this->row($picture, $own['item_key'], $own['content_key'], ItemMedia::KIND_CLIP);
                $dispatched += $this->ensureRow($clip, (string) $image->storage_path) ? 1 : 0;

                continue;
            }

            $dispatched += $this->ensureRow($image, $picture['path']) ? 1 : 0;
        }

        return $dispatched;
    }

    /**
     * One row wanted from $source: done when its stored file is made from it; a running /
     * failed chain for $source is left alone (heal() decides); anything else is reset (new
     * chain) and dispatched.
     */
    private function ensureRow(ItemMedia $row, string $source): bool
    {
        if ($row->isServable() && $row->source_path === $source && $row->status !== ItemMedia::STATUS_PENDING) {
            return false;
        }

        if ($row->target_path === $source && in_array($row->status, [ItemMedia::STATUS_RUNNING, ItemMedia::STATUS_FAILED], true)) {
            return false;
        }

        if ($row->status === ItemMedia::STATUS_RUNNING && $row->request_id !== null) {
            return false; // a request for an older picture is still out — its result is stale, then we come back
        }

        if ($row->status !== ItemMedia::STATUS_PENDING || $row->target_path !== $source) {
            $this->resetChain($row, $source);
        }

        GenerateItemMedia::dispatch($row->id);

        return true;
    }

    /** Start a new failure chain for $row from $source (pending, counters cleared, new generation when something was made / sent). */
    public function resetChain(ItemMedia $row, ?string $source = null): void
    {
        $bump = $row->isServable() || $row->request_id !== null || $row->status === ItemMedia::STATUS_FAILED;

        $row->update([
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
            'exhausted_at' => null,
            'alerted_at' => null,
            'completed_at' => null,
        ]);
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
            $seed = (int) (crc32($row->design) & 0x7FFFFFFF);

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
            $prompt = $this->prompts->itemImagePrompt($breedKey, $row->item_key, $description, $traits, $stage, $picture !== null, $row->prompt_variant);

            return [
                'target' => (string) $sourceImage->storage_path,
                'stage' => $stageValue,
                'submit' => fn (): array => $this->fal->submitItemImage($prompt, $this->media->falFetchUrl($sourceImage), $picture !== null ? $this->falFetchUrl($picture) : null, $seed, $petId, $row->id),
            ];
        }

        $image = $this->siblingImage($row);
        if ($image === null || ! $image->isServable()) {
            return null;
        }
        $species = PetAppearancePrompt::speciesOf($breedKey);
        $clipStage = $image->life_stage !== null ? LifeStage::tryFrom($image->life_stage) : null;
        $prompt = $this->prompts->itemClipPrompt($breedKey, $row->item_key, $description, $traits, $clipStage, $row->prompt_variant);

        return [
            'target' => (string) $image->storage_path,
            'stage' => $image->life_stage,
            'submit' => fn (): array => $this->fal->submitItemClip($prompt, $this->prompts->itemClipNegativePrompt($species), $this->falFetchUrl($image), $petId, $row->id),
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

        $previous = null;
        $stored = DB::transaction(function () use ($row, $sourceUrl, $path, $file, &$previous): bool {
            $locked = ItemMedia::whereKey($row->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== ItemMedia::STATUS_RUNNING || $locked->source_url !== $sourceUrl) {
                return false;
            }
            $previous = $locked->storage_path;
            $locked->update([
                'status' => ItemMedia::STATUS_READY,
                'storage_path' => $path,
                'source_path' => $locked->target_path,
                'bytes' => $file['bytes'],
                'mime' => $file['mime'],
                'error_reason' => null,
                'error' => null,
                'auto_retries' => 0,
                'exhausted_at' => null,
                'completed_at' => now(),
            ]);

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

    /** What follows a stored file: picture → its waiting key images; image → its clip; clip → the pets are told. */
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
            $this->settle($pet);
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
     * picture — no second »new bed« push). Idempotent (`item_media_notices`).
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
     * Exhausted rows get ONE Discord alert per run (counts only); their pets are told (fallback).
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
            foreach ($exhausted as $row) {
                $marked += ItemMedia::query()->whereKey($row->id)->where('status', ItemMedia::STATUS_FAILED)->whereNull('exhausted_at')
                    ->update(['exhausted_at' => now(), 'alerted_at' => now()]);
            }
            if ($marked > 0) {
                Log::warning('ItemMediaService: item media recovery exhausted — items shown without their content', ['count' => $marked]);
                SendItemMediaAlert::dispatch($marked);
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

    /** @param  array<string, int>  $extra */
    private function requeue(ItemMedia $row, array $extra): void
    {
        $updated = ItemMedia::query()->whereKey($row->id)->where('status', ItemMedia::STATUS_FAILED)->whereNull('exhausted_at')
            ->update(array_merge([
                'status' => ItemMedia::STATUS_PENDING,
                'generation' => $row->generation + 1,
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

        return $profile !== null && $this->guard->refusalFor($profile->estimatedCostUsd(), AiSpendPurpose::ItemMedia) === null;
    }

    /**
     * The scheduled pass (`media:item-media`, every 10 min): catalogue pictures, lost requests,
     * self-healing, then every active pet that owns items — content queued (the backfill of items
     * bought before M6-W03 included) and settled. Pets are read in chunks.
     *
     * @return array{catalogue: int, polled: int, timed_out: int, retried: int, exhausted: int, pets: int, dispatched: int, settled: int}
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
