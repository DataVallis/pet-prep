<?php

namespace App\Http\Controllers;

use App\Events\PetUpdated;
use App\Http\Requests\FalAiWebhookRequest;
use App\Models\Pet;
use App\Services\FalAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class FalAiWebhookController extends Controller
{
    public function __construct(
        private readonly FalAiService $falAiService,
    ) {}

    /**
     * Handle incoming fal.ai webhook callbacks.
     *
     * When a Kling 3.0 video generation job completes, fal.ai calls
     * this endpoint with the result. We:
     * 1. Validate the webhook secret header
     * 2. Extract the video URL from the payload
     * 3. Update the pet's current_video_url
     * 4. Broadcast a PetUpdated event via Laravel Reverb to the
     *    parent dashboard and child UI in real-time
     *
     * POST /api/webhooks/fal-ai
     */
    public function handle(FalAiWebhookRequest $request): JsonResponse
    {
        // Validate webhook secret if configured
        $webhookSecret = config('services.fal_ai.webhook_secret');
        if (filled($webhookSecret)) {
            $providedSecret = $request->query('secret') ?? $request->header('X-Fal-Webhook-Secret');

            if (! hash_equals($webhookSecret, (string) $providedSecret)) {
                Log::warning('FalAiWebhookController: Invalid webhook secret', [
                    'request_id' => $request->input('request_id'),
                ]);

                return response()->json(['message' => 'Unauthorized'], 401);
            }
        }

        $payload = $request->all();
        $result = $this->falAiService->processWebhookPayload($payload);

        $videoUrl = $result['video_url'];
        $petId = $request->query('pet_id') ?? $request->input('pet_id');

        if (! $videoUrl || ! $petId) {
            Log::info('FalAiWebhookController: Webhook received but no video URL or pet ID', [
                'request_id' => $result['request_id'],
                'pet_id' => $petId,
            ]);

            return response()->json(['message' => 'Webhook acknowledged (no action needed).'], 200);
        }

        $pet = Pet::find($petId);

        if (! $pet) {
            Log::warning('FalAiWebhookController: Pet not found for webhook', [
                'pet_id' => $petId,
                'request_id' => $result['request_id'],
            ]);

            return response()->json(['message' => 'Pet not found.'], 404);
        }

        // Update the pet's current video URL
        $pet->update([
            'current_video_url' => $videoUrl,
        ]);

        // Broadcast real-time update to parent dashboard and child UI
        broadcast(new PetUpdated($pet->fresh(), 'video_ready'));

        Log::info('FalAiWebhookController: Video URL updated and broadcast', [
            'pet_id' => $pet->id,
            'request_id' => $result['request_id'],
        ]);

        return response()->json([
            'message' => 'Video URL updated successfully.',
            'pet_id' => $pet->id,
            'current_video_url' => $videoUrl,
        ], 200);
    }
}
