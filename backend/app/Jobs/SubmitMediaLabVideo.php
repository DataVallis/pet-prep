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
 * AI Lab: submit one image-to-video request to the fal queue. The signed
 * webhook (FalAiWebhookController) or PollMediaLabResult finishes it.
 */
class SubmitMediaLabVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $resultId) {}

    public function handle(FalGateway $gateway, MediaProfiles $profiles, FalAiService $fal): void
    {
        $claimed = MediaLabResult::whereKey($this->resultId)
            ->where('status', MediaLabResult::STATUS_QUEUED)
            ->update(['status' => MediaLabResult::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $result = MediaLabResult::findOrFail($this->resultId);

        try {
            $submitted = $gateway->submit(
                $profiles->video($result->profile),
                (array) $result->params,
                AiSpendPurpose::Lab,
                $fal->webhookUrl(),
                labResultId: $result->id,
            );
        } catch (AiCallException $e) {
            $this->markFailed($result, $e->reason, $e->getMessage());

            return;
        } catch (Throwable $e) {
            $this->markFailed($result, AiCallFailure::HttpError, $e->getMessage());

            return;
        }

        $result->update([
            'request_id' => $submitted['request_id'],
            'status_url' => $submitted['status_url'],
            'response_url' => $submitted['response_url'],
            'estimated_cost_usd' => $submitted['cost_usd'],
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
