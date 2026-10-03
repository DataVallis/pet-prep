<?php

namespace App\Http\Controllers;

use App\Events\PetUpdated;
use App\Http\Requests\FalAiWebhookRequest;
use App\Models\PetMediaJob;
use App\Services\FalAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FalAiWebhookController extends Controller
{
    public function __construct(
        private readonly FalAiService $falAiService,
    ) {}

    /**
     * Handle a fal.ai webhook (video generation finished or failed).
     *
     * 1. The ED25519 signature is verified in FalAiWebhookRequest::authorize() — fail closed.
     * 2. Match the request_id to a job we created (pet_media_jobs); the pet comes
     *    from that job, never from client-supplied parameters.
     * 3. Idempotent: a job is finalised once; repeats are acknowledged.
     * 4. On success, set the pet's current video and broadcast once.
     *
     * POST /api/webhooks/fal-ai
     */
    public function handle(FalAiWebhookRequest $request): JsonResponse
    {
        $requestId = (string) $request->input('request_id');

        // The signed header and the body must describe the same request.
        if (! hash_equals((string) $request->header('X-Fal-Webhook-Request-Id'), $requestId)) {
            Log::warning('FalAiWebhookController: request_id header/body mismatch', ['request_id' => $requestId]);

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $result = $this->falAiService->parseWebhookResult($request->all());

        return DB::transaction(function () use ($requestId, $result) {
            /** @var PetMediaJob|null $job */
            $job = PetMediaJob::where('request_id', $requestId)->lockForUpdate()->first();

            if (! $job) {
                // Can happen if fal.ai answers before our submit call stored the row;
                // answer non-2xx so a retrying sender can deliver again later.
                Log::warning('FalAiWebhookController: unknown request_id', ['request_id' => $requestId]);

                return response()->json(['message' => 'Unknown request.'], 404);
            }

            if (! $job->isPending()) {
                return response()->json(['message' => 'Already processed.'], 200);
            }

            if (! $result['ok']) {
                $job->update([
                    'status' => PetMediaJob::STATUS_FAILED,
                    'error' => $result['error'],
                    'completed_at' => now(),
                ]);

                Log::warning('FalAiWebhookController: generation failed', [
                    'request_id' => $requestId,
                    'pet_id' => $job->pet_id,
                    'error' => $result['error'],
                ]);

                return response()->json(['message' => 'Failure recorded.'], 200);
            }

            $job->update([
                'status' => PetMediaJob::STATUS_COMPLETED,
                'result_url' => $result['video_url'],
                'completed_at' => now(),
            ]);

            $pet = $job->pet;
            // Quiet update + one explicit broadcast (avoids the observer's duplicate event).
            $pet->updateQuietly(['current_video_url' => $result['video_url']]);

            DB::afterCommit(fn () => broadcast(new PetUpdated($pet->fresh(), 'video_ready')));

            return response()->json([
                'message' => 'Video URL updated successfully.',
                'pet_id' => $pet->id,
            ], 200);
        });
    }
}
