<?php

namespace App\Console\Commands;

use App\Models\Pet;
use App\Models\PetLook;
use App\Services\Media\FrugalClipPreview;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Frugal clips (David 2026-10-30): a ZERO-COST preview of savings B and C from clips that
 * are already stored — no fal call, no AI budget (FrugalClipPreview). Prints time-limited
 * signed URLs for the superadmin who runs it; the previews are never shown to children.
 */
class FrugalClipPreviewCommand extends Command
{
    protected $signature = 'media:frugal-preview
        {--look= : A shared look (all its stages)}
        {--pet= : A pet (the files it shows — its own or its look\'s)}
        {--minutes=120 : How long the printed URLs work}
        {--prune : Only delete previews older than media.state_media.frugal_preview_keep_hours (scheduled daily)}';

    protected $description = 'Preview the frugal clips (reversed idle_to_sleeping, 3 s ping-pong loops) from stored clips — 0 $, no fal call';

    public function handle(FrugalClipPreview $preview): int
    {
        if ($this->option('prune')) {
            $deleted = $preview->prune();
            $this->info("{$deleted} preview file(s) deleted.");

            return self::SUCCESS;
        }

        $look = $this->option('look') !== null ? (int) $this->option('look') : null;
        $pet = $this->option('pet') !== null ? (int) $this->option('pet') : null;

        if (($look === null) === ($pet === null)) {
            $this->error('Give exactly one of --look= or --pet=.');

            return self::INVALID;
        }
        if ($look !== null && ! PetLook::whereKey($look)->exists()) {
            $this->error("Look {$look} not found.");

            return self::FAILURE;
        }
        if ($pet !== null && ! Pet::whereKey($pet)->exists()) {
            $this->error("Pet {$pet} not found.");

            return self::FAILURE;
        }

        $rows = $preview->make($look, $pet, (int) $this->option('minutes'));

        if ($rows === []) {
            $this->warn('No stored idle_to_sleeping / sleeping / low_energy / sick clip of a dog to preview (frugal clips are for dogs only).');

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $label = sprintf('#%d %s%s → %s', $row['source_id'], $row['state'], $row['life_stage'] !== null ? " ({$row['life_stage']})" : '', $row['key']);
            if ($row['ok']) {
                $this->line(sprintf('%s · %.2f s', $label, (float) $row['seconds']));
                $this->line('  '.$row['url']);
            } else {
                $this->error($label.' · not made: '.$row['error']);
            }
        }
        $this->info(sprintf('URLs work for %d min and are capability links: whoever has one can open it until then — superadmin only, never post them anywhere shared. Previews are never shown to children and are deleted after %d h.', (int) $this->option('minutes'), (int) config('media.state_media.frugal_preview_keep_hours', 24)));
        Log::info('media:frugal-preview', ['look' => $look, 'pet' => $pet, 'made' => count(array_filter($rows, fn ($r) => $r['ok'])), 'failed' => count(array_filter($rows, fn ($r) => ! $r['ok']))]);

        return self::SUCCESS;
    }
}
