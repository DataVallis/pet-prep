<?php

namespace App\Http\Controllers;

use App\Http\Requests\FalAiWebhookRequest;
use App\Models\AiSpendLedger;
use App\Models\MediaLabResult;
use App\Models\PetMedia;
use App\Services\FalAiService;
use App\Services\Media\MediaLabService;
use App\Services\Media\PetMediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FalAiWebhookController extends Controller
{
    public function __construct(
        private readonly FalAiService $falAiService,
        private readonly MediaLabService $lab,
        private readonly PetMediaService $media,
    ) {}

    /**
     * Handle a fal.ai webhook (state video finished or failed).
     *
     * 1. The ED25519 signature is verified in FalAiWebhookRequest::authorize() — fail closed.
     * 2. Match the request_id to a pet_media slot we submitted (M4-03); the pet
     *    comes from that row, never from client-supplied parameters. Otherwise an
     *    AI Lab video (media_lab_results, admin only — M4-02) with that request_id.
     * 3. Idempotent: a slot is finalised once; repeats are acknowledged.
     * 4. On success the allowlisted fal URL is only recorded; StorePetMedia
     *    downloads it (M4-05) and broadcasts once the file is ours.
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
            /** @var PetMedia|null $slot */
            $slot = PetMedia::where('request_id', $requestId)->lockForUpdate()->first();

            if ($slot !== null) {
                return match ($this->media->recordVideoResult($slot, $result)) {
                    'already_processed' => response()->json(['message' => 'Already processed.'], 200),
                    'failed' => response()->json(['message' => 'Failure recorded.'], 200),
                    default => response()->json(['message' => 'Video recorded; storing.', 'pet_id' => $slot->pet_id], 200),
                };
            }

            // AI Lab video (admin only, M4-02): same signed webhook, own table.
            $labResult = MediaLabResult::where('request_id', $requestId)->lockForUpdate()->first();

            if ($labResult) {
                if ($labResult->isFinished()) {
                    return response()->json(['message' => 'Already processed.'], 200);
                }

                $this->lab->completeVideo($labResult, $result['video_url'], $result['error']);

                return response()->json(['message' => 'Lab result recorded.'], 200);
            }

            // A request we submitted but whose slot was regenerated since (the ledger
            // keeps every request id): acknowledge so fal stops redelivering. Not while
            // the slot is still waiting for its own request id (fal answered before our
            // submit stored it) — then 404 so fal delivers again.
            $ledger = AiSpendLedger::where('request_id', $requestId)->first();

            if ($ledger !== null) {
                $owner = $ledger->pet_media_id !== null ? PetMedia::find($ledger->pet_media_id) : null;

                if ($owner === null || $owner->request_id !== null || $owner->status !== PetMedia::STATUS_RUNNING) {
                    return response()->json(['message' => 'Superseded.'], 200);
                }
            }

            // Can happen if fal.ai answers before our submit call stored the row;
            // answer non-2xx so a retrying sender can deliver again later.
            Log::warning('FalAiWebhookController: unknown request_id', ['request_id' => $requestId]);

            return response()->json(['message' => 'Unknown request.'], 404);
        });
    }
}
