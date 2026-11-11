/**
 * M6-U02 (FREE_PET_UPGRADE_SPEC U5 / D1, David 2026-11-10): the day-7 reminder card on the parent
 * dashboard — »[Vzdevek] že 7 dni skrbi za [ime]. Je čas za pravi preizkus?«. The SERVER decides
 * (`pets[].upgrade_reminder`: once 7 days after the contract, when one child acted on 5 of those
 * 7 days; only while the free pet can still be upgraded, for 7 days) — the app never counts days.
 * The button opens the PR 1 upgrade paywall for that pet. »×« hides the card on this phone for that
 * pet (SecureStore: pet ids only, no personal data). An older server sends no field → no card.
 */

import * as SecureStore from 'expo-secure-store';

import type { FamilyOverview, FamilyPet } from '@/modules/family/family';
import { canUpgradePet } from '@/modules/plan/purchaseEntry';

export interface UpgradeReminder {
  sentAt: string;
  /** The child whose 5 of 7 days qualified; null when that profile is gone. */
  childId: number | null;
}

export function readUpgradeReminder(raw: unknown): UpgradeReminder | null {
  if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) return null;
  const o = raw as Record<string, unknown>;
  if (typeof o.sent_at !== 'string' || o.sent_at === '') return null;
  const id = o.child_id;
  return { sentAt: o.sent_at, childId: typeof id === 'number' && Number.isInteger(id) && id > 0 ? id : null };
}

/** One card to show: the pet, its name (null = none) and the child's nickname (null = unknown). */
export interface UpgradeReminderCard {
  pet: FamilyPet;
  petName: string | null;
  childName: string | null;
}

/** The cards to show now (pets in dashboard order), without the pets dismissed on this phone. */
export function upgradeReminderCards(family: FamilyOverview | null, dismissedPetIds: readonly number[]): UpgradeReminderCard[] {
  if (!family) return [];
  return family.pets.flatMap((pet) => {
    const reminder = readUpgradeReminder((pet as unknown as Record<string, unknown>).upgrade_reminder);
    if (reminder === null || dismissedPetIds.includes(pet.id) || !canUpgradePet(pet)) return [];
    const name = pet.name?.trim() ?? '';
    const child = reminder.childId === null ? undefined : family.children.find((c) => c.id === reminder.childId);
    return [{ pet, petName: name === '' ? null : name, childName: child?.name ?? null }];
  });
}

export const UPGRADE_REMINDER_DISMISSED_KEY = 'petprep.upgrade_reminder_dismissed';

export interface ReminderPrefsStore {
  getItemAsync(key: string): Promise<string | null>;
  setItemAsync(key: string, value: string): Promise<void>;
}

let store: ReminderPrefsStore = SecureStore;

export function setUpgradeReminderStoreForTests(next: ReminderPrefsStore | null): void {
  store = next ?? SecureStore;
}

/** Pet ids whose card was dismissed on this phone. An unreadable store reads as none. */
export async function dismissedReminderPets(): Promise<number[]> {
  try {
    const raw = await store.getItemAsync(UPGRADE_REMINDER_DISMISSED_KEY);
    const parsed: unknown = raw ? JSON.parse(raw) : [];
    return Array.isArray(parsed) ? parsed.filter((v): v is number => typeof v === 'number' && Number.isInteger(v) && v > 0) : [];
  } catch {
    return [];
  }
}

/** Remember a dismissed card (keeps the last 50 pet ids). */
export async function dismissReminderPet(petId: number): Promise<number[]> {
  const next = [...new Set([...(await dismissedReminderPets()), petId])].slice(-50);
  try {
    await store.setItemAsync(UPGRADE_REMINDER_DISMISSED_KEY, JSON.stringify(next));
  } catch {
    // best effort
  }
  return next;
}
