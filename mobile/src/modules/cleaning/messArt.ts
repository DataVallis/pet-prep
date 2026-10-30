/**
 * Shape data of the mess illustrations (GAMES_SPEC §6.1 / §6.2, CGP v2 »Grafit in meta").
 *
 * Pure data (SVG path strings), no imports of a drawing library: `components/MessGraphics.tsx`
 * draws them with react-native-svg (every binary — the HUD, the old tap overlays) and the
 * cleaning games of the native layer draw the same paths (Skia for the masked puddle), so a
 * mess looks the same everywhere.
 *
 * Style: flat, rounded geometric shapes, a 2 px outline in a darker shade of the fill (like
 * Lucide), no faces, no flies, no realistic texture, no text. David 2026-10-30 chose sketch A for
 * the poop: a soft three-tier swirl, brown, one highlight.
 */

/** viewBox of a mess (as `BehaviourGraphics`): 120 × 80. */
export const MESS_VIEWBOX = { width: 120, height: 80 } as const;

/** Poop: a soft three-tier swirl standing on y = 72, one highlight, no face. */
export const POOP_PATHS = {
  bottom: 'M30 72 C24 72 22 64 28 60 C31 57.5 36 56.5 42 56.5 L78 56.5 C84 56.5 89 57.5 92 60 C98 64 96 72 90 72 Z',
  middle: 'M38 58 C33 58 32 51 37 48 C40 46 45 45 50 45 L70 45 C75 45 80 46 83 48 C88 51 87 58 82 58 Z',
  top: 'M46 46.5 C42 46.5 41.5 40 46 37.5 C49 35.5 53 35 57 35 C58.5 29 62 24.5 67 22 C66.5 27 69 31 72 34.5 C77 36.5 78.5 42 75 46.5 Z',
  /** The one highlight (white, 45 %). */
  shine: { cx: 50, cy: 49.5, rx: 6, ry: 2.2 },
} as const;

/** Pee: a shallow puddle with a rim and a reflection, plus the drop (the distinguishing mark). */
export const PEE_PATHS = {
  body: 'M16 54 C8 46 18 36 34 38 C40 30 60 28 72 34 C86 30 106 36 104 48 C112 56 100 66 84 64 C74 72 50 72 40 66 C26 70 10 64 16 54 Z',
  rim: 'M28 52 C26 46 36 42 46 44 C54 38 70 38 78 44 C90 42 98 48 94 54 C96 60 84 62 76 60 C66 64 50 64 44 60 C34 62 26 58 28 52 Z',
  shine: [
    { cx: 44, cy: 46, rx: 10, ry: 3 },
    { cx: 72, cy: 56, rx: 6, ry: 2 },
  ],
  drop: 'M100 4 C100 4 92 14 92 19.5 C92 24 95.6 27.5 100 27.5 C104.4 27.5 108 24 108 19.5 C108 14 100 4 100 4 Z',
  dropShine: { cx: 97.5, cy: 19, rx: 1.8, ry: 3 },
} as const;

/** One litter clump (viewBox 40 × 30): light and rounded — never a dark dot. */
export const CLUMP_VIEWBOX = { width: 40, height: 30 } as const;
export const CLUMP_PATHS = {
  body: 'M6 21 C4 13.5 12 8 20 9 C28 8 36 13 34 20.5 C34 25.5 27 27 20 26 C12 27 6 25.5 6 21 Z',
  top: { cx: 18, cy: 14.5, rx: 7, ry: 3 },
} as const;

/** One flake bitten off the slipper (viewBox 40 × 30): a soft fog-grey tuft. */
export const FLAKE_VIEWBOX = { width: 40, height: 30 } as const;
export const FLAKE_PATH = 'M8 16 C6 10 14 7.5 17 11 C20 6 28 7 28 13 C34 13 35 21 29 22 C28 27 20 28 18 24 C12 27 6 22 8 16 Z';

/** Tools (viewBox 60 × 60 each), the hand's objects in the cleaning games. */
export const TOOL_VIEWBOX = { width: 60, height: 60 } as const;
export const BAG_PATHS = {
  body: 'M12 24 L48 24 L45 54 C44.7 56.5 43 58 40.5 58 L19.5 58 C17 58 15.3 56.5 15 54 Z',
  /** Open: two handles; tied: one knot. */
  handles: 'M20 24 C20 14 26 12 26 18 M40 24 C40 14 34 12 34 18',
  knot: 'M24 24 L30 13 L36 24 Z',
} as const;
export const CLOTH_PATHS = {
  body: 'M10 18 C10 14 13 12 17 12 L44 12 C48 12 50 15 50 19 L50 44 C50 48 47 50 43 50 L16 50 C12 50 10 47 10 43 Z',
  fold: 'M10 30 C22 26 36 34 50 28',
  stripe: 'M18 12 L18 50 M42 12 L42 50',
} as const;
export const SCOOP_PATHS = {
  head: 'M6 14 C6 10 9 8 13 8 L38 8 C42 8 44 11 44 15 L44 38 C44 44 38 48 32 48 L18 48 C11 48 6 43 6 36 Z',
  slots: 'M16 16 L16 40 M25 16 L25 40 M34 16 L34 40',
  handle: 'M44 22 L58 22 L58 32 L44 32 Z',
} as const;
export const TOY_PATH =
  'M12 22 C6 22 4 16 8 13 C6 9 10 5 14 7 C16 4 22 6 22 11 L38 11 C38 6 44 4 46 7 C50 5 54 9 52 13 C56 16 54 22 48 22 C54 25 54 31 50 32 C52 36 46 39 44 35 C42 39 38 37 38 33 L22 33 C22 37 18 39 16 35 C14 39 8 36 10 32 C6 31 6 25 12 22 Z';
