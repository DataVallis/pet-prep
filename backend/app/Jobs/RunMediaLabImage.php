<?php

namespace App\Jobs;

use App\Enums\AiCallFailure;
use App\Enums\AiSpendPurpose;
use App\Models\MediaLabResult;
use App\Services\FalAiService;
use App\Services\Media\AiCallException;
use App\Services\Media\FalGateway;
use App\Services\Media\MediaProfiles;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * AI Lab: one image from one profile (synchronous fal call on the queue).
 * No retries — a lab call costs money and the admin can simply run again.
 */
class RunMediaLabImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** Below the queue connection's retry_after (90 s). */
    public int $timeout = 85;

    public function __construct(public readonly int $resultId) {}

    public function handle(FalGateway $gateway, MediaProfiles $profiles, FalAiService $fal): void
    {
        // Claim the row (queued → running) so a duplicate delivery cannot call fal twice.
        $claimed = MediaLabResult::whereKey($this->resultId)
            ->where('status', MediaLabResult::STATUS_QUEUED)
            ->update(['status' => MediaLabResult::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $result = MediaLabResult::findOrFail($this->resultId);

        try {
            $profile = $profiles->image($result->profile);
            $call = $gateway->run($profile, (array) $result->params, AiSpendPurpose::Lab, labResultId: $result->id, timeoutSeconds: 75);
        } catch (AiCallException $e) {
            $this->markFailed($result, $e->reason, $e->getMessage());

            return;
        } catch (Throwable $e) {
            $this->markFailed($result, AiCallFailure::HttpError, $e->getMessage());

            return;
        }

        $url = $call['body']['images'][0]['url'] ?? null;

        if (! is_string($url) || ! $fal->isAllowedMediaUrl($url)) {
            $result->update([
                'status' => MediaLabResult::STATUS_FAILED,
                'error_reason' => AiCallFailure::InvalidResponse->value,
                'error' => 'No usable image URL in the response.',
                'latency_ms' => $call['latency_ms'],
                'estimated_cost_usd' => $call['cost_usd'],
                'completed_at' => now(),
            ]);

            return;
        }

        $result->update([
            'status' => MediaLabResult::STATUS_COMPLETED,
            'result_url' => $url,
            'latency_ms' => $call['latency_ms'],
            'estimated_cost_usd' => $call['cost_usd'],
            'completed_at' => now(),
        ]);
    }

    private function markFailed(MediaLabResult $result, AiCallFailure $reason, string $message): void
    {
        $result->update([
            'status' => MediaLabResult::STATUS_FAILED,
            'error_reason' => $reason->value,
            'error' => mb_substr($message, 0, 2000),
            'completed_at' => now(),
        ]);
    }
}
