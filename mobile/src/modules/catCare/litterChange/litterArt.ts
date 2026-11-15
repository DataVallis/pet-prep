/**
 * The drawings of the weekly litter change (David 2026-11-15: drawn, no paid media) as plain shape
 * data — ONE source for the app (`LitterGraphics.tsx`, react-native-svg) and for the render check
 * outside the app. CGP v2 »Grafit in meta«: graphite neutrals for the tray and the bin, mint for
 * what the child acts with (the bag, the fill line), the `mess` sand tones for litter. Colours
 * only from `@/theme`.
 */

import { mess, palette } from '@/theme';

export type Shape =
  | { t: 'path'; d: string; fill?: string; fillOpacity?: number; evenOdd?: boolean; stroke?: string; strokeWidth?: number; strokeOpacity?: number; dash?: string }
  | { t: 'circle'; cx: number; cy: number; r: number; fill?: string; fillOpacity?: number; stroke?: string; strokeWidth?: number; strokeOpacity?: number }
  | { t: 'ellipse'; cx: number; cy: number; rx: number; ry: number; fill?: string; fillOpacity?: number; stroke?: string; strokeWidth?: number }
  | { t: 'rect'; x: number; y: number; w: number; h: number; rx?: number; fill?: string; fillOpacity?: number; stroke?: string; strokeWidth?: number };

export interface Art {
  w: number;
  h: number;
  shapes: readonly Shape[];
}

const BODY = 'M20 30 L300 30 L285 174 Q284 186 272 186 L48 186 Q36 186 35 174 Z';
const CAVITY = 'M34 38 L286 38 L274 164 Q273 174 263 174 L57 174 Q47 174 46 164 Z';

/** Behind the litter: the tray's body and its dark inside (viewBox = `TRAY_ART`). */
export const TRAY_BACK: Art = {
  w: 320,
  h: 190,
  shapes: [
    { t: 'path', d: BODY, fill: palette.n700 },
    { t: 'path', d: CAVITY, fill: palette.n850 },
    { t: 'ellipse', cx: 160, cy: 168, rx: 104, ry: 7, fill: palette.black, fillOpacity: 0.22 },
    { t: 'path', d: 'M50 46 L60 160', stroke: palette.white, strokeOpacity: 0.05, strokeWidth: 10 },
  ],
};

/** Over the litter: the walls (the body with the cavity cut out) and the rim. */
export const TRAY_FRONT: Art = {
  w: 320,
  h: 190,
  shapes: [
    { t: 'path', d: `${BODY} ${CAVITY}`, fill: palette.n700, evenOdd: true },
    { t: 'path', d: CAVITY, stroke: palette.n600, strokeWidth: 2 },
    { t: 'path', d: BODY, stroke: palette.n500, strokeWidth: 2.5 },
    { t: 'rect', x: 6, y: 14, w: 308, h: 24, rx: 12, fill: palette.n600, stroke: palette.n400, strokeWidth: 2.5 },
    { t: 'path', d: 'M24 22 L296 22', stroke: palette.white, strokeOpacity: 0.2, strokeWidth: 3 },
  ],
};

const WAVE = 'M0 10 Q23 2 46 8 T92 8 T138 8 T184 8 T230 8 T276 8 T322 8 T368 8 L368 200 L0 200 Z';

/** Old litter (used: sand with clumps). Drawn wider than the cavity so it can stay level in a tilted tray. */
export const OLD_LITTER: Art = {
  w: 368,
  h: 200,
  shapes: [
    { t: 'path', d: WAVE, fill: mess.litter },
    { t: 'path', d: 'M0 10 Q23 2 46 8 T92 8 T138 8 T184 8 T230 8 T276 8 T322 8 T368 8', stroke: mess.clump, strokeWidth: 2, strokeOpacity: 0.6 },
    ...[
      [74, 30, 9, 6],
      [132, 22, 7, 5],
      [176, 44, 10, 6],
      [228, 28, 8, 5],
      [286, 40, 9, 6],
      [104, 60, 8, 5],
      [252, 66, 9, 6],
    ].map(([cx, cy, rx, ry]): Shape => ({ t: 'ellipse', cx, cy, rx, ry, fill: mess.clumpLight, stroke: mess.clump, strokeWidth: 1.5 })),
    ...[
      [92, 44],
      [150, 62],
      [204, 24],
      [214, 70],
      [300, 62],
      [68, 70],
      [162, 30],
      [270, 20],
    ].map(([cx, cy]): Shape => ({ t: 'circle', cx, cy, r: 2, fill: mess.clump, fillOpacity: 0.7 })),
  ],
};

/** Fresh litter (clean, light). */
export const FRESH_LITTER: Art = {
  w: 368,
  h: 200,
  shapes: [
    { t: 'path', d: WAVE, fill: palette.n100 },
    { t: 'path', d: 'M0 10 Q23 2 46 8 T92 8 T138 8 T184 8 T230 8 T276 8 T322 8 T368 8', stroke: palette.white, strokeWidth: 3 },
    ...[
      [80, 28],
      [118, 46],
      [156, 24],
      [190, 52],
      [226, 30],
      [262, 50],
      [296, 26],
      [100, 66],
      [174, 72],
      [244, 70],
      [140, 60],
      [280, 68],
    ].map(([cx, cy]): Shape => ({ t: 'circle', cx, cy, r: 2.2, fill: palette.n300 })),
  ],
};

/** A dirt spot on the tray's inside. */
export const SPOT: Art = {
  w: 40,
  h: 32,
  shapes: [
    { t: 'path', d: 'M6 16 C4 8 14 3 22 5 C32 4 38 12 35 20 C33 28 22 30 14 28 C8 28 5 22 6 16 Z', fill: mess.clump, fillOpacity: 0.92 },
    { t: 'path', d: 'M14 14 C16 10 24 10 26 15 C27 20 20 22 16 20 Z', fill: mess.brown, fillOpacity: 0.4 },
    { t: 'circle', cx: 31, cy: 9, r: 2.2, fill: mess.clump, fillOpacity: 0.7 },
  ],
};

function bubble(cx: number, cy: number, r: number, o = 0.9): Shape[] {
  return [
    { t: 'circle', cx, cy, r, fill: palette.white, fillOpacity: o, stroke: palette.mintBorder, strokeWidth: 1.5 },
    { t: 'circle', cx: cx - r * 0.35, cy: cy - r * 0.35, r: r * 0.22, fill: palette.mintSoft, fillOpacity: 0.9 },
  ];
}

/** The foam under the finger. */
export const FOAM: Art = {
  w: 72,
  h: 72,
  shapes: [...bubble(28, 42, 16), ...bubble(46, 36, 14), ...bubble(36, 22, 12), ...bubble(54, 52, 9, 0.85), ...bubble(18, 24, 8, 0.85), ...bubble(58, 20, 5, 0.8)],
};

/** The suds left where a spot was rubbed away. */
export const SUDS: Art = {
  w: 40,
  h: 32,
  shapes: [...bubble(14, 18, 8, 0.75), ...bubble(26, 14, 6, 0.7), ...bubble(24, 24, 4, 0.7)],
};

/** The bin for the old litter (the mint arrow = »in here«). */
export const BIN: Art = {
  w: 100,
  h: 116,
  shapes: [
    { t: 'path', d: 'M14 26 L86 26 L79 104 Q78 113 69 113 L31 113 Q22 113 21 104 Z', fill: palette.n600, stroke: palette.n400, strokeWidth: 2.5 },
    { t: 'path', d: 'M36 46 L38 98', stroke: palette.n700, strokeWidth: 4 },
    { t: 'path', d: 'M64 46 L62 98', stroke: palette.n700, strokeWidth: 4 },
    { t: 'path', d: 'M40 62 L50 73 L60 62', stroke: palette.mint, strokeWidth: 5 },
    { t: 'ellipse', cx: 50, cy: 22, rx: 40, ry: 9, fill: palette.n850, stroke: palette.n300, strokeWidth: 2.5 },
  ],
};

/** The old litter showing in the bin's mouth (drawn over `BIN` once something was poured). */
export const BIN_HEAP: Art = {
  w: 100,
  h: 116,
  shapes: [
    { t: 'path', d: 'M22 23 Q36 10 50 13 Q66 9 78 23 Q64 30 50 30 Q36 30 22 23 Z', fill: mess.litter, stroke: mess.clump, strokeWidth: 1.5 },
    { t: 'ellipse', cx: 42, cy: 20, rx: 5, ry: 3, fill: mess.clumpLight },
    { t: 'ellipse', cx: 60, cy: 22, rx: 4, ry: 2.5, fill: mess.clumpLight },
  ],
};

/** The bag of fresh litter (mint = what the child holds); it pours from its upper left corner. */
export const BAG: Art = {
  w: 80,
  h: 100,
  shapes: [
    { t: 'path', d: 'M14 22 L6 9 L24 10 Z', fill: palette.n100, stroke: palette.mintDeep, strokeWidth: 2 },
    { t: 'path', d: 'M12 22 Q11 12 20 11 L60 9 Q70 9 71 19 L74 84 Q74 95 63 95 L18 95 Q7 95 8 84 Z', fill: palette.mint, stroke: palette.mintDeep, strokeWidth: 2.5 },
    { t: 'path', d: 'M16 24 L68 22', stroke: palette.mintDeep, strokeWidth: 2.5, strokeOpacity: 0.55 },
    { t: 'rect', x: 21, y: 38, w: 40, h: 38, rx: 10, fill: palette.mintSoft },
    { t: 'ellipse', cx: 41, cy: 62, rx: 8, ry: 7, fill: palette.mintDeep },
    { t: 'circle', cx: 31, cy: 51, r: 3.4, fill: palette.mintDeep },
    { t: 'circle', cx: 41, cy: 47, r: 3.4, fill: palette.mintDeep },
    { t: 'circle', cx: 51, cy: 51, r: 3.4, fill: palette.mintDeep },
  ],
};

/** The marked line the fresh litter is poured up to (as wide as the cavity). */
export const FILL_LINE: Art = {
  w: 248,
  h: 6,
  shapes: [{ t: 'path', d: 'M10 3 L238 3', stroke: palette.mint, strokeWidth: 3, dash: '9 8' }],
};

/** Grain colours (plain round `View`s, not svg). */
export const GRAIN = { old: mess.litter, fresh: palette.n100 } as const;
