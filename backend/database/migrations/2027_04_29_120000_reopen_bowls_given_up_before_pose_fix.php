<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 2026-11-20 — production evidence after #1453 / #1429 / #1417: every bowls DRINKING clip (rows
 * 69–72) and the bowls EATING clip of pet 8 (row 60) were given up after their 3 tries
 * (`quality_failed`). Causes, both fixed in the same PR: (a) the bowls key image showed the dog
 * EATING (head in the kibble, a water bowl without visible water), so Kling kept eating and the
 * check saw kibble in frame 1 — the key image is now a NEUTRAL pose both clips start from;
 * (b) the items were sized for the breed's ADULT class (»extra-large dog«) while the pet is a
 * puppy — now sized for the animal's current stage. A clip remade from the OLD key image would
 * repeat (a), so each affected key image gets ONE fresh chain here; its store remakes both clips
 * from it (ItemMediaService::ensure() resets a clip whose source changed). No manual step (David
 * 2026-11-19: it must work within 3 tries without his intervention).
 *
 * Which key images — all of:
 *  - bowls: a `clip` or `drink_clip` row given up with `quality_failed` (status failed,
 *    `exhausted_at` set, before this migration ran) on the key image it was made from
 *    (`target_path` = the key image's `storage_path`);
 *  - bed (the same size bug, fix (b)): a bed `clip` row given up likewise at a puppy / young stage
 *    whose reason names the size (size / large / small / scale / big);
 *  - the key image itself (bowls or bed, puppy / young, size reason) given up with `quality_failed`;
 *  - and an OWNER STILL SHOWS IT: an active, not game-over pet that owns the item (unrevoked) and
 *    whose current picture is the one the key image was made from (own row, or its look's row).
 * A STORED key image (status ready, not hidden) gets a fresh chain in place (pending, new
 * generation, fresh seed, check retries / variants / counters cleared) — its old file stays shown
 * meanwhile, so nothing goes back to »preparing«; a given-up key image row the same way. The
 * next `media:item-media` pass (≤ 10 min) dispatches it (ensure()); every submit is guarded by
 * AiSpendGuard (budget waits like every item row).
 *
 * Cost bound (production 2026-11-20: 4 key images — pet 8, pet 9, look 3, look 11 — for rows
 * 60, 69, 70, 71, 72): one try each ≈ 4 × (0.15 $ key image + 2 × 0.34 $ clips) ≈ 3.3 $; worst
 * case with every automatic check retry (3 tries per row) ≈ 4 × (3 × 0.15 + 2 × 3 × 0.34) ≈ 10 $
 * (+ ≈ 0.003 $ per vision check). Rows given up after this migration are never reopened by it.
 *
 * Idempotent: a reopened key image is pending, no longer ready / given up, so it never matches
 * again. No personal data (ids only in the log line).
 */
return new class extends Migration
{
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
        $sizeReason = fn ($q, string $column) => $q->where(fn ($w) => $w->where($column, 'ilike', '%size%')->orWhere($column, 'ilike', '%large%')
            ->orWhere($column, 'ilike', '%small%')->orWhere($column, 'ilike', '%scale%')->orWhere($column, 'ilike', '%big%'));
        $givenUp = fn ($q, string $t) => $q->where("{$t}.status", 'failed')->where("{$t}.error_reason", 'quality_failed')
            ->whereNotNull("{$t}.exhausted_at")->where("{$t}.exhausted_at", '<', $now);

        // Key images whose clip was given up (bowls: any reason; bed: a size reason at puppy / young).
        $fromClips = DB::table('item_media as img')
            ->join('item_media as clip', function ($j) {
                $j->on('clip.scope', '=', 'img.scope')->on('clip.design', '=', 'img.design')->on('clip.target_path', '=', 'img.storage_path')
                    ->whereRaw('clip.pet_id IS NOT DISTINCT FROM img.pet_id')
                    ->whereRaw('clip.pet_look_id IS NOT DISTINCT FROM img.pet_look_id')
                    ->whereRaw("(img.scope <> 'look' OR clip.life_stage IS NOT DISTINCT FROM img.life_stage)");
            })
            ->where('img.kind', 'image')->where('img.status', 'ready')->whereNotNull('img.storage_path')
            ->where(fn ($q) => $q->where('img.quality', '<>', 'flagged')->orWhereNull('img.quality')->orWhereNull('img.flagged_at'))
            ->where(fn ($q) => $givenUp($q, 'clip'))
            ->where(fn ($q) => $q
                ->where(fn ($b) => $b->where('img.item_key', 'bowls')->whereIn('clip.kind', ['clip', 'drink_clip']))
                ->orWhere(fn ($b) => $sizeReason($b->where('img.item_key', 'bed')->where('clip.kind', 'clip')->whereIn('img.life_stage', ['puppy', 'young']), 'clip.error')))
            ->select('img.id', 'img.scope', 'img.pet_id', 'img.pet_look_id', 'img.item_key', 'img.source_path', 'img.target_path')
            ->distinct()->get();

        // Key images given up themselves on the size (bowls / bed, puppy / young).
        $images = DB::table('item_media as img')
            ->where('img.kind', 'image')->whereIn('img.item_key', ['bowls', 'bed'])->whereIn('img.life_stage', ['puppy', 'young'])
            ->where(fn ($q) => $givenUp($q, 'img'))
            ->where(fn ($q) => $sizeReason($q, 'img.error'))
            ->select('img.id', 'img.scope', 'img.pet_id', 'img.pet_look_id', 'img.item_key', 'img.source_path', 'img.target_path')
            ->get();

        $reopened = [];
        foreach ($fromClips->concat($images)->unique('id') as $img) {
            // The picture the key image is (or, given up, was meant to be) made from.
            $picture = $img->source_path ?? $img->target_path;
            if ($picture === null || ! $this->shownByAnOwner($img, (string) $picture)) {
                continue;
            }

            $done = DB::table('item_media')->where('id', $img->id)->where('kind', 'image')
                ->where(fn ($q) => $q->where('status', 'ready')->orWhere(fn ($f) => $f->where('status', 'failed')->whereNotNull('exhausted_at')))
                ->update([
                    'status' => 'pending',
                    'generation' => DB::raw('generation + 1'),
                    'seed_override' => random_int(1, 2_147_483_647),
                    'target_path' => $picture,
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
                    'updated_at' => $now,
                ]);
            if ($done === 1) {
                $reopened[] = (int) $img->id;
            }
        }

        if ($reopened !== []) {
            Log::info('Item media: bowls / bed key images given up before the neutral-pose / puppy-size fix get one fresh chain', ['item_media_ids' => $reopened]);
        }

        return $reopened;
    }

    /** An active, not game-over pet that owns the item and shows the picture the key image is made from. */
    private function shownByAnOwner(object $img, string $picture): bool
    {
        return DB::table('pets')
            ->where('pets.is_active', true)->where('pets.is_game_over', false)
            ->when($img->scope === 'look', fn ($q) => $q->where('pets.pet_look_id', $img->pet_look_id), fn ($q) => $q->where('pets.id', $img->pet_id))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('pet_items')->whereColumn('pet_items.pet_id', 'pets.id')
                ->where('pet_items.item_key', $img->item_key)->whereNull('pet_items.revoked_at'))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('pet_media')->whereColumn('pet_media.pet_id', 'pets.id')
                ->where('pet_media.kind', 'image')->where('pet_media.storage_path', $picture))
            ->exists();
    }
};
