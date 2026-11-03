<?php

namespace App\Console\Commands;

use App\Models\ItemMedia;
use App\Services\Media\ItemMediaService;
use Illuminate\Console\Command;

/**
 * M6-W03 (every 10 min, scheduled — never a manual step): the »Želje« item content —
 * catalogue pictures of every design (made once), lost fal requests polled, failed rows healed
 * (retries, prompt variants, then the item is shown without that content + one Discord alert),
 * and every active pet that owns items: its content queued for its current picture (the
 * backfill of items bought before M6-W03 included) and the family told once it is there.
 * `--dry-run` lists the rows that are not made yet and what healing would do; changes nothing.
 */
class ItemMediaCommand extends Command
{
    protected $signature = 'media:item-media {--dry-run : List only, change nothing}';

    protected $description = 'AI content of bought items: catalogue pictures, pet-with-item pictures / clips, self-healing';

    public function handle(ItemMediaService $items): int
    {
        if ((bool) $this->option('dry-run')) {
            $rows = ItemMedia::query()->where(fn ($q) => $q->where('status', '!=', ItemMedia::STATUS_READY)->orWhereNull('storage_path'))
                ->orderBy('id')->limit(500)->get();
            if ($rows->isNotEmpty()) {
                $this->table(['id', 'scope', 'owner', 'design', 'kind', 'stage', 'status', 'reason', 'auto retries', 'variant', 'exhausted'], $rows->map(fn (ItemMedia $r) => [
                    $r->id, $r->scope, $r->pet_look_id !== null ? 'look '.$r->pet_look_id : ($r->pet_id !== null ? 'pet '.$r->pet_id : '—'),
                    $r->design, $r->kind, $r->life_stage ?? '—', $r->status, $r->error_reason ?? '—', $r->auto_retries, $r->prompt_variant,
                    $r->exhausted_at?->toIso8601String() ?? '—',
                ])->all());
            }
            $heal = $items->heal(dryRun: true);
            $this->info("[dry run] {$rows->count()} row(s) not made; would retry {$heal['retried']}, give up {$heal['exhausted']}, {$heal['waiting']} waiting for the AI budget.");

            return self::SUCCESS;
        }

        $r = $items->run();
        $this->info("Catalogue pictures queued {$r['catalogue']}; polled {$r['polled']}, timed out {$r['timed_out']}; retried {$r['retried']}, given up {$r['exhausted']}; "
            ."{$r['pets']} pet(s) with items, {$r['dispatched']} job(s) queued, {$r['settled']} item(s) newly ready.");

        return self::SUCCESS;
    }
}
