<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 2026-11-20 — production evidence after #1453 / #1429 / #1417: every bowls DRINKING clip (rows
 * 69–72) and the bowls EATING clip of pet 8 (row 60) were given up after their 3 tries
 * (`quality_failed`). Causes, both fixed in the same PR: (a) the bowls key image showed the dog
 * EATING (head in the kibble, a water bowl without visible water), so Kling kept eating and the
 * check saw kibble in frame 1 — the key image is now a NEUTRAL pose both clips start from;
 * (b) the items were sized for the breed's ADULT class (»extra-large dog«) while the pet is a
 * puppy — now sized for the animal's current stage. A clip made from an OLD (eating-pose) key
 * image would repeat (a), so such key images get ONE fresh chain here; its store remakes both
 * clips from it (ItemMediaService::ensure() resets a clip whose source changed). No manual step
 * (David 2026-11-19: it must work within 3 tries without his intervention).
 *
 * Which key images (every one that exists when this migration runs is pre-fix; never one an admin approved —
 * `quality = approved`, like regenerateKeyImageOf() — and never a hidden one):
 *  - BOWLS, whoever owns them (QA #1459 M1 — a pre-fix eating-pose key image must never be reused
 *    for a new drinking clip, also not for a later buyer of the same look / design / stage):
 *      · a stored key image whose `clip` or `drink_clip` was given up with `quality_failed` on it, or
 *        that has no stored drinking clip made from it;
 *      · a key image given up itself with `quality_failed`;
 *  - BED (the size bug only, fix (b)), at a puppy / young stage, only while an owner shows it:
 *      · a stored key image whose clip was given up on a SIZE reason (size / large / small / scale / big);
 *      · a key image given up itself on a size reason.
 * »Owner« = an active, not game-over pet that owns the item IN THIS DESIGN (unrevoked) and whose
 * current picture is the one the key image is made from (own row, or its look's row).
 *
 * A reopened key image is set to `pending` in place (new generation, fresh seed, check retries /
 * variants / counters / completion cleared — like ItemMediaService::resetChain()); a stored file
 * stays shown meanwhile, so nothing goes back to »preparing«. It is SUBMITTED only by
 * ItemMediaService::ensure() for a pet that owns it (`media:item-media` ≤ 10 min after deploy for
 * today's owners; for an unowned row: when someone buys it) or by a catalogue-picture store /
 * give-up, which since this PR dispatch a waiting key image only when it has an owner
 * (ItemMediaService::hasOwner()). The pending row is not touched by media:still-check /
 * media:item-recheck (they select stored, READY rows only), heal() (failed rows) or the sweep
 * (running rows) — so an unowned row costs 0 $ until it is bought. Every submit is guarded by
 * AiSpendGuard.
 *
 * Hard cap (QA #1459 m3): at most MAX_REOPEN key images. Over it, only the OWNED ones (the only
 * ones that spend now) are reopened and a warning is logged; if even those are over the cap,
 * nothing is reopened (warning) — an unexpected data set is never turned into spend unattended.
 *
 * Cost bound (production 2026-11-20: 4 owned key images — pet 8, pet 9, look 3, look 11 — for
 * rows 60, 69–72): one try each ≈ 4 × (0.15 $ key image + 2 × 0.34 $ clips) ≈ 3.3 $; worst case
 * with every automatic check retry (3 tries per row) ≈ 4 × (3 × 0.15 + 2 × 3 × 0.34) ≈ 10 $
 * (+ ≈ 0.003 $ per vision check). An unowned reopened row: 0 $ now; when bought later, the same
 * ≤ ≈ 2.5 $ a first purchase of that look / design / stage costs anyway. Absolute bound for the
 * whole migration: MAX_REOPEN × ≈ 2.5 $ ≈ 50 $, and only as rows are owned.
 *
 * Idempotent: a reopened key image is pending, no longer ready / given up, so it never matches
 * again. No personal data (ids only in the log line).
 */
return new class extends Migration
{
    public const MAX_REOPEN = 20;

    public function up(): void
    {
        $this->reopen();
    }

    public function down(): void
    {
        // Nothing to undo: the key images only got one more automatic chain.
    }

    /** @return list<int> the key image ids that got a fresh chain */
    public function reopen(): array
    {
        $now = now();
        $candidates = $this->bowlsCandidates()->concat($this->bedCandidates($now))->unique('id')->values();

        $owned = $candidates->filter(fn (object $img): bool => $this->ownedBy($img))->values();
        $chosen = $candidates;
        if ($candidates->count() > self::MAX_REOPEN) {
            $chosen = $owned->count() <= self::MAX_REOPEN ? $owned : collect();
            Log::warning('Item media: more pre-fix bowls / bed key images than the reopen cap', [
                'found' => $candidates->count(), 'owned' => $owned->count(), 'cap' => self::MAX_REOPEN,
                'reopening' => $chosen->count() === 0 ? 'none' : 'only the owned ones',
            ]);
        }

        $reopened = [];
        foreach ($chosen as $img) {
            $done = DB::table('item_media')->where('id', $img->id)->where('kind', 'image')
                ->where(fn ($q) => $q->where('quality', '<>', 'approved')->orWhereNull('quality'))
                ->where(fn ($q) => $q->where('status', 'ready')->orWhere(fn ($f) => $f->where('status', 'failed')->whereNotNull('exhausted_at')))
                ->update([
                    'status' => 'pending',
                    'generation' => DB::raw('generation + 1'),
                    'seed_override' => random_int(1, 2_147_483_647),
                    'target_path' => $img->picture,
                    'quality_retries' => 0,
                    'prompt_variant' => 0,
                    'auto_retries' => 0,
                    'download_attempts' => 0,
                    'request_id' => null,
                    'status_url' => null,
                    'response_url' => null,
                    'source_url' => null,
                    'candidate_path' => null,
                    'candidate_bytes' => null,
                    'candidate_mime' => null,
                    'error_reason' => null,
                    'error' => null,
                    'exhausted_at' => null,
                    'alerted_at' => null,
                    'completed_at' => null,
                    'updated_at' => $now,
                ]);
            if ($done === 1) {
                $reopened[] = (int) $img->id;
            }
        }

        if ($reopened !== []) {
            Log::info('Item media: bowls / bed key images from before the neutral-pose / puppy-size fix get one fresh chain', [
                'item_media_ids' => $reopened, 'owned' => $owned->pluck('id')->intersect($reopened)->values()->all(),
            ]);
        }

        return $reopened;
    }

    /** @return Collection<int, object> pre-fix bowls key images (any owner). */
    private function bowlsCandidates(): Collection
    {
        $usable = fn ($q) => $q->where('img.kind', 'image')->where('img.item_key', 'bowls')
            ->where(fn ($w) => $w->where('img.quality', '<>', 'approved')->orWhereNull('img.quality'));
        $sameOwner = fn ($q, string $c) => $q->whereColumn("{$c}.scope", 'img.scope')->whereColumn("{$c}.design", 'img.design')
            ->whereRaw("{$c}.pet_id IS NOT DISTINCT FROM img.pet_id")
            ->whereRaw("{$c}.pet_look_id IS NOT DISTINCT FROM img.pet_look_id")
            ->whereRaw("(img.scope <> 'look' OR {$c}.life_stage IS NOT DISTINCT FROM img.life_stage)");

        // Stored, shown key images: a clip given up on them, or no stored drinking clip made from them.
        $stored = DB::table('item_media as img')->where($usable)
            ->where('img.status', 'ready')->whereNotNull('img.storage_path')
            ->where(fn ($q) => $q->where('img.quality', '<>', 'flagged')->orWhereNull('img.quality')->orWhereNull('img.flagged_at'))
            ->where(fn ($q) => $q
                ->whereExists(fn ($e) => $e->selectRaw('1')->from('item_media as clip')
                    ->where(fn ($j) => $sameOwner($j, 'clip'))
                    ->whereIn('clip.kind', ['clip', 'drink_clip'])->whereColumn('clip.target_path', 'img.storage_path')
                    ->where('clip.status', 'failed')->where('clip.error_reason', 'quality_failed')->whereNotNull('clip.exhausted_at'))
                ->orWhereNotExists(fn ($e) => $e->selectRaw('1')->from('item_media as drink')
                    ->where(fn ($j) => $sameOwner($j, 'drink'))
                    ->where('drink.kind', 'drink_clip')->whereNotNull('drink.storage_path')->whereColumn('drink.source_path', 'img.storage_path')))
            ->select('img.id', 'img.scope', 'img.pet_id', 'img.pet_look_id', 'img.item_key', 'img.design', DB::raw('COALESCE(img.source_path, img.target_path) as picture'))
            ->get();

        // Key images given up themselves on their check.
        $givenUp = DB::table('item_media as img')->where($usable)
            ->where('img.status', 'failed')->where('img.error_reason', 'quality_failed')->whereNotNull('img.exhausted_at')
            ->select('img.id', 'img.scope', 'img.pet_id', 'img.pet_look_id', 'img.item_key', 'img.design', DB::raw('COALESCE(img.source_path, img.target_path) as picture'))
            ->get();

        return $stored->concat($givenUp)->filter(fn (object $img): bool => $img->picture !== null)->values();
    }

    /** @return Collection<int, object> bed key images given up on the SIZE at a puppy / young stage that an owner still shows. */
    private function bedCandidates($now): Collection
    {
        $size = fn ($q, string $col) => $q->where(fn ($w) => $w->where($col, 'ilike', '%size%')->orWhere($col, 'ilike', '%large%')
            ->orWhere($col, 'ilike', '%small%')->orWhere($col, 'ilike', '%scale%')->orWhere($col, 'ilike', '%big%'));
        $givenUp = fn ($q, string $t) => $q->where("{$t}.status", 'failed')->where("{$t}.error_reason", 'quality_failed')
            ->whereNotNull("{$t}.exhausted_at")->where("{$t}.exhausted_at", '<', $now);
        $base = fn ($q) => $q->where('img.kind', 'image')->where('img.item_key', 'bed')->whereIn('img.life_stage', ['puppy', 'young'])
            ->where(fn ($w) => $w->where('img.quality', '<>', 'approved')->orWhereNull('img.quality'));
        $select = ['img.id', 'img.scope', 'img.pet_id', 'img.pet_look_id', 'img.item_key', 'img.design'];

        $fromClips = DB::table('item_media as img')->where($base)
            ->where('img.status', 'ready')->whereNotNull('img.storage_path')
            ->where(fn ($q) => $q->where('img.quality', '<>', 'flagged')->orWhereNull('img.quality')->orWhereNull('img.flagged_at'))
            ->whereExists(fn ($e) => $e->selectRaw('1')->from('item_media as clip')
                ->whereColumn('clip.scope', 'img.scope')->whereColumn('clip.design', 'img.design')->where('clip.kind', 'clip')
                ->whereRaw('clip.pet_id IS NOT DISTINCT FROM img.pet_id')->whereRaw('clip.pet_look_id IS NOT DISTINCT FROM img.pet_look_id')
                ->whereRaw("(img.scope <> 'look' OR clip.life_stage IS NOT DISTINCT FROM img.life_stage)")
                ->whereColumn('clip.target_path', 'img.storage_path')
                ->where(fn ($q) => $size($givenUp($q, 'clip'), 'clip.error')))
            ->select(array_merge($select, [DB::raw('img.source_path as picture')]))->get();

        $images = DB::table('item_media as img')->where($base)
            ->where(fn ($q) => $size($givenUp($q, 'img'), 'img.error'))
            ->select(array_merge($select, [DB::raw('COALESCE(img.source_path, img.target_path) as picture')]))->get();

        return $fromClips->concat($images)->filter(fn (object $img): bool => $img->picture !== null && $this->ownedBy($img))->values();
    }

    /** An active, not game-over pet that owns the item in this design (unrevoked) and shows the picture the key image is made from. */
    private function ownedBy(object $img): bool
    {
        return DB::table('pets')
            ->where('pets.is_active', true)->where('pets.is_game_over', false)
            ->when($img->scope === 'look', fn ($q) => $q->where('pets.pet_look_id', $img->pet_look_id), fn ($q) => $q->where('pets.id', $img->pet_id))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('pet_items')->whereColumn('pet_items.pet_id', 'pets.id')
                ->where('pet_items.item_key', $img->item_key)->whereNull('pet_items.revoked_at')
                // A pack without a design (bought before M6-W03) shows the default design.
                ->where(fn ($d) => $d->where('pet_items.design', $img->design)->orWhereNull('pet_items.design')))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('pet_media')->whereColumn('pet_media.pet_id', 'pets.id')
                ->where('pet_media.kind', 'image')->where('pet_media.storage_path', $img->picture))
            ->exists();
    }
};
