/**
 * What a PetPrep push carries (M3-02): `data = { type, pet_id }` only — no names,
 * nothing else (Expo / APNs / FCM are third parties). The types equal the server's
 * escalation event types (`PushType` / `PetUpdated.event_type`).
 */

export const PUSH_TYPES = [
  'soft_warning',
  'critical_alert',
  'walk_reminder',
  'parent_intervention_alarm',
  'illness_triggered',
  'game_over_virtual_shelter',
  // M2-01d: a child's 12-week challenge ended (parents only, once per child).
  'challenge_completed',
  // M5-T06: the pet's preparation ended (caretaker children, once per pet).
  'pet_ready',
  // M5-T13: the pet's first birthday (caretaker children + parents, once per pet).
  'pet_birthday',
  // M5-R18 / R20: a groomer / bath period started (parents only, once per period).
  'care_reminder',
  // M6-W01: a child wished for an item (parents only; data `{type, wish_id}` — no pet id).
  'wish_created',
] as const;

export type PushType = (typeof PUSH_TYPES)[number];

export interface PushData {
  type: PushType;
  /** The pet the push is about; null only for `wish_created` (its data carries `wish_id`). */
  petId: number | null;
}

function positiveId(value: unknown): number | null {
  const id = typeof value === 'string' ? Number(value) : value;
  return typeof id === 'number' && Number.isInteger(id) && id > 0 ? id : null;
}

function isPushType(value: unknown): value is PushType {
  return typeof value === 'string' && (PUSH_TYPES as readonly string[]).includes(value);
}

/** Read a notification's `data`; anything that is not a PetPrep escalation push → null. */
export function parsePushData(data: unknown): PushData | null {
  if (typeof data !== 'object' || data === null) return null;
  const { type, pet_id: petId, wish_id: wishId } = data as { type?: unknown; pet_id?: unknown; wish_id?: unknown };
  if (!isPushType(type)) return null;
  const id = positiveId(petId);
  // M6-W01: the wish push names no pet (WISHES_API §6) — it opens the parent's wish list.
  if (type === 'wish_created') return positiveId(wishId) !== null || id !== null ? { type, petId: id } : null;
  if (id === null) return null;
  return { type, petId: id };
}

/**
 * Phase 2 and above: sound in the foreground too (Android channel "alarm" on the server
 * side). The daily walk reminder is never urgent (PR #35), nor is the end of a challenge.
 */
export function isUrgentPush(type: PushType | null | undefined): boolean {
  return (
    type !== null &&
    type !== undefined &&
    type !== 'soft_warning' &&
    type !== 'walk_reminder' &&
    // Good news / a reminder, never an alarm (M2-01d, M5-T06, M5-T13, M5-R18).
    type !== 'challenge_completed' &&
    type !== 'pet_ready' &&
    type !== 'pet_birthday' &&
    type !== 'care_reminder' &&
    type !== 'wish_created'
  );
}
