/**
 * Brushing as a scratch card (M5-T14, APP_REFINEMENT_SPEC §11) — pure logic for the grooming
 * stroke game (dog and cat, `ChoreOverlay` kind `grooming`).
 *
 * The server's rule is unchanged (`CatChoreService::score`, mirrored in `scoreStrokes`):
 * ≥ `min_strokes` strokes ≥ `min_stroke_interval_ms` apart, spread over all `segments`, not
 * machine-regular; the game still finishes when the session time is up. This module only
 * turns the same strokes into something a child can see:
 *
 * - every segment (= step "Glava in vrat → Hrbet → Boki in rep") owns `PATCHES_PER_SEGMENT`
 *   "dirty" patches in its band of the pet (head at the top, tail at the bottom);
 * - a counted stroke cleans one unit of the patch of the CURRENT segment closest to the
 *   finger (no point = the first patch that is still dirty);
 * - a segment's patches need `ceil(min_strokes / segments)` strokes in that segment, so
 *   "all patches clean" (= progress 100 %) implies the server's stroke count and spread.
 *   Patches of a step that is over stay as they are (the bar then never reaches 100 % — the
 *   same thing the server will say with `not_spread` / `too_few_strokes`).
 *
 * Coordinates are normalised (0…1 of the stage width / height).
 */

import { segmentOf, type ChoreRules, type Point } from '@/modules/catCare/catGames';

/** Dirty patches per segment (3 segments today → 6 patches). */
export const PATCHES_PER_SEGMENT = 2;

/** The body band the patches sit in (normalised stage height). */
const BAND_TOP = 0.2;
const BAND_BOTTOM = 0.86;

export interface BrushPatch {
  id: number;
  segment: number;
  /** Centre, normalised. */
  x: number;
  y: number;
  /** Radius as a share of the stage's shorter side. */
  r: number;
}

/** Deterministic patch layout: two per segment, alternating left / right in its band. */
export function layoutPatches(segments: number, perSegment: number = PATCHES_PER_SEGMENT): BrushPatch[] {
  const n = Math.max(1, Math.floor(segments));
  const k = Math.max(1, Math.floor(perSegment));
  const band = (BAND_BOTTOM - BAND_TOP) / n;
  const patches: BrushPatch[] = [];
  for (let s = 0; s < n; s++) {
    for (let j = 0; j < k; j++) {
      const x = k === 1 ? 0.5 : 0.28 + (0.44 * j) / (k - 1) + (s % 2 === 0 ? 0 : 0.06);
      const y = BAND_TOP + band * s + band * (0.35 + 0.3 * (j % 2));
      patches.push({ id: s * k + j, segment: s, x: Math.min(0.85, x), y, r: Math.min(0.2, 0.55 / n) });
    }
  }
  return patches;
}

/** Strokes a segment needs for its patches to be clean (≥ the server's share of `min_strokes`). */
export function strokesPerSegment(rules: Pick<ChoreRules, 'min_strokes' | 'segments'>): number {
  return Math.max(1, Math.ceil(Math.max(1, rules.min_strokes) / Math.max(1, rules.segments)));
}

/** Units of one patch (a segment's strokes split over its patches, rounded up). */
export function unitsPerPatch(rules: Pick<ChoreRules, 'min_strokes' | 'segments'>, perSegment: number = PATCHES_PER_SEGMENT): number {
  return Math.max(1, Math.ceil(strokesPerSegment(rules) / Math.max(1, perSegment)));
}

export interface BrushState {
  /** Cleaned units per patch (index = patch id). */
  cleaned: readonly number[];
  /** Last stroke time that counted (server's `min_stroke_interval_ms` rule); null = none. */
  lastCountedMs: number | null;
}

export function initialBrush(patchCount: number): BrushState {
  return { cleaned: Array.from({ length: Math.max(0, patchCount) }, () => 0), lastCountedMs: null };
}

function dist2(a: Point, b: Point): number {
  return (a.x - b.x) ** 2 + (a.y - b.y) ** 2;
}

/**
 * The open patch of `segment` nearest to `point` (normalised; null = the first open one).
 * Null when the segment is clean.
 */
export function targetPatch(patches: readonly BrushPatch[], cleaned: readonly number[], units: number, segment: number, point: Point | null): BrushPatch | null {
  const open = patches.filter((p) => p.segment === segment && (cleaned[p.id] ?? 0) < units);
  if (open.length === 0) return null;
  if (point === null || !Number.isFinite(point.x) || !Number.isFinite(point.y)) return open[0];
  return open.reduce((best, p) => (dist2(p, point) < dist2(best, point) ? p : best));
}

/**
 * One stroke at `ms` (game clock) at `point` (normalised, or null for the button / hold /
 * VoiceOver). Same state when the stroke would not count (out of the session, closer than
 * `min_stroke_interval_ms` to the last counted one) or its segment is already clean.
 */
export function applyStroke(state: BrushState, rules: ChoreRules, patches: readonly BrushPatch[], ms: number, point: Point | null): BrushState {
  const t = Math.round(ms);
  if (!Number.isFinite(t) || t < 0 || t > rules.duration_ms) return state;
  // Strokes are recorded in time order; one closer than the interval counts once on the server.
  if (state.lastCountedMs !== null && t - state.lastCountedMs < rules.min_stroke_interval_ms) return state;
  const segment = segmentOf(t, rules.duration_ms, rules.segments);
  const units = unitsPerPatch(rules, patchesPerSegment(patches, rules.segments));
  const patch = targetPatch(patches, state.cleaned, units, segment, point);
  if (patch === null) return { ...state, lastCountedMs: t };
  const cleaned = [...state.cleaned];
  cleaned[patch.id] = Math.min(units, (cleaned[patch.id] ?? 0) + 1);
  return { cleaned, lastCountedMs: t };
}

function patchesPerSegment(patches: readonly BrushPatch[], segments: number): number {
  return Math.max(1, Math.round(patches.length / Math.max(1, segments)));
}

export interface BrushProgress {
  /** 0…1 — share of all patch units cleaned (the progress bar). */
  fraction: number;
  /** Whole percent for texts / accessibility (floored, 100 only when all clean). */
  percent: number;
  /** Dirt left per patch, 0 (clean) … 1 (untouched). */
  dirt: number[];
  allClean: boolean;
}

export function brushProgress(state: BrushState, rules: ChoreRules, patches: readonly BrushPatch[]): BrushProgress {
  const units = unitsPerPatch(rules, patchesPerSegment(patches, rules.segments));
  const total = units * patches.length;
  const done = patches.reduce((sum, p) => sum + Math.min(units, state.cleaned[p.id] ?? 0), 0);
  const fraction = total > 0 ? done / total : 1;
  return {
    fraction,
    percent: done >= total ? 100 : Math.min(99, Math.floor(fraction * 100)),
    dirt: patches.map((p) => 1 - Math.min(units, state.cleaned[p.id] ?? 0) / units),
    allClean: done >= total,
  };
}

/** Normalise a stage point (pt) to 0…1; null for an unknown stage size. */
export function normalisePoint(p: Point, size: { width: number; height: number }): Point | null {
  if (!(size.width > 0) || !(size.height > 0)) return null;
  return { x: Math.min(1, Math.max(0, p.x / size.width)), y: Math.min(1, Math.max(0, p.y / size.height)) };
}

// ── Press-and-hold alternative (APP_REFINEMENT_SPEC §6) ──────

/**
 * Gaps between strokes while a finger (or switch / VoiceOver user) holds the "Počeši" button
 * or the pet. Irregular on purpose: the server rejects machine-regular rhythms (≥ 8 gaps
 * within 40 ms of each other), and a held brush should count like a hand brushing.
 */
export const HOLD_GAPS_MS: readonly number[] = [640, 780, 700, 860, 610, 740, 820, 680];
/** A finger resting on the pet this long (without travelling) starts the hold brushing. */
export const HOLD_START_MS = 450;
/** Travel (pt) that turns a hold on the pet into a swipe. */
export const HOLD_SLOP_PT = 12;

/** The gap after the `n`-th held stroke (0-based). */
export function holdGap(n: number): number {
  const i = ((Math.floor(n) % HOLD_GAPS_MS.length) + HOLD_GAPS_MS.length) % HOLD_GAPS_MS.length;
  return HOLD_GAPS_MS[i];
}

// ── Particles (finger feedback) ──────────────────────────────

/** At most one sparkle per this many ms / pt of travel (mid-range Android budget). */
export const PARTICLE_MIN_MS = 70;
export const PARTICLE_MIN_PT = 14;
export const PARTICLE_POOL = 8;

/** Whether a new sparkle may spawn (throttle by time AND travel since the last one). */
export function shouldSpawnParticle(last: { t: number; p: Point } | null, t: number, p: Point): boolean {
  if (last === null) return true;
  return t - last.t >= PARTICLE_MIN_MS && Math.hypot(p.x - last.p.x, p.y - last.p.y) >= PARTICLE_MIN_PT;
}

// ── Finishing once the coat is clean ─────────────────────────

/**
 * How long before the session's end a clean coat may finish. The server accepts a finish up
 * to `finish_early_tolerance_seconds` (3 s) before `ends_at`; the app's game clock starts when
 * the start answer arrives (after the server's `started_at`), so 2 s early on the app clock
 * is never early for the server.
 */
export const EARLY_FINISH_LEAD_MS = 2_000;

/** All patches clean, from the stroke times alone (which patch a stroke hit doesn't matter). */
export function brushCleanFromStrokes(rules: ChoreRules, strokes: readonly number[], perSegment: number = PATCHES_PER_SEGMENT): boolean {
  const segments = Math.max(1, rules.segments);
  const capacity = unitsPerPatch(rules, perSegment) * Math.max(1, perSegment);
  const perSeg = Array.from({ length: segments }, () => 0);
  let last: number | null = null;
  for (const raw of strokes) {
    const t = Math.round(raw);
    if (!Number.isFinite(t) || t < 0 || t > rules.duration_ms) continue;
    if (last !== null && t - last < rules.min_stroke_interval_ms) continue;
    last = t;
    perSeg[segmentOf(t, rules.duration_ms, segments)] += 1;
  }
  return perSeg.every((n) => n >= capacity);
}

/** When the grooming finishes: at the end as today, or a little earlier once the coat is clean. */
export function brushFinishAt(rules: ChoreRules, strokes: readonly number[]): number {
  return brushCleanFromStrokes(rules, strokes) ? Math.max(0, rules.duration_ms - EARLY_FINISH_LEAD_MS) : rules.duration_ms;
}
