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
] as const;

export type PushType = (typeof PUSH_TYPES)[number];

export interface PushData {
  type: PushType;
  petId: number;
}

function isPushType(value: unknown): value is PushType {
  return typeof value === 'string' && (PUSH_TYPES as readonly string[]).includes(value);
}

/** Read a notification's `data`; anything that is not a PetPrep escalation push → null. */
export function parsePushData(data: unknown): PushData | null {
  if (typeof data !== 'object' || data === null) return null;
  const { type, pet_id: petId } = data as { type?: unknown; pet_id?: unknown };
  if (!isPushType(type)) return null;
  const id = typeof petId === 'string' ? Number(petId) : petId;
  if (typeof id !== 'number' || !Number.isInteger(id) || id <= 0) return null;
  return { type, petId: id };
}

/**
 * Phase 2 and above: sound in the foreground too (Android channel "alarm" on the server
 * side). The daily walk reminder is never urgent (PR #35).
 */
export function isUrgentPush(type: PushType | null | undefined): boolean {
  return type !== null && type !== undefined && type !== 'soft_warning' && type !== 'walk_reminder';
}
