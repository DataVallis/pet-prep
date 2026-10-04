/**
 * Family section of `GET /api/parent/dashboard` (M2-01 / M2-02) and pure helpers
 * for the parent's children list and "Dodaj otroka" flow.
 */

import { ApiError, type ParentDashboardResponse } from '@/api/client';
import { reasonOf } from '@/modules/pairing/pin';

/** One pet of the family — typed by the generated schema. */
export type FamilyPet = NonNullable<ParentDashboardResponse['family']>['pets'][number];

export interface FamilyChildStats {
  days: number;
  fed: number;
  watered: number;
  cleaned: number;
  walk_goals: number;
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

/** The dashboard's `family` (null when the parent has none yet). Malformed children are dropped. */
export function familyFromDashboard(data: ParentDashboardResponse | undefined): FamilyOverview | null {
  const family = data?.family;
  if (!family) return null;
  const rawChildren: unknown = family.children;
  const rawParents: unknown = family.parents;
  return {
    id: family.id,
    timezone: family.timezone,
    parents: Array.isArray(rawParents) ? (rawParents as FamilyParent[]) : [],
    children: Array.isArray(rawChildren) ? rawChildren.filter(isFamilyChild) : [],
    pets: Array.isArray(family.pets) ? family.pets : [],
  };
}

/** Pets a new child may join: alive and active (the backend refuses others with `pet_not_joinable`). */
export function joinablePets(family: FamilyOverview | null): FamilyPet[] {
  return family ? family.pets.filter((p) => p.is_active && !p.is_game_over) : [];
}

const BREED_LABELS: Record<string, string> = {
  mutt: 'Mešanček',
  border_collie: 'Border collie',
};

export function breedLabel(breed: string): string {
  return BREED_LABELS[breed] ?? breed;
}

/** Nicknames of the children caring for a pet ("Maja, Luka"). */
export function caretakerNames(pet: FamilyPet, family: FamilyOverview): string {
  return pet.caretakers
    .map((c) => family.children.find((child) => child.id === c.child_id)?.name)
    .filter((name): name is string => typeof name === 'string')
    .join(', ');
}

/** Slovenian count of devices: 1 naprava, 2 napravi, 3–4 naprave, 0 / 5+ naprav. */
export function devicesLabel(count: number): string {
  const n = Math.max(0, Math.floor(count));
  const mod100 = n % 100;
  if (mod100 === 1) return `${n} naprava`;
  if (mod100 === 2) return `${n} napravi`;
  if (mod100 === 3 || mod100 === 4) return `${n} naprave`;
  return `${n} naprav`;
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
