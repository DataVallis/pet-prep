<?php

namespace App\Services;

use App\Enums\ChallengeStatus;
use App\Enums\PetPlan;
use App\Jobs\SendUnassignedCreditAlert;
use App\Models\ChallengeCredit;
use App\Models\ChallengePurchaseIntent;
use App\Models\Pet;
use App\Services\Alerts\DiscordWebhook;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * M6-U01 safety net (FREE_PET_UPGRADE_SPEC §5.3, David 2026-11-10 — »a parent
 * must never pay and stay with a locked pet«): a challenge credit that is still
 * unassigned AFTER_MINUTES after the webhook wrote it, in a family that has a
 * pet waiting for payment (`payment_required`) or chose a pet before buying
 * (a purchase intent), gets ONE Discord `unassigned_challenge_credit` alert —
 * claimed per credit on `challenge_credits.unassigned_alerted_at` (conditional
 * UPDATE; released again when the post fails, so the next run retries). Only
 * credits written in the last LOOKBACK_DAYS (a credit a family holds for a
 * later pet — P11 — is not alerted forever). Ids and counts only, no names.
 * Run by `billing:unassigned-credits-check` every 5 minutes.
 */
class UnassignedCreditMonitor
{
    public const AFTER_MINUTES = 10;

    public const LOOKBACK_DAYS = 7;

    /** An intent this long before the purchase still counts as "the family chose a pet". */
    public const INTENT_WINDOW_MINUTES = 60;

    public function __construct(private readonly DiscordWebhook $discord) {}

    /**
     * @return list<int> ids of the credits claimed for one alert (empty: nothing to say)
     */
    public function check(?CarbonInterface $now = null): array
    {
        if (! $this->discord->configured()) {
            return [];
        }
        $now = CarbonImmutable::instance($now ?? now());

        $candidates = ChallengeCredit::available()
            ->whereNull('unassigned_alerted_at')
            ->where('created_at', '<=', $now->subMinutes(self::AFTER_MINUTES))
            ->where('created_at', '>=', $now->subDays(self::LOOKBACK_DAYS))
            ->orderBy('id')
            ->get();

        $due = $candidates->filter(fn (ChallengeCredit $c): bool => $this->familyWaits($c))->values();
        if ($due->isEmpty()) {
            return [];
        }

        // Claim (another run may race): only rows still unclaimed and unassigned.
        $claimed = [];
        foreach ($due as $credit) {
            $won = DB::table('challenge_credits')
                ->where('id', $credit->id)
                ->whereNull('unassigned_alerted_at')
                ->whereNull('assigned_at')
                ->whereNull('revoked_at')
                ->update(['unassigned_alerted_at' => $now->utc()]);
            if ($won === 1) {
                $claimed[] = $credit;
            }
        }
        if ($claimed === []) {
            return [];
        }

        $ids = array_map(fn (ChallengeCredit $c): int => $c->id, $claimed);
        SendUnassignedCreditAlert::dispatch(
            $ids,
            array_values(array_unique(array_map(fn (ChallengeCredit $c): int => $c->family_id, $claimed))),
            array_values(array_unique(array_map(fn (ChallengeCredit $c): string => $c->environment ?? 'unknown', $claimed))),
        );

        return $ids;
    }

    private function familyWaits(ChallengeCredit $credit): bool
    {
        $intent = ChallengePurchaseIntent::where('family_id', $credit->family_id)
            ->where('created_at', '>=', CarbonImmutable::instance($credit->purchased_at)->subMinutes(self::INTENT_WINDOW_MINUTES))
            ->exists();
        if ($intent) {
            return true;
        }

        return Pet::where('family_id', $credit->family_id)
            ->where('plan', PetPlan::Challenge->value)
            ->whereNull('challenge_paid_at')
            ->where('is_active', true)
            ->where('is_game_over', false)
            ->get()
            ->contains(fn (Pet $p): bool => $p->challengeStatus() === ChallengeStatus::PaymentRequired);
    }

    /** A failed post: the next run tries again. */
    public static function release(array $creditIds): void
    {
        DB::table('challenge_credits')->whereIn('id', $creditIds)->update(['unassigned_alerted_at' => null]);
    }
}
