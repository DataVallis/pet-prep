/**
 * M5-T12 »Prilagojen način« — where pushes come from, said clearly to the PARENT
 * (David 2026-10-26, after a device test on an iPhone without an Apple Watch: 0 pushes
 * for two days).
 *
 * Facts the texts rest on:
 *  - iPhone: Apple Health's push count (`HKQuantityTypeIdentifierPushCount`) is recorded only by
 *    an Apple Watch whose wearer has Health Details → Wheelchair = Yes. The iPhone alone never
 *    records pushes.
 *  - Android: Health Connect has a `WheelchairPushes` record, written only by a device or an app
 *    that records pushes; Android itself does not count them.
 *
 * The parent's view does not know which phone the child uses (the dashboard exposes no device
 * platform), so both lines are shown. The "no pushes yet today" hint is parent-only — the child
 * is never nagged.
 */

import { familyCalendar, localParts } from '@/modules/childPet/familyTime';
import { petOfChild, petStatus, type FamilyChild, type FamilyOverview, type FamilyPet } from '@/modules/family/family';
import { strings } from '@/i18n/strings';

/** All user-visible strings (`family:children.adapted.sources` / `noPushesToday`). */
export const ADAPTED_PUSH_STRINGS = strings('family', 'children', {});

/** The family default zone (`families.timezone` default) when the family has none. */
const DEFAULT_TIMEZONE = 'Europe/Ljubljana';

/** From this family-local hour on, a day with no pushes at all is worth a hint ("after noon"). */
export const NO_PUSHES_HINT_FROM_HOUR = 12;

/** Platform of the child's phone when known; the parent view never knows it today (→ null = both). */
export type ChildPhonePlatform = 'ios' | 'android' | null;

/** The "where pushes come from" lines for the parent, in display order. */
export function pushSourceLines(platform: ChildPhonePlatform = null): string[] {
  const S = ADAPTED_PUSH_STRINGS.adapted.sources;
  const lines: string[] = [];
  if (platform !== 'android') lines.push(S.ios);
  if (platform !== 'ios') lines.push(S.android);
  lines.push(S.noWatch);
  return lines;
}

/** The family-local hour (0–23) at `nowMs`, or null when the zone can't be read. */
function familyHour(timezone: string, nowMs: number): number | null {
  const time = localParts(new Date(nowMs).toISOString(), timezone)?.time;
  if (!time) return null;
  const hour = Number(time.slice(0, 2));
  return Number.isNaN(hour) ? null : hour;
}

/**
 * True when the parent should see "Danes še ni potiskov …": adapted mode on, a dog that can
 * walk today (active, not ill / paused / over, contract signed, with a step goal), today's row
 * has 0 counted steps (pushes + logged minutes, so a logged walk or any push hides it) and it
 * is past noon in the family's zone. A shared pet's day counts every child's steps — any
 * steps hide the hint (never a false alarm).
 */
export function noPushesHintDue(
  child: Pick<FamilyChild, 'adapted_mode' | 'contract_signed' | 'last_7_days'>,
  pet: FamilyPet | null,
  timezone: string | null,
  nowMs: number = Date.now(),
): boolean {
  if (!child.adapted_mode || !child.contract_signed || pet === null) return false;
  if (pet.species === 'cat' || petStatus(pet) !== null) return false;
  const zone = timezone ?? DEFAULT_TIMEZONE;
  const hour = familyHour(zone, nowMs);
  if (hour === null || hour < NO_PUSHES_HINT_FROM_HOUR) return false;
  const today = familyCalendar(zone, null).dateOf(nowMs);
  const row = child.last_7_days.find((d) => d.date === today);
  if (!row || row.walk_goal === null) return false;
  return row.walk_steps === 0 && row.walk_logged_minutes === 0;
}

/**
 * True when the child's page shows »Vpiši sprehod« (`LogWalkCard`): adapted mode on and a dog
 * (a cat has no steps). Same condition as `ChildDetailScreen`.
 */
export function canLogWalk(child: FamilyChild, family: FamilyOverview): boolean {
  if (!child.adapted_mode) return false;
  const pet = petOfChild(child, family);
  return pet !== null && pet.species !== 'cat';
}
