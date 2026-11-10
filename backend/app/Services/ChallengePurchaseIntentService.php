<?php

namespace App\Services;

use App\Exceptions\ChallengeException;
use App\Models\ChallengePurchaseIntent;
use App\Models\Family;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * M6-U01 purchase intent (FREE_PET_UPGRADE_SPEC §5.3, David »da« 2026-11-10):
 * `POST /api/parent/pets/{pet}/challenge/intent` — the new parent app says,
 * BEFORE it opens the store sheet, which pet the 12-week challenge purchase is
 * for. The RevenueCat webhook then assigns the purchase's credit to that pet
 * (ChallengeCreditService::create — a free pet is upgraded with the activation
 * code path). Without an intent (every older app) the webhook works exactly as
 * before.
 *
 * Per parent (= the purchase's RevenueCat `app_user_id`): a new intent
 * supersedes the parent's earlier open ones; single use (consumed by the
 * webhook or by the activation of that pet); valid TTL_MINUTES at the purchase.
 * Same eligibility as activation: an active unpaid challenge pet (also unborn)
 * or an upgradeable free pet. Lock order family → pet (like activate()).
 */
class ChallengePurchaseIntentService
{
    public const TTL_MINUTES = 30;

    /**
     * @throws ChallengeException already_paid / pet_not_active / game_over / pet_not_born / legacy_profile / free_plan (422)
     */
    public function create(User $parent, Pet $pet): ChallengePurchaseIntent
    {
        return DB::transaction(function () use ($parent, $pet): ChallengePurchaseIntent {
            Family::whereKey($pet->family_id)->lockForUpdate()->first();
            $locked = Pet::whereKey($pet->id)->lockForUpdate()->firstOrFail();

            if ($locked->isFreePlan()) {
                ChallengeCreditService::assertUpgradeable($locked);
            } elseif ($locked->challenge_paid_at !== null) {
                throw new ChallengeException('already_paid', 'This '.$locked->speciesValue()->value.'\'s challenge is already unlocked.');
            } elseif (! ChallengeCreditService::intentTargetAllowed($locked)) {
                throw new ChallengeException('pet_not_active', 'This '.$locked->speciesValue()->value.' is no longer in play.');
            }

            $now = now();
            ChallengePurchaseIntent::where('parent_user_id', $parent->id)->open()->update([
                'consumed_at' => $now,
                'consumed_by' => ChallengePurchaseIntent::CONSUMED_BY_SUPERSEDED,
                'updated_at' => $now,
            ]);

            return ChallengePurchaseIntent::create([
                'family_id' => $locked->family_id,
                'parent_user_id' => $parent->id,
                'pet_id' => $locked->id,
                'expires_at' => $now->copy()->addMinutes(self::TTL_MINUTES),
            ]);
        });
    }
}
