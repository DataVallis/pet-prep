/**
 * The cat's litter clumps (CAT_SPEC Q3, GAMES_SPEC §6.2), pure: how many and where they lie.
 * Shared by the old tap overlay (`catCare/ScoopOverlay`) and the scoop game of the native layer.
 */

/** Clumps to scoop for `uses` open litter uses (two each, 2–6). */
export function clumpCount(uses: number): number {
  return Math.max(2, Math.min(6, uses * 2));
}

/** Fixed, spread-out spots inside the tray (percent of the tray box) — no randomness, testable. */
export const CLUMP_SPOTS: readonly { x: number; y: number }[] = [
  { x: 22, y: 38 },
  { x: 48, y: 58 },
  { x: 74, y: 40 },
  { x: 34, y: 70 },
  { x: 62, y: 74 },
  { x: 84, y: 64 },
];

/**
 * The litter area of `TrayGraphic` (viewBox 160 × 90: litter from x 26–134, y 38–70) — a clump
 * spot in that drawing, as a fraction of the drawing (0–1), so the clumps lie IN the sand.
 */
export function clumpInTray(index: number): { x: number; y: number } {
  const spot = CLUMP_SPOTS[index % CLUMP_SPOTS.length];
  return { x: (30 + (spot.x / 100) * 100) / 160, y: (38 + (spot.y / 100) * 30) / 90 };
}
