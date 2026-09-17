<?php

namespace App\Http\Controllers;

use App\Enums\BreedType;
use App\Events\PetUpdated;
use App\Http\Requests\RevenueCatWebhookRequest;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class RevenueCatWebhookController extends Controller
{
    /**
     * POST /api/webhooks/revenuecat
     * Handle RevenueCat webhook events for successful purchases.
     *
     * Validates the payload using the RevenueCatWebhookRequest Form Request,
     * verifies the Authorization header against the configured secret,
     * updates users.revenuecat_id, unlocks the premium breed
     * (Border Collie) in the pets table, and broadcasts the
     * update via Laravel Reverb.
     */
    public function handle(RevenueCatWebhookRequest $request): JsonResponse
    {
        // Verify the Authorization header against the configured secret
        $webhookSecret = config('services.revenuecat.secret_key');
        if (filled($webhookSecret)) {
            $providedAuth = $request->header('Authorization');
            $expectedAuth = 'Bearer ' . $webhookSecret;

            if (! hash_equals($expectedAuth, (string) $providedAuth)) {
                Log::warning('RevenueCatWebhook: Invalid Authorization header');

                return response()->json(['message' => 'Unauthorized'], 401);
            }
        }

        $event = $request->input('event');
        $eventType = $event['type'] ?? null;

        // Only handle non-subscription / one-time purchase events
        // For the MVP, we handle NON_RENEWING_PURCHASE / INITIAL_PURCHASE events
        if (! in_array($eventType, ['NON_RENEWING_PURCHASE', 'INITIAL_PURCHASE', 'PURCHASE'])) {
            Log::info('RevenueCatWebhook: Ignoring event type', ['type' => $eventType]);

            return response()->json(['message' => 'Event ignored.'], 200);
        }

        // Extract the app user ID (maps to our user)
        $appUserId = $event['app_user_id'] ?? null;
        if (! $appUserId) {
            Log::warning('RevenueCatWebhook: No app_user_id in event');

            return response()->json(['message' => 'Missing app_user_id.'], 422);
        }

        $user = User::where('id', $appUserId)->orWhere('email', $appUserId)->first();
        if (! $user) {
            Log::warning('RevenueCatWebhook: User not found', ['app_user_id' => $appUserId]);

            return response()->json(['message' => 'User not found.'], 404);
        }

        // Extract the RevenueCat customer ID
        $revenuecatId = $event['subscriber_id'] ?? $event['original_app_user_id'] ?? null;

        // Update the user's revenuecat_id
        $user->update([
            'revenuecat_id' => $revenuecatId,
        ]);

        // Check for the product ID to determine which breed was purchased
        $productId = $event['product_id'] ?? '';
        $store = $event['store'] ?? '';

        // Border Collie product ID (configurable via env)
        $borderCollieProductId = config('services.revenuecat.border_collie_product_id', 'border_collie_unlock');

        if ($productId === $borderCollieProductId) {
            // Unlock the premium breed: update the child's pet to Border Collie
            $pet = $this->getUserPet($user);

            if ($pet) {
                $pet->update([
                    'breed_type' => BreedType::BorderCollie->value,
                ]);

                // Broadcast the breed change to parent and child
                broadcast(new PetUpdated($pet->fresh(), 'breed_unlocked'));

                Log::info('RevenueCatWebhook: Border Collie unlocked', [
                    'user_id' => $user->id,
                    'pet_id' => $pet->id,
                    'product_id' => $productId,
                ]);

                return response()->json([
                    'message' => 'Border Collie breed unlocked successfully.',
                    'pet_id' => $pet->id,
                    'breed_type' => BreedType::BorderCollie->value,
                ], 200);
            }
        }

        Log::info('RevenueCatWebhook: Purchase recorded, no breed change', [
            'user_id' => $user->id,
            'product_id' => $productId,
        ]);

        return response()->json([
            'message' => 'Purchase recorded.',
            'revenuecat_id' => $revenuecatId,
        ], 200);
    }

    /**
     * Get the pet for a user. If the user is a parent, get the child's pet.
     * If the user is a child, get their pet directly.
     */
    private function getUserPet(User $user): ?Pet
    {
        if ($user->isChild()) {
            return $user->activePet();
        }

        if ($user->isParent()) {
            $child = $user->children()->first();

            return $child?->activePet();
        }

        return null;
    }
}
