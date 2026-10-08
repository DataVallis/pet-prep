/**
 * Family section of `GET /api/parent/dashboard` (M2-01 / M2-02) and pure helpers
 * for the parent's children list and "Dodaj otroka" flow.
 */

import { readPetPlan, type PetPlan } from '@/modules/plan/plan';
import { ApiError, type ParentDashboardResponse } from '@/api/client';
import { reasonOf } from '@/modules/pairing/pin';
import {
  emptyToday,
  readCareScore,
  readDayRows,
  readProgress,
  readTimeline,
  readToday,
  readTrafficLight,
  type CareScore,
  type ChallengeProgress,
  type DayRow,
  type TimelineEntry,
  type TodayRoutines,
  type TrafficLight,
} from '@/modules/family/scoring';
import { readPetBehaviour, type PetBehaviour } from '@/modules/behaviour/behaviour';
import { readPetTraining, type PetTrainingSummary } from '@/modules/training/training';
import { t } from '@/i18n';
import { strings } from '@/i18n/strings';

/** One pet of the family as the generated schema types it (raw API). */
export type FamilyPetRaw = NonNullable<ParentDashboardResponse['family']>['pets'][number];

/**
 * One pet of the family after normalisation: the schema's loose `timeline` and
 * union-typed `traffic_light` replaced by real types (M2-05).
 */
export type FamilyPet = Omit<FamilyPetRaw, 'timeline' | 'traffic_light' | 'care_score' | 'today' | 'behaviour' | 'training' | 'plan'> & {
  /** M3-11 / M3-13: free mutt sandbox or the challenge (payment_required / paid; trial = a pre-M3-13 trial); legacy → paid. */
  plan: PetPlan;
  traffic_light: TrafficLight;
  care_score: CareScore;
  today: TodayRoutines;
  timeline: TimelineEntry[];
  /** M5-R02: bladder clock + open messes; nothing for a legacy pet / older server. */
  behaviour: PetBehaviour;
  /** M5-R03: "Kuža zna …", today's training; disabled for a legacy pet / older server. */
  training: PetTrainingSummary;
};

export interface FamilyChildStats {
  days: number;
  fed: number;
  watered: number;
  cleaned: number;
  walk_goals: number;
  /** M5-R02: puppy take-outs ("Pelji ven"); 0 from older servers. */
  taken_out: number;
  /** M5-R02: chewed slippers tidied up; 0 from older servers. */
  chewing_resolved: number;
  actions_total: number;
  steps: number;
  active_step_days: number;
}

/**
 * One child of the family. Hand-typed: Scramble types `family.children` as a string
 * (backend `FamilyDashboardService::family`).
 */
export interface FamilyChild {
  id: number;
  name: string;
  birth_year: number | null;
  /** `pin` = profile without e-mail (M2-02); `email` = legacy account. */
  login: 'pin' | 'email';
  /** Signed-in devices (Sanctum tokens). */
  devices: number;
  pet_id: number | null;
  contract_signed: boolean;
  stats: FamilyChildStats;
  /** M2-06: today's light for this child (shared pet: every missed routine they shared). */
  traffic_light: TrafficLight;
  /** M2-06: fair-share Care Score (null score = no routine expected yet). */
  care_score: CareScore;
  /** M2-06: today's routines this child shares. */
  today: TodayRoutines;
  /** M2-06: oldest → newest, 7 family-local days. */
  last_7_days: DayRow[];
  /** 12-week challenge since the child's contract; null before it. */
  progress: ChallengeProgress | null;
}

export interface FamilyParent {
  id: number;
  name: string;
  is_me: boolean;
}

export interface FamilyOverview {
  id: number;
  timezone: string;
  parents: FamilyParent[];
  children: FamilyChild[];
  pets: FamilyPet[];
}

function isFamilyChild(value: unknown): value is FamilyChild {
  if (typeof value !== 'object' || value === null) return false;
  const c = value as Record<string, unknown>;
  return typeof c.id === 'number' && typeof c.name === 'string' && typeof c.devices === 'number';
}

function isFamilyParent(value: unknown): value is FamilyParent {
  if (typeof value !== 'object' || value === null) return false;
  const p = value as Record<string, unknown>;
  return typeof p.id === 'number' && typeof p.name === 'string';
}

const STAT_KEYS = [
  'days',
  'fed',
  'watered',
  'cleaned',
  'walk_goals',
  'taken_out',
  'chewing_resolved',
  'actions_total',
  'steps',
  'active_step_days',
] as const satisfies readonly (keyof FamilyChildStats)[];

/** Stats with every counter a number (older servers lack the M5-R02 ones). */
function readStats(value: unknown): FamilyChildStats {
  const o = typeof value === 'object' && value !== null ? (value as Record<string, unknown>) : {};
  const stats = {} as FamilyChildStats;
  for (const key of STAT_KEYS) {
    const v = o[key];
    stats[key] = typeof v === 'number' && Number.isFinite(v) ? v : 0;
  }
  return stats;
}

/** Scoring fields with safe defaults (an older backend without M2-06 sends none). */
function normalizeChild(raw: FamilyChild): FamilyChild {
  const c = raw as unknown as Record<string, unknown>;
  return {
    ...raw,
    stats: readStats(c.stats),
    traffic_light: readTrafficLight(c.traffic_light),
    care_score: readCareScore(c.care_score),
    today: c.today === undefined ? emptyToday() : readToday(c.today),
    last_7_days: readDayRows(c.last_7_days),
    progress: readProgress(c.progress),
  };
}

export function normalizePet(raw: FamilyPetRaw): FamilyPet {
  const p = raw as unknown as Record<string, unknown>;
  return {
    ...raw,
    metrics: raw.metrics ?? { hunger: 0, thirst: 0, energy: 0, hygiene: 0 },
    caretakers: Array.isArray(raw.caretakers) ? raw.caretakers : [],
    traffic_light: readTrafficLight(p.traffic_light),
    care_score: readCareScore(p.care_score),
    today: readToday(p.today),
    timeline: readTimeline(p.timeline),
    behaviour: readPetBehaviour(p.behaviour),
    training: readPetTraining(p.training),
    plan: readPetPlan(p.plan),
  };
}

/** The dashboard's `family` (null when the parent has none yet). Malformed children are dropped. */
export function familyFromDashboard(data: ParentDashboardResponse | undefined): FamilyOverview | null {
  const family = data?.family;
  if (!family) return null;
  const rawChildren: unknown = family.children;
  const rawParents: unknown = family.parents;
  return {
    id: family.id,
    timezone: family.timezone,
    parents: Array.isArray(rawParents) ? rawParents.filter(isFamilyParent).map((p) => ({ ...p, is_me: p.is_me === true })) : [],
    children: Array.isArray(rawChildren) ? rawChildren.filter(isFamilyChild).map(normalizeChild) : [],
    pets: Array.isArray(family.pets) ? family.pets.map(normalizePet) : [],
  };
}

/** The pet a child currently cares for (null: no pet yet / pet not in the family block). */
export function petOfChild(child: FamilyChild, family: FamilyOverview): FamilyPet | null {
  return child.pet_id === null ? null : (family.pets.find((p) => p.id === child.pet_id) ?? null);
}

/** Nickname of a family child by id (timeline items from `/activities` carry only the id). */
export function nicknameOf(childId: number | null, family: FamilyOverview | null): string | null {
  if (childId === null || !family) return null;
  return family.children.find((c) => c.id === childId)?.name ?? null;
}

/** What the parent should know about a pet's state, most important first (null = playing normally). */
export type PetStatus = 'game_over' | 'awaiting_contract' | 'inactive' | 'hard_stopped' | 'ill';

export function petStatus(pet: FamilyPet): PetStatus | null {
  if (pet.is_game_over) return 'game_over';
  if (pet.awaiting_contract) return 'awaiting_contract';
  if (!pet.is_active) return 'inactive';
  if (pet.is_hard_stopped) return 'hard_stopped';
  if (pet.is_ill) return 'ill';
  return null;
}

/** "Kuža je pri veterinarju" … — a live view (i18n `family:petStatus`): read it when rendering. */
export const PET_STATUS_LABELS: Readonly<Record<PetStatus, string>> = strings('family', 'petStatus');

/** Pets a new child may join: alive and active (the backend refuses others with `pet_not_joinable`). */
export function joinablePets(family: FamilyOverview | null): FamilyPet[] {
  return family ? family.pets.filter((p) => p.is_active && !p.is_game_over) : [];
}

const BREED_LABELS = strings('family', 'breeds') as Readonly<Record<string, string | undefined>>;

/** "Mešanček" / "Mixed breed"; an unknown breed code is shown as is. */
export function breedLabel(breed: string): string {
  return BREED_LABELS[breed] ?? breed;
}

/** Nicknames of the children caring for a pet ("Maja, Luka"). */
export function caretakerNames(pet: Pick<FamilyPet, 'caretakers'>, family: FamilyOverview): string {
  return pet.caretakers
    .map((c) => family.children.find((child) => child.id === c.child_id)?.name)
    .filter((name): name is string => typeof name === 'string')
    .join(', ');
}

/** Count of devices: "1 naprava", "2 napravi", "3 naprave", "5 naprav" / "1 device", "2 devices". */
export function devicesLabel(count: number): string {
  return t('family:devices', { count: Math.max(0, Math.floor(count)) });
}

/** What the parent knew about the child when the PIN was issued. */
export interface ChildBaseline {
  devices: number;
  pet_id: number | null;
}

/**
 * The PIN was used: the child got a pet (first pairing) or a new device signed in.
 * Limitation: a relogin for a child already on 3 devices keeps the count at 3
 * (the oldest token is dropped) and can't be detected from the dashboard.
 */
export function isChildConnected(child: FamilyChild | undefined, baseline: ChildBaseline): boolean {
  if (!child) return false;
  if (baseline.pet_id === null && child.pet_id !== null) return true;
  return child.devices > baseline.devices;
}

export const NICKNAME_MAX_LENGTH = 30;

/** Collapse whitespace like the backend does; null when empty / too long / looks like an e-mail. */
export function normalizeNickname(input: string): string | null {
  const name = input.trim().replace(/\s+/g, ' ');
  if (name.length === 0 || name.length > NICKNAME_MAX_LENGTH || name.includes('@')) return null;
  return name;
}

/** Child profiles are minors: birth year within the last 18 years (backend rule). */
export function birthYearRange(now: Date = new Date()): { min: number; max: number } {
  const max = now.getFullYear();
  return { min: max - 18, max };
}

/**
 * '' → null (optional), a valid year → number, anything else → 'invalid'.
 */
export function parseBirthYear(input: string, now: Date = new Date()): number | null | 'invalid' {
  const value = input.trim();
  if (value === '') return null;
  if (!/^\d{4}$/.test(value)) return 'invalid';
  const year = Number(value);
  const { min, max } = birthYearRange(now);
  return year >= min && year <= max ? year : 'invalid';
}

export type CreateChildErrorKind = 'too_many_children' | 'invalid_name' | 'offline' | 'server';

export function classifyCreateChildError(error: unknown): CreateChildErrorKind {
  if (error instanceof ApiError) {
    if (error.status === 422) {
      return reasonOf(error) === 'too_many_children' ? 'too_many_children' : 'invalid_name';
    }
    return 'server';
  }
  return 'offline';
}

export type RevokeErrorKind = 'not_found' | 'offline' | 'server';

export function classifyRevokeError(error: unknown): RevokeErrorKind {
  if (error instanceof ApiError) return error.status === 404 ? 'not_found' : 'server';
  return 'offline';
}
