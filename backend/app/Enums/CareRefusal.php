<?php

namespace App\Enums;

/**
 * Why a care action was refused although the pet is not locked (HTTP 422,
 * M1-07). The response carries `next_allowed_at` where a time is known.
 */
enum CareRefusal: string
{
    /** Feeding only inside a breed feed window (family-local, PRODUCT_SPEC §5). */
    case OutsideFeedWindow = 'outside_feed_window';

    /** One feed per window ("2× / dan" with two windows). */
    case AlreadyFedThisWindow = 'already_fed_this_window';

    /** breed water_times_per_day reached for the family-local day. */
    case WaterDailyLimit = 'water_daily_limit';

    /** Less than breed water_min_gap_minutes since the last refill. */
    case WaterTooSoon = 'water_too_soon';

    /** Hygiene shows 0 %: the mess must be cleaned first (PRODUCT_SPEC §8). */
    case NeedsCleaning = 'needs_cleaning';

    /** The responsibility contract is signed once per pet. */
    case ContractAlreadySigned = 'contract_already_signed';

    /**
     * "Pelji ven" (M5-R02) is a puppy rule: a legacy-profile pet or a dog
     * past the puppy stage has no bladder clock.
     */
    case TakeOutNotNeeded = 'take_out_not_needed';

    /**
     * Training (M5-R03) is only for a pet created by an app build that
     * declared `training` (legacy-profile pets never — they keep the pre-M5
     * rules).
     */
    case TrainingNotAvailable = 'training_not_available';

    /** Another training session of this pet is still running (next_allowed_at = its expiry). */
    case TrainingSessionActive = 'training_session_active';

    /** The dog's training minutes of the family-local day are used up (next_allowed_at = local midnight). */
    case TrainingDailyBudgetUsed = 'training_daily_budget_used';

    /**
     * This child's fair share of the dog's daily training budget is used
     * (budget / children who can train, M5-R03b; next_allowed_at = local
     * midnight). A sibling may still have time left.
     */
    case TrainingChildShareUsed = 'training_child_share_used';

    /** Unknown session, another child's session, or a session of another pet. */
    case TrainingSessionInvalid = 'training_session_invalid';

    /** The session's schedule has not run its course yet. */
    case TrainingSessionNotOver = 'training_session_not_over';

    /** Finished after the session's expiry (TTL) — no progress. */
    case TrainingSessionExpired = 'training_session_expired';

    /**
     * The session + its finish TTL would run past the family-local midnight
     * (next_allowed_at = midnight): routine and budget belong to one day.
     */
    case TrainingDayEnding = 'training_day_ending';

    /** A lock (hard stop, vet, game over) began during the session: it does not count, its time is refunded. */
    case TrainingSessionInterrupted = 'training_session_interrupted';

    /** A tap offset lies outside the session (impossible value). */
    case TrainingInvalidTaps = 'training_invalid_taps';

    /**
     * Play & cuddle (M5-R05) is not possible now: the pet has no play (free
     * mutt, legacy pet), it is quiet hours (next_allowed_at = their end) or a
     * mess waits to be cleaned.
     */
    case PlayNotAvailable = 'play_not_available';

    /**
     * M5-R06-04: phone steps are dog-only (CAT_SPEC Q1, §5.3) — a cat's daily
     * exercise is the wand play, so a step sync for a cat is refused.
     */
    case StepsNotApplicable = 'steps_not_applicable';

    /**
     * M5-R06-04: the wand game is the cat's play (CAT_SPEC Q1) — not for a
     * dog, a cat without life-stage data, or a cat whose stage has no play goal.
     */
    case WandNotAvailable = 'wand_not_available';

    /**
     * Less than `play_min_gap_minutes` (120, David 2026-10-08) since the
     * cat's last SUCCESSFUL wand session (next_allowed_at = when the gap ends).
     * Unfinished / failed sessions do not start the gap.
     */
    case WandTooSoon = 'wand_too_soon';

    /** Another child's wand session is running on this cat (next_allowed_at = its expiry). */
    case WandSessionActive = 'wand_session_active';

    /** The session + its finish TTL would run past the family-local midnight (next_allowed_at = midnight). */
    case WandDayEnding = 'wand_day_ending';

    /**
     * David 2026-10-08 ~21:5x: no wand play in quiet hours — the cat sleeps
     * (as free play, PLAY_CUDDLE_SPEC §3.1). Also when the game + its TTL
     * would run into the next quiet hours. next_allowed_at = the end of that
     * quiet stretch.
     */
    case WandQuietHours = 'wand_quiet_hours';

    /** Unknown session, another child's session, or a session of another pet / kind. */
    case WandSessionInvalid = 'wand_session_invalid';

    /** Finished before the ~60 s game could have run (start + duration − tolerance). */
    case WandSessionNotOver = 'wand_session_not_over';

    /** Finished after the session's TTL, or the session was replaced by a newer one. */
    case WandSessionExpired = 'wand_session_expired';

    /** A lock (hard stop, vet, game over, payment) began during the session: it does not count. */
    case WandSessionInterrupted = 'wand_session_interrupted';

    /** A move lies outside the session (impossible offset). */
    case WandInvalidMoves = 'wand_invalid_moves';

    // ── M5-R06-05: litter, grooming, scratching (cats) ──────────────────

    /** Litter rules apply only to a cat with life-stage data (a dog has poops, not a tray). */
    case LitterNotAvailable = 'litter_not_available';

    /** The weekly full litter change was already done in this program week (next_allowed_at = the next week). */
    case LitterChangeDone = 'litter_change_done';

    /** Another child's litter change is running (next_allowed_at = its expiry). */
    case LitterChangeSessionActive = 'litter_change_session_active';

    /** Grooming is the Maine Coon's routine (CAT_SPEC Q8) — not for other breeds / species / legacy pets. */
    case GroomingNotAvailable = 'grooming_not_available';

    /**
     * At least one day between two groomings (CAT_SPEC Q8): already groomed
     * on this family-local day (next_allowed_at = the next local midnight).
     */
    case GroomingDoneToday = 'grooming_done_today';

    /** All of this program week's groomings are done (next_allowed_at = the next week). */
    case GroomingWeekDone = 'grooming_week_done';

    /** The cat sleeps in quiet hours — no grooming (like the wand game; next_allowed_at = their end). */
    case GroomingQuietHours = 'grooming_quiet_hours';

    /** Another child is grooming the cat (next_allowed_at = its expiry). */
    case GroomingSessionActive = 'grooming_session_active';

    /** No scratching mess is open — nothing to redirect. */
    case ScratchingNotNeeded = 'scratching_not_needed';

    /** Another child is carrying the cat to the scratcher (next_allowed_at = its expiry). */
    case ScratchingSessionActive = 'scratching_session_active';

    /** Litter change / grooming / scratching finish: unknown session, another child's, or another pet / kind. */
    case CareSessionInvalid = 'care_session_invalid';

    /** Finished before the session could have run its course. */
    case CareSessionNotOver = 'care_session_not_over';

    /** Finished after the session's TTL, or the session was replaced by a newer one. */
    case CareSessionExpired = 'care_session_expired';

    /** A lock (hard stop, vet, game over, payment) began during the session: it does not count. */
    case CareSessionInterrupted = 'care_session_interrupted';

    /** A stroke / praise offset lies outside the session. */
    case CareSessionInvalidInput = 'care_session_invalid_input';
}
