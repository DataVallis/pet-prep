/**
 * Child-facing texts for care actions (M1-14). Short, kind; `child:actions` (M1-18).
 * The server decides (422 reason + `next_allowed_at`, 423 lock); this only words it,
 * with times in the family's timezone (`state.timezone`).
 */

import { ApiError } from '@/api/client';
import { stateFromErrorBody } from '@/modules/contract/signContract';
import type { ChildPetState } from '@/api/client';
import type { CareAction, ChildPetView, LockReason } from '@/modules/childPet/childPetView';
import { familyClock, isLaterDay, whenText } from '@/modules/childPet/familyTime';
import { BEHAVIOUR_STRINGS, onlyChewingOpen } from '@/modules/behaviour/behaviour';
import { t } from '@/i18n';
import { strings } from '@/i18n/strings';

/** All child texts of care actions (`child:actions`); behaviour texts come from `behaviour:child`. */
export const CHILD_ACTION_STRINGS = strings('child', 'actions', {
  success: {
    get take_out(): string {
      return BEHAVIOUR_STRINGS.success.take_out;
    },
    get resolve_chewing(): string {
      return BEHAVIOUR_STRINGS.success.resolve_chewing;
    },
  },
  unchanged: {
    get take_out(): string {
      return BEHAVIOUR_STRINGS.unchanged.take_out;
    },
    get resolve_chewing(): string {
      return BEHAVIOUR_STRINGS.unchanged.resolve_chewing;
    },
  },
  refused: {
    outsideFeedWindow: (when: string) => t('child:actions.refused.outsideFeedWindow', { when }),
    alreadyFed: (when: string) => t('child:actions.refused.alreadyFed', { when }),
    waterTooSoon: (when: string) => t('child:actions.refused.waterTooSoon', { when }),
    get takeOutNotNeeded(): string {
      return BEHAVIOUR_STRINGS.takeOutNotNeeded;
    },
  },
  locked: {
    ill: (until: string) => t('child:actions.locked.ill', { until }),
  },
});

/** Every care action has a success and an unchanged text (compile-time check). */
const _careTexts: { success: Record<CareAction, string>; unchanged: Record<CareAction, string> } = CHILD_ACTION_STRINGS;
void _careTexts;

export type RefusalReason =
  | 'outside_feed_window'
  | 'already_fed_this_window'
  | 'water_daily_limit'
  | 'water_too_soon'
  | 'needs_cleaning'
  | 'take_out_not_needed';

export type ActionFailure =
  | { kind: 'refused'; reason: string | null; nextAllowedAt: string | null; state: ChildPetState | null }
  | { kind: 'locked'; reason: LockReason | null; lockedUntil: string | null; state: ChildPetState | null }
  | { kind: 'offline' }
  | { kind: 'throttled' }
  | { kind: 'failed'; status: number };

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

function str(value: unknown): string | null {
  return typeof value === 'string' && value.length > 0 ? value : null;
}

const LOCK_REASONS: readonly LockReason[] = ['game_over', 'inactive', 'hard_stopped', 'contract_required', 'ill'];

/** Classify a thrown error of a child action. Anything not from the API = no connection. */
export function classifyActionError(error: unknown): ActionFailure {
  if (!(error instanceof ApiError)) return { kind: 'offline' };
  const body = isRecord(error.data) ? error.data : {};
  const state = stateFromErrorBody(error.data);
  switch (error.status) {
    case 422:
      return { kind: 'refused', reason: str(body.reason), nextAllowedAt: str(body.next_allowed_at), state };
    case 423: {
      const reason = str(body.reason);
      return {
        kind: 'locked',
        reason: reason !== null && (LOCK_REASONS as readonly string[]).includes(reason) ? (reason as LockReason) : null,
        lockedUntil: str(body.locked_until),
        state,
      };
    }
    case 429:
      return { kind: 'throttled' };
    default:
      return { kind: 'failed', status: error.status };
  }
}

/** Text for a 423 lock (null for `contract_required` — that one routes to the contract). */
export function lockMessage(reason: LockReason | null, until: string | null, timeZone: string | null): string | null {
  switch (reason) {
    case 'hard_stopped':
      return CHILD_ACTION_STRINGS.locked.hard_stopped;
    case 'ill': {
      const clock = familyClock(until, timeZone);
      return clock ? CHILD_ACTION_STRINGS.locked.ill(clock) : CHILD_ACTION_STRINGS.locked.illNoTime;
    }
    case 'game_over':
      return CHILD_ACTION_STRINGS.locked.game_over;
    case 'inactive':
      return CHILD_ACTION_STRINGS.locked.inactive;
    case 'payment_required':
      return CHILD_ACTION_STRINGS.locked.payment_required;
    default:
      return null;
  }
}

/** Text for a 422 game-rule refusal; `view` is the state after the refusal (for tz / fallback times). */
export function refusalMessage(reason: string | null, nextAllowedAt: string | null, view: ChildPetView | null): string {
  const tz = view?.timezone ?? null;
  const now = view?.server_time ?? null;
  const s = CHILD_ACTION_STRINGS.refused;
  switch (reason) {
    case 'outside_feed_window': {
      const when = whenText(nextAllowedAt ?? view?.feeding.next_feed_window?.start ?? null, now, tz);
      return when ? s.outsideFeedWindow(when) : s.outsideFeedWindowNoTime;
    }
    case 'already_fed_this_window': {
      const when = whenText(nextAllowedAt ?? view?.feeding.next_feed_window?.start ?? null, now, tz);
      return when ? s.alreadyFed(when) : s.alreadyFedNoTime;
    }
    case 'water_daily_limit':
      return s.waterDailyLimit;
    case 'water_too_soon': {
      const next = nextAllowedAt ?? view?.water.next_allowed_at ?? null;
      if (isLaterDay(next, now, tz)) return s.waterDailyLimit;
      const when = whenText(next, now, tz);
      return when ? s.waterTooSoon(when) : s.waterTooSoonNoTime;
    }
    case 'needs_cleaning':
      return s.needsCleaning;
    case 'take_out_not_needed':
      return s.takeOutNotNeeded;
    default:
      return s.other;
  }
}

/** One message for any failed care action (null = nothing to say, e.g. contract routing). */
export function failureMessage(failure: ActionFailure, view: ChildPetView | null): string | null {
  switch (failure.kind) {
    case 'refused':
      return refusalMessage(failure.reason, failure.nextAllowedAt, view);
    case 'locked':
      return lockMessage(failure.reason, failure.lockedUntil, view?.timezone ?? null);
    case 'offline':
      return CHILD_ACTION_STRINGS.offline;
    case 'throttled':
      return CHILD_ACTION_STRINGS.tooFast;
    case 'failed':
      return CHILD_ACTION_STRINGS.failed;
  }
}

/** Message after a 200 (`accepted` or `unchanged`). */
export function successMessage(action: CareAction, status: 'accepted' | 'unchanged'): string {
  return status === 'accepted' ? CHILD_ACTION_STRINGS.success[action] : CHILD_ACTION_STRINGS.unchanged[action];
}

/** Short hint under a disabled HUD button ("ob 17:00", "Najprej pospravi"); null when enabled. */
export const HUD_HINTS = strings('child', 'hints', {
  get cleanChewing(): string {
    return BEHAVIOUR_STRINGS.cleanChewing;
  },
});

/** Hint under "Očisti": "Čisto", or "Pospravi copat" when only a chewed slipper is open (M5-R02). */
export function cleanHint(view: ChildPetView): string | null {
  if (view.lock.is_locked) return null;
  if (view.pet.needs_cleaning && onlyChewingOpen(view.behaviour)) return HUD_HINTS.cleanChewing;
  if (view.pet.hygiene_level >= 100 && !view.pet.needs_cleaning) return HUD_HINTS.clean;
  return null;
}

export function feedHint(view: ChildPetView): string | null {
  if (view.feeding.can_feed || view.lock.is_locked) return null;
  if (view.pet.needs_cleaning) return HUD_HINTS.cleanFirst;
  const next = view.feeding.next_feed_window?.start ?? null;
  // The current, unused window can still be closed for the moment (e.g. a lock just ended).
  return whenText(next, view.server_time, view.timezone);
}

export function waterHint(view: ChildPetView): string | null {
  if (view.water.can_water || view.lock.is_locked) return null;
  if (view.pet.needs_cleaning) return HUD_HINTS.cleanFirst;
  const next = view.water.next_allowed_at;
  if (!next || isLaterDay(next, view.server_time, view.timezone)) return HUD_HINTS.tomorrow;
  return whenText(next, view.server_time, view.timezone);
}
