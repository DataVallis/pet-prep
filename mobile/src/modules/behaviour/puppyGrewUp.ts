/**
 * »{{name}} je zrasel!« (David 2026-11-27, »Pravilo ostane + sporočilo«) — the puppy potty clock
 * stays puppy-stage only (PRODUCT_SPEC §5.4), but on the LAST puppy day the server's
 * `behaviour.take_out` turns null as soon as the next due instant would fall on the first
 * young-dog day (`BehaviourEventService::dueFrom` — after the evening take-out, or any take-out
 * whose hold reaches past midnight). »Pelji ven« and its card then vanished silently and a tester
 * took it for a bug. Instead the HUD top stack shows one calm, dismissible card.
 *
 * Pure rule ({@link puppyGrewUpFor}) — everything comes from the state, nothing from the phone's
 * own zone or a local copy of the potty rules:
 * - dog (a cat never has the potty clock; a missing species = an older dog-only server),
 * - a non-legacy profile with `behaviour_enabled` (the pet has behaviour events → it had the
 *   potty clock today) and today's stage `puppy`,
 * - `next_stage` = `young` from the NEXT pet-local day (`familyCalendar(view.timezone)`),
 * - `take_out` is null (also when the app opens after the take-out already happened),
 * - not locked.
 * An adult / young dog, a cat, a legacy pet or a puppy with days left never gets it.
 *
 * Dismissed with × per pet + boundary date on this device (SecureStore, ids + dates only, no
 * personal data); an unreadable store counts as dismissed (never a card on every start). The
 * card disappears by itself the next pet-local day (the stage is then `young`).
 */

import { useCallback, useEffect, useState } from 'react';
import * as SecureStore from 'expo-secure-store';

import type { ChildPetView } from '@/modules/childPet/childPetView';
import { familyCalendar } from '@/modules/childPet/familyTime';
import { t } from '@/i18n';

export const PUPPY_GREW_UP_KEY = 'petprep.puppy_grew_up_dismissed';
const MAX_ENTRIES = 20;

/** The card to show: which pet and which boundary (the first young-dog day, pet-local Y-m-d). */
export interface PuppyGrewUp {
  petId: number;
  fromDate: string;
  /** The pet's name for the text (allowed in the child app — never in a push); null → nameless text. */
  name: string | null;
}

/** Pure: the card for this state at `serverNowMs`, or null. */
export function puppyGrewUpFor(view: ChildPetView, serverNowMs: number): PuppyGrewUp | null {
  const { pet } = view;
  if (view.lock.is_locked || view.behaviour.take_out !== null) return null;
  if (pet.species !== null && pet.species !== 'dog') return null;
  const profile = pet.profile;
  if (profile === null || !profile.behaviourEnabled || profile.stage !== 'puppy') return null;
  const next = profile.nextStage;
  if (next === null || next.stage !== 'young') return null;
  const calendar = familyCalendar(view.timezone, view.server_time);
  const tomorrow = calendar.dateOf(calendar.nextMidnight(serverNowMs));
  if (next.fromDate !== tomorrow) return null;
  return { petId: pet.id, fromDate: next.fromDate, name: pet.name };
}

/** Card text: »Bafo je zrasel! Od jutri ga ni treba več peljati ven na potrebo — zdaj štejejo sprehodi.« */
export function puppyGrewUpText(card: PuppyGrewUp): string {
  return card.name !== null ? t('behaviour:child.grewUp', { name: card.name }) : t('behaviour:child.grewUpNoName');
}

/** Collapsed pill: »Bafo je zrasel!«. */
export function puppyGrewUpShort(card: PuppyGrewUp): string {
  return card.name !== null ? t('behaviour:child.grewUpShort', { name: card.name }) : t('behaviour:child.grewUpShortNoName');
}

// ── Device memory (one-time) ─────────────────────────────────────────

export interface GrewUpStore {
  getItemAsync(key: string): Promise<string | null>;
  setItemAsync(key: string, value: string): Promise<void>;
}

let store: GrewUpStore = SecureStore;
/** Dismissals of this app run — they hold at once, also before the store write has landed. */
const dismissedThisRun = new Set<string>();

export function setPuppyGrewUpStoreForTests(next: GrewUpStore | null): void {
  store = next ?? SecureStore;
  dismissedThisRun.clear();
}

const entry = (card: Pick<PuppyGrewUp, 'petId' | 'fromDate'>) => `${card.petId}:${card.fromDate}`;

function parse(value: string | null): string[] {
  if (!value) return [];
  try {
    const list: unknown = JSON.parse(value);
    return Array.isArray(list) ? list.filter((k): k is string => typeof k === 'string') : [];
  } catch {
    return [];
  }
}

export async function wasPuppyGrewUpDismissed(card: Pick<PuppyGrewUp, 'petId' | 'fromDate'>): Promise<boolean> {
  if (dismissedThisRun.has(entry(card))) return true;
  try {
    return parse(await store.getItemAsync(PUPPY_GREW_UP_KEY)).includes(entry(card));
  } catch {
    return true;
  }
}

export async function markPuppyGrewUpDismissed(card: Pick<PuppyGrewUp, 'petId' | 'fromDate'>): Promise<void> {
  const key = entry(card);
  dismissedThisRun.add(key);
  try {
    const list = parse(await store.getItemAsync(PUPPY_GREW_UP_KEY)).filter((k) => k !== key);
    list.push(key);
    await store.setItemAsync(PUPPY_GREW_UP_KEY, JSON.stringify(list.slice(-MAX_ENTRIES)));
  } catch {
    // Best effort: the dismissal still holds for this app run.
  }
}

/**
 * The card while it applies and was not dismissed on this device; `dismiss` hides it at once and
 * remembers it. Hidden until the device memory is read (never a flash of a dismissed card).
 */
export function usePuppyGrewUp(view: ChildPetView | undefined, serverNowMs: number): { card: PuppyGrewUp | null; dismiss: () => void } {
  const card = view ? puppyGrewUpFor(view, serverNowMs) : null;
  const id = card ? entry(card) : null;
  // `checked` = the entry whose memory has been read; `hidden` = dismissed (stored or just now).
  const [memory, setMemory] = useState<{ checked: string | null; hidden: boolean }>({ checked: null, hidden: true });

  useEffect(() => {
    if (card === null || id === null) return;
    let alive = true;
    void wasPuppyGrewUpDismissed(card).then((dismissed) => {
      if (alive) setMemory((m) => (m.checked === id ? m : { checked: id, hidden: dismissed }));
    });
    return () => {
      alive = false;
    };
    // `card` is derived from `id` (pet + date); the name alone never re-reads the memory.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id]);

  const dismiss = useCallback(() => {
    if (card === null || id === null) return;
    setMemory({ checked: id, hidden: true });
    void markPuppyGrewUpDismissed(card);
  }, [card, id]);

  const shown = card !== null && memory.checked === id && !memory.hidden ? card : null;
  return { card: shown, dismiss };
}
