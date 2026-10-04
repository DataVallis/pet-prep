<?php

namespace App\Jobs;

use App\Models\MediaLabResult;
use App\Services\Media\FalGateway;
use App\Services\Media\MediaLabService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * AI Lab: ask the fal queue whether a video is done (fallback for the
 * webhook, e.g. a local dev server fal cannot reach). Free — no spend.
 * The HTTP poll runs outside the transaction; the row is then locked and
 * finished idempotently (same path as the webhook).
 */
class PollMediaLabResult implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $resultId) {}

    public function handle(FalGateway $gateway, MediaLabService $lab): void
    {
        $result = MediaLabResult::find($this->resultId);

        if (! $result || $result->isFinished() || ! $result->status_url || ! $result->response_url) {
            return;
        }

        $poll = $gateway->poll($result->status_url, $result->response_url);

        if ($poll['state'] === 'pending') {
            return;
        }

        DB::transaction(function () use ($lab, $poll) {
            $locked = MediaLabResult::whereKey($this->resultId)->lockForUpdate()->first();

            if (! $locked) {
                return;
            }

            $url = $poll['body']['video']['url'] ?? null;
            $lab->completeVideo($locked, is_string($url) ? $url : null, $poll['error']);
        });
    }
}
