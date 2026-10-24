/**
 * "Kaj je dovoljeno kdaj" — ONE rules table for every action of the child HUD (M5-T02,
 * APP_REFINEMENT_SPEC §8, David 2026-10-20: when the pet sleeps, play AND school are off;
 * 2026-10-21 (M5-T02b, »spečo žival ne motimo«): also cuddling and brushing; a disabled
 * button always shows its reason, e.g. »💤 Kuža spi do 7:00«).
 *
 * The SERVER decides; this table only reads its flags and names the reason. It never
 * re-derives a game rule (feed windows, water gap, budgets …). What it adds: the sleeping
 * reason + wake time (`sleep` of the child state) and the lock reason.
 *
 * | Action        | Server flag                      | Blocked by (reason shown)                                        |
 * |---------------|----------------------------------|------------------------------------------------------------------|
 * | feed          | `feeding.can_feed`               | lock · mess (clean first) · outside a meal window (next window) — |
 * |               |                                  | in quiet hours the parent feeds (meal windows row)               |
 * | water         | `water.can_water`                | lock · mess · bowl still full / gap (next time)                  |
 * | clean         | hygiene / messes                 | lock · nothing to clean ("Čisto") · only a chewed slipper / sofa |
 * | take_out      | `behaviour.can_take_out`         | lock (puppy only — no button otherwise)                          |
 * | walk          | not locked                       | lock (steps count any time, also while the pet sleeps)           |
 * | play / cuddle | `play.can_play`                  | lock · SLEEPING (until wake, `play_pet_sleeping`) · mess ·       |
 * |               | + `play.media_ready`             | PREPARING (`media_not_ready`, »Igra se še pripravlja«) · other   |
 * | training      | `training.can_start`             | lock · SLEEPING (until wake) · session running · budget / share  |
 * |               | + `blocked_reason`               | used · day ending                                                |
 * | grooming      | `grooming.can_start`             | lock · SLEEPING (`grooming_quiet_hours`, until wake; dog + cat)  |
 * |               |                                  | · done today / week · another game                               |
 * | wand (cat)    | `wand.can_start`                 | lock · SLEEPING (`wand_quiet_hours`) · 2 h gap · another game    |
 * |               |                                  | · PREPARING (`media_not_ready`)                                  |
 * | scoop (cat)   | `litter.can_scoop`               | lock · tray clean                                                |
 * | socialisation | `socialisation.can_record`       | lock · SLEEPING (`socialisation_quiet_hours`) · week done        |
 * | tooth_brushing| `care_tasks[].can_start`         | lock · SLEEPING (`care_task_quiet_hours`, until wake) · done     |
 * | fold_cleaning |  (M5-R14 / R15 / R19 / R20 —     |   today / this period · another game · a mess (clean first)      |
 * | ear_cleaning  |   only breeds whose source       |                                                                  |
 * | nail_trim     |   states the frequency)          |                                                                  |
 *
 * States: **sleeping** = the family's quiet hours (server `PetSleepService`; `sleep.asleep`,
 * wakes at `sleep.until`); **quiet hours** = the same moments (decay ×0.1, the parent covers
 * meals); **sick** = the vet lock (`lock.reason = ill`, until `lock.until`); **on a walk** =
 * the walk tracker is open and covers the HUD (nothing else can be pressed); **payment
 * lock** = `lock.reason = payment_required` (translucent lock screen). Every lock is shown
 * by the lock screen itself; the table still returns it (`reason: 'locked'`).
 *
 * **Preparing** (M5-T04, David 2026-10-20, APP_REFINEMENT_SPEC §10 / §13): when ANY clip a
 * game shows is not ready for the pet, the game stays visible but off with the neutral
 * »Igra se še pripravlja« — never half working, never a word about pictures or videos. The
 * server decides (`play.media_ready`, wand `blocked_reason = media_not_ready`) and turns it
 * on with the `PetUpdated` of the last clip (no restart).
 */

import { t } from '@/i18n';
import type { ChildPetView, LockReason } from '@/modules/childPet/childPetView';
import { familyClock } from '@/modules/childPet/familyTime';
import { strings } from '@/i18n/strings';
import { CARE_TASKS, type CareTaskKey } from '@/modules/careTasks/careTasks';

export type HudAction =
  | 'feed'
  | 'water'
  | 'clean'
  | 'take_out'
  | 'walk'
  | 'play'
  | 'cuddle'
  | 'training'
  | 'grooming'
  | 'wand'
  | 'scoop'
  | 'socialisation'
  | CareTaskKey;

export const HUD_ACTIONS: readonly HudAction[] = [
  'feed',
  'water',
  'clean',
  'take_out',
  'walk',
  'play',
  'cuddle',
  'training',
  'grooming',
  'wand',
  'scoop',
  'socialisation',
  ...CARE_TASKS,
];

/**
 * Why an action is off: a lock, the pet sleeps, the game's clips are not ready yet
 * (`preparing`, M5-T04 — »Igra se še pripravlja«), or another server rule (its own hint
 * explains it).
 */
export type ActionBlockReason = 'locked' | 'sleeping' | 'preparing' | 'server';

export interface ActionRule {
  /** The server would accept it now (its flag); `false` also when the pet has no such action. */
  enabled: boolean;
  /** Set when `enabled` is false and the pet has the action. */
  reason?: ActionBlockReason;
  /** When the block ends (ISO, family offset): wake time, end of the vet visit …; null = unknown. */
  until?: string | null;
  /** The lock behind `reason: 'locked'`. */
  lock?: LockReason;
  /**
   * Short text for a disabled button ("💤 Kuža spi do 7:00", "Najprej podpiši pogodbo");
   * absent for `server` — that action's own hint ("ob 17:00", "Najprej pospravi") explains it.
   */
  text?: string;
}

/** Child texts of the table (`child:rules`, a cat reads `cat:override.child.rules`). */
export const RULE_STRINGS = strings('child', 'rules', {
  sleepingUntil: (clock: string) => t('child:rules.sleepingUntil', { clock }),
});

/** Server reason: a clip of the game is not ready yet (M5-T04). */
export const MEDIA_NOT_READY = 'media_not_ready';

const OFF: ActionRule = { enabled: false };
const ON: ActionRule = { enabled: true };

/** "💤 Kuža spi do 7:00" (family clock), or "💤 Kuža spi" without a wake time. */
export function sleepingText(until: string | null, timezone: string | null): string {
  const clock = familyClock(until, timezone);
  return clock ? RULE_STRINGS.sleepingUntil(clock) : RULE_STRINGS.sleeping;
}

/** The lock's title ("Igra počaka na starša", "Kuža je pri veterinarju" …). */
export function lockText(reason: LockReason): string {
  switch (reason) {
    case 'contract_required':
      return RULE_STRINGS.contract;
    case 'hard_stopped':
      return t('child:locked.hard_stop.title');
    case 'ill':
      return t('child:locked.illness.title');
    case 'game_over':
      return t('child:locked.game_over.title');
    case 'inactive':
      return t('child:locked.inactive.title');
    case 'payment_required':
      return t('child:locked.payment_required.title');
  }
}

function locked(view: ChildPetView): ActionRule | null {
  if (!view.lock.is_locked || view.lock.reason === null) return null;
  return { enabled: false, reason: 'locked', lock: view.lock.reason, until: view.lock.until, text: lockText(view.lock.reason) };
}

function sleeping(view: ChildPetView, until: string | null = view.sleep.until): ActionRule {
  return { enabled: false, reason: 'sleeping', until, text: sleepingText(until, view.timezone) };
}

const server = (until: string | null = null): ActionRule => ({ enabled: false, reason: 'server', until });

/** »Igra se še pripravlja« — no time: the server's PetUpdated turns the game on. */
const preparing = (): ActionRule => ({ enabled: false, reason: 'preparing', until: null, text: RULE_STRINGS.preparing });

/** Server reasons that mean "the pet sleeps" (quiet hours) for the cat games and socialisation. */
const SLEEP_REASONS: ReadonlySet<string> = new Set([
  'training_pet_sleeping',
  'wand_quiet_hours',
  'grooming_quiet_hours',
  'socialisation_quiet_hours',
  'care_task_quiet_hours',
]);

/** A server block: the sleeping reason (with its time, else the wake time) or the server's own. */
function fromServer(view: ChildPetView, blockedReason: string | null, nextAllowedAt: string | null): ActionRule {
  if (blockedReason !== null && SLEEP_REASONS.has(blockedReason)) return sleeping(view, nextAllowedAt ?? view.sleep.until);
  if (blockedReason === MEDIA_NOT_READY) return preparing();
  return server(nextAllowedAt);
}

/**
 * The rule for one HUD action now. Server flags are the truth; a lock wins, then sleep for
 * play / cuddle / school / brushing, then the server's own reason.
 */
export function actionRule(view: ChildPetView, action: HudAction): ActionRule {
  const lock = locked(view);
  switch (action) {
    case 'feed':
      return lock ?? (view.feeding.can_feed ? ON : server(view.feeding.next_feed_window?.start ?? null));
    case 'water':
      return lock ?? (view.water.can_water ? ON : server(view.water.next_allowed_at));
    case 'clean': {
      if (lock) return lock;
      const nothing = view.pet.hygiene_level >= 100 && !view.pet.needs_cleaning;
      return nothing ? server() : ON;
    }
    case 'take_out':
      if (view.behaviour.take_out === null) return OFF;
      return lock ?? (view.behaviour.can_take_out ? ON : server());
    case 'walk':
      // Steps count any time (also while the pet sleeps); a cat has no walk.
      if (view.pet.species === 'cat') return OFF;
      return lock ?? ON;
    case 'play':
    case 'cuddle': {
      if (view.play === null) return OFF;
      if (lock) return lock;
      if (view.play.can_play) return ON;
      if (view.sleep.asleep) return sleeping(view);
      // A mess explains itself (»Najprej počisti …«); then the clips (M5-T04).
      if (view.pet.needs_cleaning || view.pet.hygiene_level <= 0) return server();
      return view.play.media_ready ? server() : preparing();
    }
    case 'training': {
      const tr = view.training;
      if (!tr.enabled || tr.commands.length === 0 || view.pet.species === 'cat') return OFF;
      if (lock) return lock;
      if (tr.can_start) return ON;
      // An older server sends no `blocked_reason`: the pet's sleep explains it.
      if (tr.blocked_reason === null && tr.session === null && view.sleep.asleep) return sleeping(view);
      return fromServer(view, tr.blocked_reason, tr.next_allowed_at);
    }
    case 'grooming': {
      // M5-T02b (David 2026-10-21, »spečo žival ne motimo«): no brushing (dog or cat)
      // while the pet sleeps — the server refuses it first (`grooming_quiet_hours`, wake time).
      const g = view.cat.grooming;
      if (g === null) return OFF;
      if (lock) return lock;
      if (g.can_start || g.session !== null) return ON;
      // An older server may name another block first (done today …): the sleep explains it.
      if (view.sleep.asleep) return sleeping(view, g.blocked_reason === 'grooming_quiet_hours' ? (g.next_allowed_at ?? view.sleep.until) : view.sleep.until);
      return fromServer(view, g.blocked_reason, g.next_allowed_at);
    }
    case 'wand': {
      const w = view.cat.wand;
      if (w === null) return OFF;
      return lock ?? (w.can_start || w.session !== null ? ON : fromServer(view, w.blocked_reason, w.next_allowed_at));
    }
    case 'scoop': {
      const l = view.cat.litter;
      if (l === null) return OFF;
      return lock ?? (l.can_scoop ? ON : server());
    }
    case 'socialisation': {
      const s = view.socialisation;
      if (s === null) return OFF;
      return lock ?? (s.can_record ? ON : fromServer(view, s.blocked_reason, s.next_allowed_at));
    }
    case 'tooth_brushing':
    case 'fold_cleaning':
    case 'ear_cleaning':
    case 'nail_trim': {
      // M5-R14 / R15 / R19 / R20 (David 2026-10-24): no care while the pet sleeps, like brushing.
      const c = view.careTasks.find((x) => x.task === action);
      if (c === undefined) return OFF;
      if (lock) return lock;
      if (c.can_start || c.session !== null) return ON;
      if (view.sleep.asleep) return sleeping(view, c.blocked_reason === 'care_task_quiet_hours' ? (c.next_allowed_at ?? view.sleep.until) : view.sleep.until);
      return fromServer(view, c.blocked_reason, c.next_allowed_at);
    }
  }
}

/** The whole table for the view (e.g. for a debug sheet or tests). */
export function actionRules(view: ChildPetView): Record<HudAction, ActionRule> {
  return Object.fromEntries(HUD_ACTIONS.map((a) => [a, actionRule(view, a)])) as Record<HudAction, ActionRule>;
}

/**
 * Text under a disabled button: the table's own (sleep, lock) or the action's existing hint
 * (`fallback`, e.g. "ob 17:00"). Null while enabled.
 */
export function disabledText(rule: ActionRule, fallback: string | null = null): string | null {
  if (rule.enabled) return null;
  return rule.text ?? fallback;
}
