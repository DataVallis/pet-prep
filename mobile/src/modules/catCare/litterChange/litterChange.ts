/**
 * The weekly full litter change as three different gestures (David 2026-11-15: »Želim da je bolj
 * interaktivno« — a different gesture per step, drawn animation that follows the finger, no paid
 * media), pure. `LitterChangeGame` draws it; Jest calls everything here as plain functions.
 *
 *   1. Izprazni — DRAG the tray down to the bin: it tilts with the drag and the old litter pours
 *      out while it is tipped (at most `POUR_PER_DRAG` of the tray per drag → 2–3 drags).
 *   2. Umij    — RUB inside the tray: every dirt spot fades with the finger's travel over it
 *      (`SCRUB_TRAVEL`), clean once `WASH_DONE_SPOTS` of the `SPOTS` are gone.
 *   3. Napolni — PRESS AND HOLD (the bag or the tray): fresh litter rises while held
 *      (`FILL_HOLD_MS` for a full tray), pauses on release, stops at the marked line.
 *
 * NO server change (`CatChoreService`, GAMES_SPEC §7.7): the session is still 30 s in three equal
 * parts, the steps ARE those parts (`choreStepAt`), and the app still reports strokes `{t}` — one
 * per piece of real work at its real time: a poured fifth of the tray (step 1); a rub turn / lift
 * exactly as before (`rubMove` = `catGames#moveRub`) and every spot rubbed away (step 2); a pour
 * beat per stretch of HELD time, at the irregular `holdGap`s the hold mode always used (step 3 —
 * the held time adds up across presses, so a full tray is always the same 7 beats). A child who
 * finishes each gesture in its part has ≥ `min_strokes` (10) counted strokes in all three parts
 * (at least 3 + 1 + 7); the server's verdict decides as before. A step whose time ran out is
 * settled without a ✓ (`settleBefore`) — never a penalty, never shaming.
 *
 * Functions marked `'worklet'` run on the UI thread inside the gesture callbacks.
 */

import type { CareFrame } from '@/modules/gameLayer/careFrame';

// ── Art boxes (the drawings' own units, `litterArt.ts`) ───────────────────────

/** The tray drawing's viewBox and the inner cavity (where litter, spots and the line live). */
export const TRAY_ART = { w: 320, h: 190, cavity: { x: 36, y: 38, w: 248, h: 136 } } as const;
/** Share of the cavity's height the old litter fills at the start / the fresh litter at the line. */
export const OLD_FILL = 0.62;
export const FRESH_FILL = 0.56;

// ── Layout ────────────────────────────────────────────────────────────────────

/** Nothing the child must touch or aim at is closer than this to the field's edge (the field is already inset 12 pt → ≥ 24 dp from the screen edge, where Android's back gesture starts). */
export const EDGE_PT = 12;
/** The tray's tilt (degrees, clockwise = towards the bin) when fully tipped. */
export const MAX_TILT_DEG = 35;

export interface LitterLayout {
  W: number;
  H: number;
  trayW: number;
  trayH: number;
  /** Tray centre at rest. */
  home: { x: number; y: number };
  /** Tray centre when fully tipped over the bin. */
  pose: { x: number; y: number };
  /** Unit vector home → pose and its length (the drag that tips the tray fully). */
  ux: number;
  uy: number;
  pathLen: number;
  /** The bin (centre, size) and its mouth (where the litter lands). */
  bin: { x: number; y: number; w: number; h: number; mouthX: number; mouthY: number };
  /** The litter bag (centre, size) of step 3. */
  bag: { x: number; y: number; w: number; h: number };
  /** The tray's scale and centre while washing / filling (as wide as the screen allows; its top edge stays put). */
  bigScale: number;
  big: { x: number; y: number };
}

/** Top of the »floor« the tray stands on (field points): under the tray at rest, under the enlarged one later. */
export function floorTop(L: LitterLayout, step: 1 | 2 | 3): number {
  return step === 1 ? L.home.y + L.trayH * 0.3 : L.big.y + L.trayH * L.bigScale * 0.3;
}

export function litterLayout(W: number, H: number): LitterLayout {
  const trayW = Math.min(W * 0.7, 360, H * 0.62);
  const trayH = (trayW * TRAY_ART.h) / TRAY_ART.w;
  const binW = Math.max(72, Math.min(120, W * 0.26));
  const binH = binW * 1.16;
  const bin = { x: W - binW / 2 - EDGE_PT, y: H - binH / 2 - EDGE_PT, w: binW, h: binH, mouthX: 0, mouthY: 0 };
  bin.mouthX = bin.x - binW * 0.08;
  bin.mouthY = bin.y - binH / 2 + binH * 0.08;
  const bagW = Math.max(64, Math.min(96, W * 0.24));
  const bagH = bagW * 1.25;
  const bag = { x: W - bagW / 2 - EDGE_PT - 4, y: EDGE_PT + bagH / 2, w: bagW, h: bagH };
  const home = { x: W / 2, y: Math.max(bag.y + bagH / 2 + trayH / 2 - bagH * 0.18, H * 0.36) };
  // Fully tipped: the tray's upper right corner sits just above the bin's mouth.
  const rad = (MAX_TILT_DEG * Math.PI) / 180;
  const a = trayW * 0.47;
  const b = -trayH * 0.42;
  const lipX = a * Math.cos(rad) - b * Math.sin(rad);
  const lipY = a * Math.sin(rad) + b * Math.cos(rad);
  const pose = { x: Math.max(trayW * 0.5, bin.mouthX - lipX), y: Math.max(home.y + 60, bin.mouthY - 6 - lipY) };
  const pathLen = Math.hypot(pose.x - home.x, pose.y - home.y);
  const top = home.y - trayH / 2;
  const bigScale = Math.max(1, Math.min(1.35, (W - 2 * EDGE_PT) / trayW, (H - EDGE_PT - top) / trayH));
  // A little lower than at rest (under the thumb), never over the bag's corner or the bottom edge.
  const bigH = trayH * bigScale;
  const big = { x: W / 2, y: Math.min(H - EDGE_PT - bigH / 2, Math.max(top + bigH / 2, H * 0.46)) };
  return { W, H, trayW, trayH, home, pose, ux: (pose.x - home.x) / pathLen, uy: (pose.y - home.y) / pathLen, pathLen, bin, bag, bigScale, big };
}

// ── Step 1: drag → tilt → pour ────────────────────────────────────────────────

/** The tray starts to pour once it is this far along its way to the bin. */
export const POUR_FROM = 0.55;
/** One drag pours at most this share of a full tray (→ 3 drags: 40 % + 40 % + 20 %). */
export const POUR_PER_DRAG = 0.4;
/** A stroke per poured fifth of the tray. */
export const POUR_CHUNKS = 5;
/** Less than this left = empty (no crumb that needs a fourth drag). */
export const LEFT_EPSILON = 0.02;

/** How far the tray is on its way to the bin (0…1) for a finger translation (tx, ty). */
export function dragProgress(tx: number, ty: number, ux: number, uy: number, pathLen: number): number {
  'worklet';
  if (!(pathLen > 0)) return 0;
  const d = (tx * ux + ty * uy) / pathLen;
  return d < 0 || d !== d ? 0 : d > 1 ? 1 : d;
}

/** Tilt (degrees) at drag progress `d` — it tilts with the drag from the first point. */
export function tiltAt(d: number): number {
  'worklet';
  return MAX_TILT_DEG * (d < 0 ? 0 : d > 1 ? 1 : d);
}

/** Share of this drag's pour that is out at progress `d` (0 before `POUR_FROM`, 1 fully tipped). */
export function pourShare(d: number): number {
  'worklet';
  const s = (d - POUR_FROM) / (1 - POUR_FROM);
  return s < 0 || s !== s ? 0 : s > 1 ? 1 : s;
}

/** Old litter left (0…1) when a drag that started with `startLeft` is at progress `d`. */
export function leftAfter(startLeft: number, d: number): number {
  'worklet';
  const left = startLeft - POUR_PER_DRAG * pourShare(d);
  return left < LEFT_EPSILON ? 0 : left;
}

/** Poured fifths so far (0…`POUR_CHUNKS`) — each new one is a stroke. */
export function pourChunks(left: number): number {
  'worklet';
  const n = Math.floor((1 - left) * POUR_CHUNKS + 1e-6);
  return n < 0 ? 0 : n > POUR_CHUNKS ? POUR_CHUNKS : n;
}

// (Worklets are `const`s after the Babel transform — a worklet must be defined BEFORE the worklet that calls it.)

/** A spot's drawn width (share of the cavity's width). */
export const SPOT_DRAW = 0.17;
/** The bag's tilt (degrees, anticlockwise) while it pours. */
export const BAG_TILT_DEG = 50;

/** Where the tilted bag's corner pours from (field points). */
export function bagSpout(L: LitterLayout): { x: number; y: number } {
  const rad = (-BAG_TILT_DEG * Math.PI) / 180;
  const a = -L.bag.w * 0.42;
  const b = -L.bag.h * 0.45;
  return { x: L.bag.x + a * Math.cos(rad) - b * Math.sin(rad), y: L.bag.y + a * Math.sin(rad) + b * Math.cos(rad) };
}

/** y (field points) of the fresh litter's surface in the enlarged tray at fill 0…1. */
export function freshSurfaceY(L: LitterLayout, fill: number): number {
  'worklet';
  const k = (L.trayW * L.bigScale) / TRAY_ART.w;
  const bottom = L.big.y + (TRAY_ART.cavity.y + TRAY_ART.cavity.h - TRAY_ART.h / 2) * k;
  return bottom - fill * FRESH_FILL * TRAY_ART.cavity.h * k;
}

/** The tray's pouring corner (field points) at drag progress `d`. */
export function trayLip(L: LitterLayout, d: number): { x: number; y: number } {
  'worklet';
  const rad = (tiltAt(d) * Math.PI) / 180;
  const a = L.trayW * 0.47;
  const b = -L.trayH * 0.42;
  return {
    x: L.home.x + (L.pose.x - L.home.x) * d + a * Math.cos(rad) - b * Math.sin(rad),
    y: L.home.y + (L.pose.y - L.home.y) * d + a * Math.sin(rad) + b * Math.cos(rad),
  };
}

// ── Step 2: rub → spots ───────────────────────────────────────────────────────

/** Dirt spots inside the cavity: u = share of its width, v = share of its height. */
export const SPOTS: readonly { u: number; v: number }[] = [
  { u: 0.13, v: 0.52 },
  { u: 0.29, v: 0.78 },
  { u: 0.43, v: 0.5 },
  { u: 0.57, v: 0.8 },
  { u: 0.7, v: 0.54 },
  { u: 0.86, v: 0.76 },
  { u: 0.15, v: 0.84 },
  { u: 0.89, v: 0.5 },
];
export const SPOT_US: readonly number[] = SPOTS.map((s) => s.u);
export const SPOT_VS: readonly number[] = SPOTS.map((s) => s.v);
/** A spot is rubbed when the finger passes within this of its centre (share of the cavity's width). */
export const SPOT_REACH = 0.1;
/** Finger travel over a spot that cleans it (share of the cavity's width) — about two passes. */
export const SCRUB_TRAVEL = 0.34;
/** »Most spots«: the tray is clean once this many of the spots are gone (the rest fade with the sparkle). */
export const WASH_DONE_SPOTS = SPOTS.length - 1;
const CAVITY_ASPECT = TRAY_ART.cavity.h / TRAY_ART.cavity.w;

/** All spots dirty. */
export function dirtySpots(): number[] {
  return SPOTS.map(() => 1);
}

/**
 * A field point → cavity units (u along the width, v along the height) for a tray centred on
 * (cx, cy), `trayW` wide and drawn at `scale`.
 */
export function cavityPoint(x: number, y: number, cx: number, cy: number, trayW: number, scale: number): { u: number; v: number } {
  'worklet';
  const k = (trayW * scale) / TRAY_ART.w;
  const left = cx - (TRAY_ART.w / 2 - TRAY_ART.cavity.x) * k;
  const top = cy - (TRAY_ART.h / 2 - TRAY_ART.cavity.y) * k;
  return { u: (x - left) / (TRAY_ART.cavity.w * k), v: (y - top) / (TRAY_ART.cavity.h * k) };
}

/**
 * Rub from (u0, v0) to (u1, v1) (cavity units): every spot the stroke passes within `SPOT_REACH`
 * of loses dirt in proportion to the travel OVER it (the stroke's chord through the spot — the same
 * whatever the sampling rate → about two passes). Returns the same array when nothing changed.
 * A finger that rests (no travel) cleans nothing — holding is not washing.
 */
export function scrubSpots(dirt: readonly number[], u0: number, v0: number, u1: number, v1: number): readonly number[] {
  'worklet';
  // Distances in cavity WIDTHS on both axes (v is a share of the height).
  const dx = u1 - u0;
  const dy = (v1 - v0) * CAVITY_ASPECT;
  const len2 = dx * dx + dy * dy;
  if (!(len2 > 0)) return dirt;
  const travel = Math.sqrt(len2);
  let next: number[] | null = null;
  for (let i = 0; i < dirt.length; i++) {
    if (dirt[i] <= 0) continue;
    const px = SPOT_US[i] - u0;
    const py = (SPOT_VS[i] - v0) * CAVITY_ASPECT;
    // The part of the stroke that lies over the spot (its chord through the spot's circle).
    const tc = (px * dx + py * dy) / len2;
    const qx = dx * tc - px;
    const qy = dy * tc - py;
    const off2 = qx * qx + qy * qy;
    if (off2 > SPOT_REACH * SPOT_REACH) continue;
    const half = Math.sqrt(SPOT_REACH * SPOT_REACH - off2) / travel;
    const from = tc - half < 0 ? 0 : tc - half;
    const to = tc + half > 1 ? 1 : tc + half;
    if (to <= from) continue;
    if (next === null) next = dirt.slice();
    const left = dirt[i] - ((to - from) * travel) / SCRUB_TRAVEL;
    next[i] = left < 0.04 ? 0 : left;
  }
  return next === null ? dirt : next;
}

/** Bit mask of the clean spots. */
export function cleanMask(dirt: readonly number[]): number {
  'worklet';
  let mask = 0;
  for (let i = 0; i < dirt.length; i++) if (dirt[i] <= 0) mask |= 1 << i;
  return mask;
}

export function countBits(mask: number): number {
  'worklet';
  let n = 0;
  for (let m = mask; m !== 0; m &= m - 1) n++;
  return n;
}

/** Spots still dirty for a mask of clean ones. */
export function spotsLeft(mask: number): number {
  return SPOTS.length - countBits(mask & ((1 << SPOTS.length) - 1));
}

export function isWashed(mask: number): boolean {
  return SPOTS.length - spotsLeft(mask) >= WASH_DONE_SPOTS;
}

/**
 * Rub strokes on the UI thread — the same rule as `catGames#moveRub` / `endRub` (a turn after
 * ≥ `RUB_MIN_PT` out and `RUB_TURN_PT` back, or a lift after ≥ `RUB_MIN_PT` of travel).
 */
export const RUB_MIN_PT = 40;
export const RUB_TURN_PT = 20;

export interface Rub {
  sx: number;
  sy: number;
  /** Furthest point from the start and its distance. */
  fx: number;
  fy: number;
  fd: number;
  path: number;
  lx: number;
  ly: number;
}

export function rubBegin(x: number, y: number): Rub {
  'worklet';
  return { sx: x, sy: y, fx: x, fy: y, fd: 0, path: 0, lx: x, ly: y };
}

export function rubMove(rub: Rub, x: number, y: number): { rub: Rub; stroke: boolean } {
  'worklet';
  if (x !== x || y !== y) return { rub, stroke: false };
  const path = rub.path + Math.hypot(x - rub.lx, y - rub.ly);
  const d = Math.hypot(x - rub.sx, y - rub.sy);
  let fx = rub.fx;
  let fy = rub.fy;
  let fd = rub.fd;
  if (d > fd) {
    fx = x;
    fy = y;
    fd = d;
  }
  const back = Math.hypot(x - fx, y - fy);
  if (fd >= RUB_MIN_PT && back >= RUB_TURN_PT && d < fd - RUB_TURN_PT / 2) {
    return { rub: { sx: fx, sy: fy, fx: x, fy: y, fd: back, path: back, lx: x, ly: y }, stroke: true };
  }
  return { rub: { sx: rub.sx, sy: rub.sy, fx, fy, fd, path, lx: x, ly: y }, stroke: false };
}

export function rubEnd(rub: Rub): boolean {
  'worklet';
  return rub.fd >= RUB_MIN_PT || rub.path >= RUB_MIN_PT;
}

// ── Step 3: hold → fill ───────────────────────────────────────────────────────

/** Holding this long fills an empty tray to the line. */
export const FILL_HOLD_MS = 5_000;
/** The first pour beat (a stroke) comes this soon after the finger goes down. */
export const FILL_FIRST_BEAT_MS = 300;

/** Fresh litter (0…1 = at the line) after holding `heldMs` from `base`. Never past the line. */
export function fillLevel(base: number, heldMs: number): number {
  const level = base + (Number.isFinite(heldMs) && heldMs > 0 ? heldMs / FILL_HOLD_MS : 0);
  return level >= 1 - 1e-9 ? 1 : level < 0 ? 0 : level;
}

/** ms of holding still needed to reach the line. */
export function fillRemainingMs(level: number): number {
  return Math.max(0, Math.round((1 - level) * FILL_HOLD_MS));
}

// ── Progress (JS side) ────────────────────────────────────────────────────────

export type LitterStep = 1 | 2 | 3;

export interface LitterProgress {
  /** Old litter left, 1…0. */
  left: number;
  /** Bit mask of the clean spots. */
  clean: number;
  /** Fresh litter, 0…1 (1 = at the line). */
  fill: number;
  /** Steps the CHILD finished with their own gesture (✓). */
  done: readonly [boolean, boolean, boolean];
}

export const LITTER_START: LitterProgress = { left: 1, clean: 0, fill: 0, done: [false, false, false] };
const ALL_SPOTS = (1 << SPOTS.length) - 1;

function withDone(p: LitterProgress, i: 0 | 1 | 2): LitterProgress['done'] {
  return [i === 0 || p.done[0], i === 1 || p.done[1], i === 2 || p.done[2]];
}

/** The pour reached `left` (only ever less). Empty = step 1 done. */
export function afterPour(p: LitterProgress, left: number): LitterProgress {
  const next = Math.max(0, Math.min(p.left, left));
  if (next === p.left) return p;
  return { ...p, left: next, done: next === 0 ? withDone(p, 0) : p.done };
}

/** Spots in `mask` are clean now. Most spots clean = step 2 done (the rest go with the sparkle). */
export function afterScrub(p: LitterProgress, mask: number): LitterProgress {
  const clean = (p.clean | mask) & ALL_SPOTS;
  if (clean === p.clean) return p;
  return isWashed(clean) ? { ...p, clean: ALL_SPOTS, done: withDone(p, 1) } : { ...p, clean };
}

/** The fill reached `level` (only ever more, never past the line). At the line = step 3 done. */
export function afterFill(p: LitterProgress, level: number): LitterProgress {
  const fill = Math.min(1, Math.max(p.fill, level));
  if (fill === p.fill) return p;
  return { ...p, fill, done: fill >= 1 ? withDone(p, 2) : p.done };
}

/**
 * The session moved on to `step`: whatever an earlier step left undone is settled so the picture
 * stays true (an emptied, washed tray) — WITHOUT its ✓ (`done` only ever comes from a gesture).
 */
export function settleBefore(p: LitterProgress, step: LitterStep): LitterProgress {
  const left = step >= 2 ? 0 : p.left;
  const clean = step >= 3 ? ALL_SPOTS : p.clean;
  return left === p.left && clean === p.clean ? p : { ...p, left, clean };
}

/** One beat of the hold button (»Lažje kretnje« / screen reader) in `step`: the same progress in equal parts. */
export const HOLD_FILL_BEATS = 6;
export function holdBeat(p: LitterProgress, step: LitterStep): LitterProgress {
  if (step === 1) return afterPour(p, p.left - 1 / POUR_CHUNKS < LEFT_EPSILON ? 0 : p.left - 1 / POUR_CHUNKS);
  if (step === 2) {
    for (let i = 0; i < SPOTS.length; i++) if ((p.clean & (1 << i)) === 0) return afterScrub(p, 1 << i);
    return p;
  }
  return afterFill(p, fillLevel(p.fill, FILL_HOLD_MS / HOLD_FILL_BEATS));
}

/** Whether `step`'s own gesture has finished it. */
export function stepDone(p: LitterProgress, step: LitterStep): boolean {
  return p.done[step - 1];
}

/** The shell's frame: counted strokes (haptics) and the ✓ per step the child finished. */
export function litterFrame(countedStrokes: number, p: LitterProgress): CareFrame {
  return { strokes: countedStrokes, stepsDone: [...p.done] };
}

/** What the drawing shows as a number, for the screen reader (whole percent / spots). */
export function litterReadout(p: LitterProgress, step: LitterStep): { kind: 'old' | 'spots' | 'fresh'; value: number } {
  if (step === 1) return { kind: 'old', value: Math.round(p.left * 100) };
  if (step === 2) return { kind: 'spots', value: spotsLeft(p.clean) };
  return { kind: 'fresh', value: Math.round(p.fill * 100) };
}
