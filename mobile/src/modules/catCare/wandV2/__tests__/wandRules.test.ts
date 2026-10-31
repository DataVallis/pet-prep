/**
 * GAMES_SPEC §5.2–§5.4 — the wand v2 mirror scorer, the live verdicts, the cue timeline, the
 * session reader and the finger → move conversion. The scorer cases are the server's
 * (`backend/tests/Feature/WandRulesV2Test.php`) one by one; the schedule generator below mirrors
 * `WandPlayService::scheduleV2` with a seeded PRNG for the slow-mover / fair-stopper sweeps.
 */
import { i18n } from '@/i18n';
import { GAME_LINE_MAX_CHARS } from '@/modules/gameLayer/gameLine';
import {
  addWandV2Move,
  readWandV2Result,
  readWandV2Session,
  scoreWandV2,
  wandCues,
  wandDots,
  wandLive,
  wandNextBoundary,
  wandStageAt,
  wandV2FinishAt,
  type WandCue,
  type WandLive,
  type WandV2Move,
  type WandV2Session,
} from '@/modules/catCare/wandV2/wandRules';
import { wandV2LineTexts } from '@/modules/catCare/wandV2/wandStrings';

const BASE = {
  id: '9b1f0c1e-4c1a-4b8e-9d1e-0f2a3b4c5d6e',
  started_at: '2026-10-31T10:00:00Z',
  ends_at: '2026-10-31T10:00:26Z',
  expires_at: '2026-10-31T10:01:26Z',
};

/** `wv2Schedule()` of the server test. */
function wv2Schedule(over: Partial<WandV2Session> = {}): WandV2Session {
  return {
    ...BASE,
    rules_version: 2,
    duration_ms: 26000,
    stalk_ms: 3000,
    pounces_ms: [7000, 13000, 19000],
    crouch_cues_ms: [5500, 11500, 17500],
    catch_at_ms: 22500,
    catch_window_ms: 1500,
    calm_window_ms: 1000,
    calm_max_moves: 0,
    dodge_window_ms: [150, 1500],
    min_move_interval_ms: 300,
    min_stars: 2,
    max_stars: 4,
    max_speed_pt_s: 4000,
    min_dodge_pt: 40,
    ...over,
  };
}

function mv(t: number, away = true, inZone = true, speedOk = true): WandV2Move {
  return { t, away, in_zone: inZone, speed_ok: speedOk };
}

/** `wv2FairMoves()` of the server test. */
function fairMoves(s: WandV2Session): WandV2Move[] {
  const moves: WandV2Move[] = [];
  const jitter = [0, 410, 170, 620, 90, 350];
  const quiet = s.pounces_ms.map((p) => [p - 1600, p + 1600]);
  let t = 900;
  let k = 0;
  while (t < s.catch_at_ms - 400) {
    const calm = quiet.some(([a, b]) => t >= a && t < b);
    if (!calm) moves.push(mv(t, k % 3 !== 2));
    t += 1300 + jitter[k++ % jitter.length];
  }
  s.pounces_ms.forEach((p, i) => moves.push(mv(p + [420, 610, 330][i % 3])));
  return moves;
}

/** `wv2SpamMoves()` of the server test. */
function spamMoves(durationMs: number): WandV2Move[] {
  const moves: WandV2Move[] = [];
  const gaps = [310, 380, 330, 420, 300, 360, 345];
  for (let t = 200, k = 0; t <= durationMs; t += gaps[k++ % gaps.length]) moves.push(mv(t));
  return moves;
}

/** Seeded PRNG (mulberry32) with PHP's `Randomizer::getInt(min, max)` shape (inclusive). */
function rng(seed: number) {
  let a = seed >>> 0;
  const next = () => {
    a = (a + 0x6d2b79f5) >>> 0;
    let x = a;
    x = Math.imul(x ^ (x >>> 15), x | 1);
    x ^= x + Math.imul(x ^ (x >>> 7), x | 61);
    return ((x ^ (x >>> 14)) >>> 0) / 4294967296;
  };
  return { getInt: (lo: number, hi: number) => lo + Math.floor(next() * (hi - lo + 1)) };
}

/** Mirror of `WandPlayService::scheduleV2` with the decided config (`config/wand.php` → `v2`). */
function scheduleV2(r: ReturnType<typeof rng>): WandV2Session {
  const pounces: number[] = [];
  let p = Math.max(3000 + 1500, r.getInt(6000, 7500));
  for (let i = 0; i < 3; i++) {
    pounces.push(p);
    p += r.getInt(5000, 6500);
  }
  const catchAt = pounces[2] + 3500;
  const duration = Math.min(30000, catchAt + 3500);
  return wv2Schedule({ duration_ms: duration, pounces_ms: pounces, crouch_cues_ms: pounces.map((x) => x - 1500), catch_at_ms: Math.min(catchAt, duration) });
}

/** `wv2SlowMover()`: one away stroke every 0.8–1.2 s for the whole hunt. */
function slowMover(s: WandV2Session, r: ReturnType<typeof rng>): WandV2Move[] {
  const moves: WandV2Move[] = [];
  for (let t = r.getInt(0, 800); t <= s.duration_ms; t += r.getInt(800, 1200)) moves.push(mv(t));
  return moves;
}

/** `wv2FairStopper()`: keeps moving, stops 300–450 ms after the cue, dodges 350–650 ms after p. */
function fairStopper(s: WandV2Session, r: ReturnType<typeof rng>): WandV2Move[] {
  const moves: WandV2Move[] = [];
  let from = 500;
  s.pounces_ms.forEach((p, i) => {
    const cue = s.crouch_cues_ms[i];
    const stopAt = cue + r.getInt(300, 450);
    for (let t = from, k = 0; t < cue; t += r.getInt(450, 600), k++) moves.push(mv(t, k % 2 === 0));
    moves.push(mv(stopAt, false));
    moves.push(mv(p + r.getInt(350, 650)));
    from = p + 1600;
  });
  for (let t = from, k = 0; t < s.catch_at_ms; t += r.getInt(450, 600), k++) moves.push(mv(t, k % 2 === 0));
  moves.push(mv(s.catch_at_ms + r.getInt(300, 450), false));
  return moves;
}

describe('scoreWandV2 — the server cases (WandRulesV2Test)', () => {
  it('passes a fair, human-like player with all four stars', () => {
    const s = wv2Schedule();
    const r = scoreWandV2(fairMoves(s), s);
    expect(r).toMatchObject({ success: true, reason: null, stars: 4, hint: null, catch: { star: true, reason: null } });
    expect(r.dodges.map((d) => d.star)).toEqual([true, true, true]);
  });

  it('gives continuous rapid away strokes 0 stars (the spam exploit), also faster bursts', () => {
    const s = wv2Schedule();
    const r = scoreWandV2(spamMoves(s.duration_ms), s);
    expect(r).toMatchObject({ success: false, reason: 'too_few_stars', stars: 0, hint: 'calm', catch: { star: false, reason: 'pulled_away' } });
    expect(new Set(r.dodges.map((d) => d.reason))).toEqual(new Set(['moved_during_crouch']));
    const fast: WandV2Move[] = [];
    for (let t = 0; t <= s.duration_ms; t += 120) fast.push(mv(t));
    expect(scoreWandV2(fast, s).stars).toBe(0);
  });

  it('rewards only the first pull-away 150–1500 ms after the pounce, in the zone, as a clean stroke', () => {
    const s = wv2Schedule({ pounces_ms: [7000], crouch_cues_ms: [5500] });
    const dodge = (m: WandV2Move | { t: number; away: boolean }) => scoreWandV2([m as WandV2Move], s).dodges[0];
    expect(dodge(mv(7400))).toEqual({ pounce_ms: 7000, star: true, reason: null, move_ms: 7400 });
    expect(dodge(mv(7149)).reason).toBe('too_early');
    expect(dodge(mv(7150)).star).toBe(true);
    expect(dodge(mv(8500)).star).toBe(true);
    expect(dodge(mv(8501)).reason).toBe('no_dodge');
    expect(dodge(mv(7400, false)).reason).toBe('no_dodge');
    expect(dodge(mv(7400, true, false)).reason).toBe('out_of_zone');
    expect(dodge(mv(7400, true, true, false)).reason).toBe('bad_stroke');
    // A move without the v2 fields fails closed (the server reads a missing field as false).
    expect(dodge({ t: 7400, away: true, in_zone: false, speed_ok: false } as WandV2Move).reason).toBe('out_of_zone');
    expect(scoreWandV2([mv(7300, true, false), mv(7900)], s).dodges[0].reason).toBe('out_of_zone');
  });

  it('allows no move in the last 1000 ms before the pounce (any direction); moves before it are fine', () => {
    const s = wv2Schedule({ pounces_ms: [7000], crouch_cues_ms: [5500] });
    expect(scoreWandV2([mv(6500, false), mv(7400)], s).dodges[0].reason).toBe('moved_during_crouch');
    expect(scoreWandV2([mv(6000, false), mv(7400)], s).dodges[0].reason).toBe('moved_during_crouch');
    expect(scoreWandV2([mv(6999), mv(7400)], s).dodges[0].reason).toBe('moved_during_crouch');
    expect(scoreWandV2([mv(5500), mv(5999, false), mv(7400)], s).dodges[0].star).toBe(true);
    const tolerant = { ...s, calm_max_moves: 1 };
    expect(scoreWandV2([mv(6500, false), mv(7400)], tolerant).dodges[0].star).toBe(true);
    expect(scoreWandV2([mv(6300, false), mv(6700, false), mv(7400)], tolerant).dodges[0].reason).toBe('moved_during_crouch');
  });

  it('counts the hunt at two stars and names the first missing rule as the one hint', () => {
    const s = wv2Schedule();
    expect(scoreWandV2([mv(7400)], s)).toMatchObject({ stars: 2, success: true, hint: 'dodge' });
    expect(scoreWandV2([], s)).toMatchObject({ stars: 1, success: false, hint: 'dodge' });
    expect(scoreWandV2([mv(23000)], s).catch).toEqual({ star: false, reason: 'pulled_away' });
    expect(scoreWandV2([mv(21000, false, false)], s).catch).toEqual({ star: false, reason: 'out_of_zone' });
    expect(scoreWandV2([mv(22800, false)], s).catch.star).toBe(true);
  });

  it('keeps a real dodge right after a calm move (QA #461 m2) and a pull-away at the catch', () => {
    const s = wv2Schedule({ pounces_ms: [7000], crouch_cues_ms: [5500], calm_max_moves: 1 });
    expect(scoreWandV2([mv(6900, false), mv(7180)], s).dodges[0]).toEqual({ pounce_ms: 7000, star: true, reason: null, move_ms: 7180 });
    expect(scoreWandV2([mv(22400, false), mv(22600)], s).catch.reason).toBe('pulled_away');
  });
});

describe('the strict calm rule over many hunts (David 2026-10-31)', () => {
  it('fails a steady slow mover (a stroke every 0.8–1.2 s) in every hunt', () => {
    let passed = 0;
    for (let i = 0; i < 200; i++) {
      const r = rng(1000 + i);
      const s = scheduleV2(r);
      if (scoreWandV2(slowMover(s, r), s).success) passed++;
    }
    expect(passed).toBe(0);
  });

  it('passes a fair child who stops mid-stroke after the cue with every star', () => {
    for (let i = 0; i < 100; i++) {
      const r = rng(5000 + i);
      const s = scheduleV2(r);
      expect(scoreWandV2(fairStopper(s, r), s).stars).toBe(4);
    }
  });
});

describe('wandLive — instant verdicts', () => {
  const s = wv2Schedule();

  it('walks the hunt: upcoming → cue → open → done, and the done verdicts equal the scorer', () => {
    const moves = fairMoves(s);
    expect(wandLive(s, moves, 5000).pounces[0]).toEqual({ state: 'upcoming' });
    expect(wandLive(s, moves, 6000).pounces[0]).toMatchObject({ state: 'cue', calmBroken: false });
    expect(wandLive(s, moves, 7200).pounces[0]).toEqual({ state: 'open' });
    expect(wandLive(s, moves, 7420).pounces[0]).toEqual({ state: 'done', star: true, reason: null });
    const end = wandLive(s, moves, s.duration_ms);
    const score = scoreWandV2(moves, s);
    expect(end.stars).toBe(score.stars);
    end.pounces.forEach((p, i) => expect(p).toEqual({ state: 'done', star: score.dodges[i].star, reason: score.dodges[i].reason }));
    expect(end.catch).toEqual({ state: 'done', ...score.catch });
    expect(wandDots(end)).toEqual([true, true, true, true]);
  });

  it('decides a calm violation at the pounce, a missing dodge after the window, a pull-away at once', () => {
    const moved = [mv(6600, false)];
    expect(wandLive(s, moved, 6700).pounces[0]).toMatchObject({ state: 'cue', calmBroken: true });
    expect(wandLive(s, moved, 7000).pounces[0]).toEqual({ state: 'done', star: false, reason: 'moved_during_crouch' });
    expect(wandLive(s, [], 8500).pounces[0]).toEqual({ state: 'open' });
    expect(wandLive(s, [], 8501).pounces[0]).toEqual({ state: 'done', star: false, reason: 'no_dodge' });
    expect(wandLive(s, [mv(22700)], 22700).catch).toEqual({ state: 'done', star: false, reason: 'pulled_away' });
    expect(wandLive(s, [], 23000).catch).toEqual({ state: 'open' });
    expect(wandLive(s, [], 24001).catch).toEqual({ state: 'done', star: true, reason: null });
  });

  it('ignores moves after `now`', () => {
    expect(wandLive(s, [mv(6600)], 6500).pounces[0]).toMatchObject({ state: 'cue', calmBroken: false });
  });
});

describe('the cue timeline', () => {
  const s = wv2Schedule();

  function cuesOver(moves: WandV2Move[], step = 50): { at: number; cue: WandCue }[] {
    const out: { at: number; cue: WandCue }[] = [];
    let prev: WandLive | null = null;
    for (let t = 0; t <= s.duration_ms; t += step) {
      const live = wandLive(s, moves, t);
      for (const cue of wandCues(prev, live)) out.push({ at: t, cue });
      prev = live;
    }
    return out;
  }

  it('cues 👀 at each crouch cue, the pounce at p, ★ at the dodge, the catch cue and the caught star', () => {
    const cues = cuesOver(fairMoves(s));
    const kinds = cues.map((c) => `${c.cue.kind}@${c.at}`);
    expect(kinds).toEqual([
      'crouch@5500',
      'pounce@7000',
      'dodged@7450',
      'crouch@11500',
      'pounce@13000',
      'dodged@13650',
      'crouch@17500',
      'pounce@19000',
      'dodged@19350',
      'catchCue@22500',
      'caught@24050',
    ]);
  });

  it('a miss has no cue but the picture (grabbed); a calm break is flagged once', () => {
    const cues = cuesOver([mv(6600, false), mv(6700, false)]).map((c) => c.cue.kind);
    expect(cues.filter((k) => k === 'calmBroken')).toHaveLength(1);
    expect(cues).toContain('grabbed');
    expect(cues).not.toContain('dodged');
  });

  it('the stage: stalk → crouch → pounce for its stored length → crouch → catch', () => {
    expect(wandStageAt(s, 0, 3000)).toBe('stalking');
    expect(wandStageAt(s, 3000, 3000)).toBe('crouch');
    expect(wandStageAt(s, 7000, 3000)).toBe('pounce');
    expect(wandStageAt(s, 9999, 3000)).toBe('pounce');
    expect(wandStageAt(s, 10000, 3000)).toBe('crouch');
    expect(wandStageAt(s, 22500, 3000)).toBe('catching');
    expect(wandNextBoundary(s, 0, 3000)).toBe(3000);
    expect(wandNextBoundary(s, 5500, 3000)).toBe(7000);
    expect(wandNextBoundary(s, 7000, 3000)).toBe(7150);
    expect(wandNextBoundary(s, s.duration_ms, 3000)).toBeNull();
    expect(wandV2FinishAt(s)).toBe(26000);
  });
});

describe('readers', () => {
  const raw = { ...wv2Schedule(), dodge_window_ms: [150, 1500] };

  it('reads a v2 start session with every timing; refuses one with a missing or broken timing', () => {
    expect(readWandV2Session(raw)).toEqual(wv2Schedule());
    for (const key of ['crouch_cues_ms', 'calm_window_ms', 'calm_max_moves', 'dodge_window_ms', 'catch_window_ms', 'max_speed_pt_s', 'min_dodge_pt', 'stalk_ms']) {
      const broken: Record<string, unknown> = { ...raw };
      delete broken[key];
      expect(readWandV2Session(broken)).toBeNull();
    }
    expect(readWandV2Session({ ...raw, rules_version: undefined })).toBeNull();
    expect(readWandV2Session({ ...raw, crouch_cues_ms: [5500, 11500] })).toBeNull();
    expect(readWandV2Session({ ...raw, crouch_cues_ms: [7500, 11500, 17500] })).toBeNull();
    // A different (config) calm rule is taken as sent.
    expect(readWandV2Session({ ...raw, calm_window_ms: 800, calm_max_moves: 1, crouch_cues_ms: [6100, 12100, 18100] })).toMatchObject({ calm_window_ms: 800, calm_max_moves: 1 });
  });

  it('reads a v2 result; a v1 result is not one', () => {
    const result = { session_id: BASE.id, rules_version: 2, ...scoreWandV2([mv(7400)], wv2Schedule()) };
    expect(readWandV2Result(result)).toEqual({ ...result, rules_version: 2 });
    expect(readWandV2Result({ session_id: BASE.id, success: true, reason: null, away_moves: 9 })).toBeNull();
  });

  it('adds moves inside the hunt only, at most 600', () => {
    expect(addWandV2Move([], mv(-1), 1000)).toEqual([]);
    expect(addWandV2Move([], mv(1001), 1000)).toEqual([]);
    expect(addWandV2Move([], mv(10.4), 1000)).toEqual([mv(10)]);
    const full = Array.from({ length: 600 }, (_, i) => mv(i));
    expect(addWandV2Move(full, mv(700), 1000)).toBe(full);
  });
});

describe('texts', () => {
  it.each(['sl', 'en'])('every game-line text fits the line (%s)', async (lang) => {
    await i18n.changeLanguage(lang);
    for (const text of wandV2LineTexts()) expect(Array.from(text).length).toBeLessThanOrEqual(GAME_LINE_MAX_CHARS);
  });
});
