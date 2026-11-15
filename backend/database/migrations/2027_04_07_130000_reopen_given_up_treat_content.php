<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * David 2026-11-15 (the general media rule): a treat is never given without its clip, so a
 * treat chain whose automatic recovery gave up no longer means »shown without its content« —
 * »Priboljšek« stays disabled until the clip exists (ItemMediaService::ACTION_ITEMS).
 *
 * Treat rows that were given up BEFORE this rule were announced to the admin as »no action
 * needed«. They get one fresh round of the automatic recovery here (no manual production step):
 * `exhausted_at` / `alerted_at` cleared and the retry counter reset, so the next scheduled
 * `media:item-media` pass (every 10 min, ItemMediaService::heal) retries them under the AI
 * budget — at most `media.item_media.max_auto_retries` (2) paid tries per row — and, if they
 * fail again, sends the new alert that says the treats stay locked until »Regenerate«.
 * A row that cannot be retried automatically (timed out, failed the quality check) is given
 * up again by that pass at once, with the same alert. Durable items are not touched.
 *
 * Idempotent (only given-up failed treat rows match); no personal data.
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
        return DB::table('item_media')
            ->where('item_key', 'treats')
            ->where('status', 'failed')
            ->whereNotNull('exhausted_at')
            ->update(['exhausted_at' => null, 'alerted_at' => null, 'auto_retries' => 0, 'updated_at' => now()]);
    }
};
