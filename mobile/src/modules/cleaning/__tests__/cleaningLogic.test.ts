/**
 * GAMES_SPEC §6.2 — the pure logic of the cleaning games: deterministic poop pieces, stages, wipe
 * coverage (scratch card ~90 %), scoop / drag hit-testing, the shake, the first-tip memory and the
 * line length of every new game text.
 */
import { i18n } from '@/i18n';
import { CLEANING_TIPS_KEY, hasSeenTip, markTipSeen, setTipStoreForTests, tipKindFor, type TipStore } from '@/modules/cleaning/cleaningTips';
import { allDone, countBits, hasBit, isShakeUp, pieceUnder, scoopPicks, segmentDistance2, within } from '@/modules/cleaning/hits';
import { FLOOR_Y, MAX_POOP_PIECES, messStages, piecePlacements, poopPieceCount, stageDots } from '@/modules/cleaning/messPlan';
import { clumpCount, clumpInTray } from '@/modules/cleaning/scoop';
import {
  AUTO_WIPE_RADIUS,
  AUTO_WIPE_ROWS,
  AUTO_WIPE_STEPS_PER_ROW,
  WIPE_DONE,
  autoWipeStroke,
  createWipeGrid,
  isWiped,
  wipeAt,
  wipeCoverage,
  wipeSegment,
  wipeStep,
} from '@/modules/cleaning/wipe';
import { GAME_LINE_MAX_CHARS } from '@/modules/gameLayer/gameLine';
import { resources } from '@/i18n/resources';

const ev = (id: number, kind: 'poop' | 'accident' | 'chewing' | 'litter_accident' | 'scratching') => ({ id, kind });

describe('poop pieces (deterministic per event)', () => {
  it('is 1–3 and always the same for the same event id', () => {
    const counts = new Set<number>();
    for (let id = 1; id <= 200; id++) {
      const n = poopPieceCount(id);
      expect(n).toBeGreaterThanOrEqual(1);
      expect(n).toBeLessThanOrEqual(3);
      expect(poopPieceCount(id)).toBe(n);
      counts.add(n);
    }
    // Every count actually occurs (not always one swirl).
    expect([...counts].sort()).toEqual([1, 2, 3]);
  });

  it('places the pieces on the floor, stable per seed and spread out', () => {
    const a = piecePlacements(3, 42);
    expect(piecePlacements(3, 42)).toEqual(a);
    expect(a).toHaveLength(3);
    for (const p of a) {
      expect(Math.abs(p.y - FLOOR_Y)).toBeLessThanOrEqual(0.03 + 1e-9);
      expect(p.scale).toBeGreaterThanOrEqual(0.9);
      expect(p.scale).toBeLessThanOrEqual(1.1);
    }
    expect(a[0].x).toBeLessThan(a[1].x);
    expect(a[1].x).toBeLessThan(a[2].x);
    expect(piecePlacements(9, 1)).toHaveLength(MAX_POOP_PIECES);
  });
});

describe('messStages', () => {
  it('poop first, then the puddle; chewing / scratching are never scrubbed', () => {
    const stages = messStages([ev(7, 'accident'), ev(3, 'poop'), ev(9, 'chewing'), ev(11, 'scratching')]);
    expect(stages.map((s) => s.kind)).toEqual(['poop', 'puddle']);
    expect(stages[0]).toEqual({ kind: 'poop', pieces: poopPieceCount(3), seed: 3 });
    expect(stages[1]).toEqual({ kind: 'puddle', pieces: 1, seed: 7 });
  });

  it('caps the poop pieces at 3 and bags a cat mess next to the tray', () => {
    const many = messStages([ev(1, 'poop'), ev(2, 'poop'), ev(5, 'litter_accident')]);
    expect(many).toHaveLength(1);
    expect(many[0].pieces).toBeLessThanOrEqual(3);
    expect(messStages([ev(4, 'litter_accident')])[0].kind).toBe('poop');
  });

  it('without event info (older server) → one poop stage, never empty', () => {
    expect(messStages([])).toEqual([{ kind: 'poop', pieces: poopPieceCount(0), seed: 0 }]);
    expect(messStages([ev(1, 'chewing')])).toHaveLength(1);
  });

  it('one dot per piece of every stage', () => {
    const stages = [
      { kind: 'poop' as const, pieces: 2, seed: 1 },
      { kind: 'puddle' as const, pieces: 1, seed: 2 },
    ];
    expect(stageDots(stages, 0, 1)).toEqual([true, false, false]);
    expect(stageDots(stages, 1, 0)).toEqual([true, true, false]);
    expect(stageDots(stages, 2, 0)).toEqual([true, true, true]);
  });
});

describe('wipe coverage (scratch card)', () => {
  it('starts at 0 and grows only inside the puddle ellipse', () => {
    const g = createWipeGrid();
    expect(wipeCoverage(g)).toBe(0);
    // A corner of the box is outside the ellipse → nothing to wipe there.
    expect(wipeAt(g, 0.01, 0.01, 0.02, 0.02)).toBe(g);
    const once = wipeAt(g, 0.5, 0.5, 0.1, 0.2);
    expect(wipeCoverage(once)).toBeGreaterThan(0);
    // Wiping the same spot again changes nothing (same object).
    expect(wipeAt(once, 0.5, 0.5, 0.1, 0.2)).toBe(once);
  });

  it('a fast stroke leaves no gaps between frames', () => {
    const g = createWipeGrid();
    const segment = wipeSegment(g, 0.05, 0.5, 0.95, 0.5, 0.06, 0.12);
    const points = wipeAt(wipeAt(g, 0.05, 0.5, 0.06, 0.12), 0.95, 0.5, 0.06, 0.12);
    expect(segment.wipedCount).toBeGreaterThan(points.wipedCount * 3);
  });

  it('is clean at ~90 % and reports progress in steps of 10 %', () => {
    expect(WIPE_DONE).toBe(0.9);
    let g = createWipeGrid();
    for (let v = 0; v <= 1; v += 0.1) g = wipeSegment(g, 0, v, 1, v, 0.08, 0.12);
    expect(isWiped(g)).toBe(true);
    expect(wipeStep(g)).toBeGreaterThanOrEqual(9);
    expect(wipeStep(createWipeGrid())).toBe(0);
  });

  it('the hold-mode auto wipe cleans the whole puddle within one pass of rows', () => {
    let g = createWipeGrid();
    const pass = AUTO_WIPE_ROWS * AUTO_WIPE_STEPS_PER_ROW;
    let steps = 0;
    while (!isWiped(g) && steps < pass) {
      const [u0, v0, u1, v1] = autoWipeStroke(steps++);
      g = wipeSegment(g, u0, v0, u1, v1, AUTO_WIPE_RADIUS.u, AUTO_WIPE_RADIUS.v);
    }
    expect(isWiped(g)).toBe(true);
    expect(steps).toBeLessThanOrEqual(pass);
    // Strokes stay inside the box and zig-zag.
    expect(autoWipeStroke(0)[0]).toBeLessThan(autoWipeStroke(0)[2]);
    expect(autoWipeStroke(AUTO_WIPE_STEPS_PER_ROW)[0]).toBeGreaterThan(autoWipeStroke(AUTO_WIPE_STEPS_PER_ROW)[2]);
    expect(autoWipeStroke(pass)).toEqual(autoWipeStroke(0));
  });
});

describe('hit-testing (worklets as plain functions)', () => {
  const xs = [100, 200, 300];
  const ys = [500, 500, 500];

  it('pieceUnder: the nearest open piece within the radius', () => {
    expect(pieceUnder(xs, ys, 0, 190, 505, 50)).toBe(1);
    expect(pieceUnder(xs, ys, 0b010, 190, 505, 50)).toBe(-1);
    expect(pieceUnder(xs, ys, 0b010, 140, 500, 50)).toBe(0);
    expect(pieceUnder(xs, ys, 0, 150, 300, 50)).toBe(-1);
  });

  it('scoopPicks: a fast drag through the sand picks every clump it passed over', () => {
    expect(scoopPicks(xs, ys, 0, 50, 500, 350, 505, 20)).toBe(0b111);
    expect(scoopPicks(xs, ys, 0b001, 50, 500, 350, 505, 20)).toBe(0b110);
    expect(scoopPicks(xs, ys, 0, 50, 400, 350, 400, 20)).toBe(0);
    expect(segmentDistance2(0, 0, 10, 0, 5, 3)).toBe(9);
    expect(segmentDistance2(0, 0, 0, 0, 3, 4)).toBe(25);
  });

  it('isShakeUp: a quick flick up shakes, a slow drag or a move down does not', () => {
    expect(isShakeUp(-900, -40)).toBe(true);
    expect(isShakeUp(-200, -150)).toBe(true);
    expect(isShakeUp(-100, -150)).toBe(false);
    expect(isShakeUp(-300, -60)).toBe(false);
    expect(isShakeUp(900, 200)).toBe(false);
  });

  it('masks and distances', () => {
    expect(within(0, 0, 3, 4, 5)).toBe(true);
    expect(within(0, 0, 3, 4, 4.9)).toBe(false);
    expect(hasBit(0b100, 2)).toBe(true);
    expect(allDone(0b111, 3)).toBe(true);
    expect(allDone(0b101, 3)).toBe(false);
    expect(countBits(0b1011)).toBe(3);
  });
});

describe('litter clumps', () => {
  it('2 per open use, 2–6, inside the sand of the tray drawing', () => {
    expect([0, 1, 2, 3, 4].map(clumpCount)).toEqual([2, 2, 4, 6, 6]);
    for (let i = 0; i < 6; i++) {
      const p = clumpInTray(i);
      expect(p.x * 160).toBeGreaterThanOrEqual(26);
      expect(p.x * 160).toBeLessThanOrEqual(134);
      expect(p.y * 90).toBeGreaterThanOrEqual(38);
      expect(p.y * 90).toBeLessThanOrEqual(70);
    }
  });
});

describe('first-tip memory', () => {
  function memoryStore(initial: string | null = null): TipStore & { data: Map<string, string> } {
    const data = new Map<string, string>();
    if (initial !== null) data.set(CLEANING_TIPS_KEY, initial);
    return {
      data,
      getItemAsync: async (k) => data.get(k) ?? null,
      setItemAsync: async (k, v) => {
        data.set(k, v);
      },
    };
  }
  afterEach(() => setTipStoreForTests(null));

  it('remembers each kind once', async () => {
    const store = memoryStore();
    setTipStoreForTests(store);
    expect(await hasSeenTip('poop')).toBe(false);
    await markTipSeen('poop');
    expect(await hasSeenTip('poop')).toBe(true);
    expect(await hasSeenTip('puddle')).toBe(false);
    await markTipSeen('poop');
    expect(JSON.parse(store.data.get(CLEANING_TIPS_KEY) ?? '[]')).toEqual(['poop']);
  });

  it('an unreadable / broken store counts as seen and never throws', async () => {
    setTipStoreForTests({
      getItemAsync: () => Promise.reject(new Error('keychain')),
      setItemAsync: () => Promise.reject(new Error('keychain')),
    });
    expect(await hasSeenTip('puddle')).toBe(true);
    await expect(markTipSeen('puddle')).resolves.toBeUndefined();
    setTipStoreForTests(memoryStore('not json'));
    expect(await hasSeenTip('poop')).toBe(false);
  });

  it('dogs only; the puddle tip follows its switch', () => {
    expect(tipKindFor('poop', 'dog')).toBe('poop');
    expect(tipKindFor('puddle', 'dog')).toBe('puddle');
    expect(tipKindFor('puddle', 'dog', false)).toBeNull();
    expect(tipKindFor('poop', 'cat')).toBeNull();
    expect(tipKindFor('poop', null)).toBe('poop');
  });
});

describe('texts', () => {
  type Tree = { [k: string]: string | Tree };
  const lines = (lang: 'sl' | 'en') => {
    const child = (resources[lang].child as unknown as { messGame: Tree }).messGame;
    const cat = ((resources[lang].cat as unknown as { scoop: Tree }).scoop.game) as Tree;
    const pick = (t: Tree, keys: string[]) => keys.map((k) => t[k] as string);
    return [
      ...['poop', 'puddle', 'slipper'].flatMap((k) => pick(child[k] as Tree, ['line', 'holdLine', ...(k === 'slipper' ? ['toyLine'] : [])])),
      ...Object.values(child.fb as Tree).map(String),
      ...pick(cat, ['line', 'shakeLine', 'holdLine', 'fb']),
    ];
  };

  it.each(['sl', 'en'] as const)('every game line fits the one line (%s, ≤ 32 chars)', (lang) => {
    for (const line of lines(lang)) expect(line.length).toBeLessThanOrEqual(GAME_LINE_MAX_CHARS);
  });

  it('the tips say what David approved', async () => {
    await i18n.changeLanguage('sl');
    const sl = (resources.sl.child as unknown as { messGame: { tips: Record<string, string> } }).messGame.tips;
    const en = (resources.en.child as unknown as { messGame: { tips: Record<string, string> } }).messGame.tips;
    expect(sl).toEqual({ poop: 'Na sprehodu vedno pobereš z vrečko.', puddle: 'Ne kregaj ga — pelji ga pogosteje ven.' });
    expect(en).toEqual({ poop: 'On walks you always pick it up with a bag.', puddle: "Don't scold — take it out more often." });
  });
});
