<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * David 2026-11-15 (the general media rule): a treat is never given without its clip, so a
 * treat chain whose automatic recovery gave up no longer means »shown without its content« —
 * »Priboljšek« stays disabled until the clip exists (ItemMediaService::ACTION_ITEMS).
 *
 * Treat rows that were given up BEFORE this rule were announced to the admin as »no action
 * needed«. Those a pet is waiting for NOW get one fresh round of the automatic recovery here
 * (no manual production step): `exhausted_at` / `alerted_at` cleared, the retry counter and the
 * prompt variant reset, so the next scheduled `media:item-media` pass (every 10 min,
 * ItemMediaService::heal) takes them up again under the AI budget.
 *
 * Which rows (QA #1139 m4): only given-up treat rows that an ACTIVE pet (not game over) with
 * TREATS LEFT currently needs — its own row, or the row of its shared look at the life stage
 * of the picture the pet shows. Rows nobody waits for stay given up (a later purchase reopens
 * them — ItemMediaService::reopenGivenUp).
 *
 * What a reopened row may cost (QA #1139 m5), each try guarded by AiSpendGuard:
 *  - a failed generation (`http_error` / `invalid_response` / `generation_failed`): at most
 *    `media.item_media.max_auto_retries` (2) paid tries — 2 × 0,15 $ (key image) or 2 × 0,34 $ (clip);
 *  - fal's refusal `no_media_generated`: the prompt variant is reset to 0, so heal() tries the
 *    NEXT variant once (PetAppearancePrompt::ITEM_PROMPT_VARIANTS = 2 → 1 paid try, with a fresh
 *    seed — never the identical request) instead of giving the row up again at once;
 *  - `timed_out` / `quality_failed`: not retried automatically — given up again by the next
 *    pass, this time with the alert that says the treats stay locked until »Regenerate«.
 *
 * Idempotent (a reopened row no longer matches); no personal data.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->reopenGivenUpTreats();
    }

    public function down(): void
    {
        // Nothing to undo: the rows only got another round of the automatic recovery.
    }

    public function reopenGivenUpTreats(): int
    {
        // Active pets that could give a treat now.
        $waiting = fn () => DB::table('pets')
            ->where('pets.is_active', true)
            ->where('pets.is_game_over', false)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('pet_items')
                ->whereColumn('pet_items.pet_id', 'pets.id')
                ->where('pet_items.item_key', 'treats')
                ->whereNull('pet_items.revoked_at')
                ->where('pet_items.quantity_left', '>', 0));

        return DB::table('item_media')
            ->where('item_media.item_key', 'treats')
            ->where('item_media.status', 'failed')
            ->whereNotNull('item_media.exhausted_at')
            ->where(fn ($q) => $q
                // The pet's own row.
                ->where(fn ($q) => $q->where('item_media.scope', 'pet')
                    ->whereIn('item_media.pet_id', $waiting()->select('pets.id')))
                // The row of a shared look, at the stage of the picture such a pet shows.
                ->orWhere(fn ($q) => $q->where('item_media.scope', 'look')
                    ->whereExists(fn ($q) => $q->selectRaw('1')->fromSub($waiting()->select('pets.id', 'pets.pet_look_id'), 'p')
                        ->join('pet_media', fn ($j) => $j->on('pet_media.pet_id', '=', 'p.id')->where('pet_media.kind', 'image'))
                        ->whereColumn('p.pet_look_id', 'item_media.pet_look_id')
                        ->whereColumn('pet_media.life_stage', 'item_media.life_stage'))))
            ->update(['exhausted_at' => null, 'alerted_at' => null, 'auto_retries' => 0, 'prompt_variant' => 0, 'updated_at' => now()]);
    }
};
