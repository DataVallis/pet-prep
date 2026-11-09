/** GAMES_SPEC §5.2 — where the wand hunt draws the feather (its moves are not scored since 2026-11-08). */
import { featherForFinger, FEATHER_LIFT_PT, floatPoint, wandZone } from '@/modules/catCare/wandV2/wandStroke';

const SIZE = { width: 400, height: 800 };
const zone = wandZone(SIZE);

describe('wand feather geometry', () => {
  it('the feather floats above the finger; the float path stays in the upper part where the cat looks', () => {
    expect(featherForFinger({ x: 10, y: 500 })).toEqual({ x: 10, y: 500 - FEATHER_LIFT_PT });
    expect(zone.x).toBeCloseTo(28);
    expect(zone.y).toBeCloseTo(120);
    expect(zone.width).toBeCloseTo(344);
    expect(zone.height).toBeCloseTo(360);
    for (let i = 0; i < 6; i++) {
      const p = floatPoint(zone, i);
      expect(p.x).toBeGreaterThanOrEqual(zone.x);
      expect(p.x).toBeLessThanOrEqual(zone.x + zone.width);
      expect(p.y).toBeGreaterThanOrEqual(zone.y);
      expect(p.y).toBeLessThanOrEqual(zone.y + zone.height);
    }
    expect(floatPoint(zone, 6)).toEqual(floatPoint(zone, 0));
  });
});
