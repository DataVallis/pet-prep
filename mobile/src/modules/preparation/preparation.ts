/**
 * Pet preparation on the phone (M5-T06, APP_REFINEMENT_SPEC §13, David 2026-10-20).
 *
 * The child (and parent) must NEVER see that images / videos are being made: while the
 * pet's first things are on their way the app shows a calm background set-up — Curio and
 * »Pripravljamo: Luna … to lahko traja nekaj minut« — features still waiting say
 * »… se še pripravlja«, and when everything is there the child gets »Luna je pripravljen!«
 * once per pet (in-app message; the server sends the push).
 *
 * Source: `preparation` of the child state, the parent dashboard pet and `pet.updated`
 * (backend `PreparationPayload`): `{ready, pending, preparing, running, ready_at}`.
 * An older server sends none → `NO_PREPARATION` (never a set-up screen).
 *
 * Wording rules: the Slovenian name is never declined — it stands after a colon
 * (»Pripravljamo: Luna«) or as the subject in the nominative (»Luna je pripravljen!«).
 * Without a name the species noun is used. Nothing here mentions AI, images or videos.
 */

import { t } from '@/i18n';

import { isolatePetName } from '@/modules/petName/petName';

export type PreparationItem =
  | 'image'
  | 'idle'
  | 'sleeping'
  | 'low_energy'
  | 'hungry'
  | 'sick'
  | 'playing'
  | 'accident'
  | 'chewing'
  | 'scratching';

const ITEMS: readonly PreparationItem[] = ['image', 'idle', 'sleeping', 'low_energy', 'hungry', 'sick', 'playing', 'accident', 'chewing', 'scratching'];

export interface PetPreparation {
  /** Everything the pet shows is there (or nothing will come). */
  ready: boolean;
  /** What is not there yet, in the order it comes (`image` = the pose). */
  pending: PreparationItem[];
  /** Show the set-up screen (the pose or a first-minutes clip is on its way). */
  preparing: boolean;
  /** The pet's preparation runs (ends once, with `ready_at`). */
  running: boolean;
  /** When the shown preparation ended (ISO); null when it never ran / still runs. */
  readyAt: string | null;
}

export const NO_PREPARATION: PetPreparation = { ready: true, pending: [], preparing: false, running: false, readyAt: null };

function isItem(value: unknown): value is PreparationItem {
  return typeof value === 'string' && (ITEMS as readonly string[]).includes(value);
}

/** `preparation` from any payload; missing / malformed → `NO_PREPARATION` (older server). */
export function readPreparation(raw: unknown): PetPreparation {
  if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) return NO_PREPARATION;
  const p = raw as Record<string, unknown>;
  const pending = Array.isArray(p.pending) ? p.pending.filter(isItem) : [];
  const ready = p.ready === true || (p.ready !== false && pending.length === 0);
  const readyAt = typeof p.ready_at === 'string' && p.ready_at.length > 0 ? p.ready_at : null;
  return {
    ready,
    pending: ready ? [] : pending,
    preparing: p.preparing === true && !ready,
    running: p.running === true,
    readyAt,
  };
}

/** Back to the wire shape (the parent dashboard cache keeps raw payloads, validated). */
export function preparationPayload(p: PetPreparation): { ready: boolean; pending: PreparationItem[]; preparing: boolean; running: boolean; ready_at: string | null } {
  return { ready: p.ready, pending: p.pending, preparing: p.preparing, running: p.running, ready_at: p.readyAt };
}

/**
 * The full set-up screen covers the HUD only while the server says so — and never over a
 * lock (the lock screen explains), never once a contract is still to be signed.
 */
export function showPreparationScreen(preparation: PetPreparation, locked: boolean): boolean {
  return preparation.preparing && !locked;
}

type SpeciesKey = 'dog' | 'cat';

function speciesKey(species: string | null | undefined): SpeciesKey {
  return species === 'cat' ? 'cat' : 'dog';
}

const tr = t as unknown as (key: string, options?: Record<string, unknown>) => string;

/** »Pripravljamo: Luna« / "Getting Luna ready" — without a name »Pripravljamo kužka«. */
export function preparationTitle(name: string | null, species: string | null | undefined): string {
  return name
    ? tr('preparation:titleNamed', { name: isolatePetName(name) })
    : tr(`preparation:${speciesKey(species)}.title`);
}

/** The parent card: »Pripravljamo: Luna« / without a name »Pripravljamo kužka« (no "your"). */
export function parentPreparationTitle(name: string | null, species: string | null | undefined): string {
  return name
    ? tr('preparation:titleNamed', { name: isolatePetName(name) })
    : tr(`preparation:${speciesKey(species)}.parentTitle`);
}

/** Screen-reader label of Curio on the set-up screen. */
export function preparationA11y(): string {
  return tr('preparation:a11y');
}

/** »To lahko traja nekaj minut.« */
export function preparationBody(): string {
  return tr('preparation:body');
}

/**
 * »Luna je pripravljen!« / "Luna is ready!" — the name as the subject (nominative). SL
 * agreement follows the species (kuža → pripravljen, muca → pripravljena), like every other
 * child text (the app has no pet sex).
 */
export function readyMessage(name: string | null, species: string | null | undefined): string {
  const key = speciesKey(species);
  return name
    ? tr(`preparation:${key}.readyNamed`, { name: isolatePetName(name) })
    : tr(`preparation:${key}.ready`);
}

/** »… je pripravljen!« belongs to the day of the preparation — a sign-in days later gets no news. */
export const READY_NEWS_MAX_AGE_MS = 12 * 60 * 60 * 1000;

/**
 * The child is told »… je pripravljen!« once per pet: when the server marks the shown
 * preparation as ended (`ready_at`, at most `READY_NEWS_MAX_AGE_MS` before `nowMs`), the HUD
 * is not locked and this device has not said it for that pet yet.
 */
export function shouldAnnounceReady(preparation: PetPreparation, alreadyAnnounced: boolean, locked: boolean, nowMs: number): boolean {
  if (preparation.readyAt === null || alreadyAnnounced || locked) return false;
  const readyMs = Date.parse(preparation.readyAt);
  return !Number.isNaN(readyMs) && nowMs - readyMs <= READY_NEWS_MAX_AGE_MS;
}

/** »Šola se še pripravlja« / "School is still getting ready". */
export function schoolPreparingText(): string {
  return tr('preparation:school');
}

/** School stage while its clips are on their way: show the pose (reference image), not a clip. */
export function schoolClipsPending(preparation: PetPreparation): boolean {
  return preparation.pending.includes('idle') || preparation.pending.includes('playing');
}
