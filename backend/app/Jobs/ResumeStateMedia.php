<?php

namespace App\Jobs;

use App\Services\Media\PetMediaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * M5-T08b (QA M1): the superadmin turned state media back ON — re-run the media
 * pipeline of every pet that uses it, so key images, loops and transitions left
 * `pending` while the switch was off are generated (PetMediaService::resumeStateMedia).
 * The slot claims and the unique per-slot jobs make it safe to run twice; the
 * hourly media:sweep is the safety net (stale pending slots only).
 */
class ResumeStateMedia implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 85;

    public int $uniqueFor = 600;

    public function handle(PetMediaService $media): void
    {
        $media->resumeStateMedia();
    }
}
