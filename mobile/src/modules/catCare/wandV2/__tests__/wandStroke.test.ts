/** GAMES_SPEC §5.3 — finger strokes → v2 moves with `in_zone` / `speed_ok` from the session. */
import {
  beginStroke,
  dodgeButtonMove,
  endStroke,
  featherForFinger,
  FEATHER_LIFT_PT,
  floatPoint,
  inZone,
  moveStroke,
  strokeToMove,
  wandCatAt,
  wandZone,
  type StrokeTracker,
  type WandStroke,
} from '@/modules/catCare/wandV2/wandStroke';

const SIZE = { width: 400, height: 800 };
const zone = wandZone(SIZE);
const cat = wandCatAt(SIZE);
const rules = { max_speed_pt_s: 4000, min_dodge_pt: 40 };

/** Drag through `points` (one sample every 16 ms at `speed`), lift at the last one. */
function drag(points: { x: number; y: number }[], speed: number, t0 = 1000): WandStroke[] {
  const out: WandStroke[] = [];
  let tr: StrokeTracker = beginStroke(points[0], t0, cat, speed);
  points.slice(1, -1).forEach((p, i) => {
    const step = moveStroke(tr, p, t0 + (i + 1) * 16, cat, speed);
    tr = step.tracker;
    if (step.stroke) out.push(step.stroke);
  });
  const last = points[points.length - 1];
  const end = endStroke(tr, last, t0 + (points.length - 1) * 16, cat, speed);
  if (end) out.push(end);
  return out;
}

function line(from: { x: number; y: number }, to: { x: number; y: number }, n = 10) {
  return Array.from({ length: n + 1 }, (_, i) => ({ x: from.x + ((to.x - from.x) * i) / n, y: from.y + ((to.y - from.y) * i) / n }));
}

describe('wand v2 strokes', () => {
  it('a calm pull away inside the zone is one clean move', () => {
    const strokes = drag(line({ x: 200, y: 450 }, { x: 200, y: 300 }), 900);
    expect(strokes).toHaveLength(1);
    expect(strokeToMove(strokes[0], zone, rules)).toEqual({ t: strokes[0].t, away: true, in_zone: true, speed_ok: true });
  });

  it('a swipe off the top of the screen ends outside the zone', () => {
    // One stroke for the whole sweep — never an in-zone first chunk (v1 split sweeps every 180 pt).
    const strokes = drag(line({ x: 200, y: 400 }, { x: 200, y: -60 }, 20), 1500);
    expect(strokes).toHaveLength(1);
    expect(strokeToMove(strokes[0], zone, rules).in_zone).toBe(false);
  });

  it('a flick faster than the session limit, or a tiny twitch, is not a clean stroke', () => {
    const [fast] = drag(line({ x: 200, y: 450 }, { x: 200, y: 300 }), 5200);
    expect(strokeToMove(fast, zone, rules).speed_ok).toBe(false);
    const [short] = drag(line({ x: 200, y: 400 }, { x: 200, y: 370 }, 4), 600);
    expect(short.lengthPt).toBeLessThan(40);
    expect(strokeToMove(short, zone, rules).speed_ok).toBe(false);
    // The limit is the session's, not a constant.
    expect(strokeToMove(fast, zone, { max_speed_pt_s: 6000, min_dodge_pt: 40 }).speed_ok).toBe(true);
  });

  it('away and back without lifting = two moves; the peak speed is per stroke', () => {
    const there = line({ x: 200, y: 450 }, { x: 200, y: 300 });
    const back = line({ x: 200, y: 300 }, { x: 200, y: 450 }).slice(1);
    let tr = beginStroke(there[0], 0, cat, 800);
    const out: WandStroke[] = [];
    [...there.slice(1), ...back].forEach((p, i) => {
      const fast = i < there.length - 1;
      const step = moveStroke(tr, p, (i + 1) * 16, cat, fast ? 5000 : 700);
      tr = step.tracker;
      if (step.stroke) out.push(step.stroke);
    });
    const end = endStroke(tr, null, 400, cat, 700);
    if (end) out.push(end);
    expect(out.map((s) => s.away)).toEqual([true, false]);
    expect(out[0].peakPtS).toBe(5000);
    expect(out[1].peakPtS).toBeLessThan(5000);
  });

  it('geometry: the feather floats above the finger; the zone is the upper part', () => {
    expect(featherForFinger({ x: 10, y: 500 })).toEqual({ x: 10, y: 500 - FEATHER_LIFT_PT });
    expect(inZone({ x: 200, y: 300 }, zone)).toBe(true);
    expect(inZone({ x: 200, y: 700 }, zone)).toBe(false);
    for (let i = 0; i < 6; i++) expect(inZone(floatPoint(zone, i), zone)).toBe(true);
    expect(dodgeButtonMove(1234.4)).toEqual({ t: 1234, away: true, in_zone: true, speed_ok: true });
  });
});
