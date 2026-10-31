/** GAMES_SPEC §5.2 / §8.1 — the wand v2 stage plan: the crouch hub, its fallback, older sets, »Slika«. */
import { wandDeck, wandHubKey, wandPounceClipMs } from '@/modules/catCare/wandV2/wandDeck';
import { normalizePetMedia } from '@/modules/petMedia/petMedia';
import { makeMedia } from '@/test-utils/fixtures';

const U = {
  ref: 'https://x/ref.png',
  crouch: 'https://x/crouch.png',
  catch: 'https://x/catch.png',
  stalking: 'https://x/wand_stalking.mp4',
  loop: 'https://x/wand_crouch_loop.mp4',
  pouncing: 'https://x/wand_pouncing.mp4',
  catching: 'https://x/wand_catching.mp4',
  idle: 'https://x/idle.mp4',
};

function media(phases: Record<string, string>, groups: ('wand' | 'ball' | 'school')[] = ['wand']) {
  return normalizePetMedia(
    makeMedia({
      status: 'ready',
      reference_image_url: U.ref,
      videos: { idle: U.idle },
      poses: { crouch: U.crouch, catch: U.catch },
      phases,
      phase_groups: groups,
      phase_seconds: { wand_pouncing: 4, wand_crouch_loop: 2 },
    }),
  );
}

const FULL = { wand_stalking: U.stalking, wand_crouch_loop: U.loop, wand_pouncing: U.pouncing, wand_catching: U.catching };

describe('wandDeck', () => {
  it('reads the server-derived crouch loop and plays stalk → loop ⇄ pounce → catch', () => {
    const m = media(FULL);
    expect(m.phases.wand_crouch_loop).toBe(U.loop);
    expect(wandHubKey(m)).toBe('wand_crouch_loop');
    const plan = wandDeck(m, 'video');
    expect(plan.initial).toBe('stalking');
    expect(plan.clips.map((c) => [c.role, c.url, c.loop])).toEqual([
      ['stalking', U.stalking, false],
      ['crouch', U.loop, true],
      ['pounce', U.pouncing, false],
      ['catching', U.catching, false],
    ]);
    expect(plan.specs.find((s) => s.role === 'pounce')).toMatchObject({ startPose: 'crouch', endPose: 'crouch', durationMs: 4000 });
    expect(plan.specs.find((s) => s.role === 'catching')).toMatchObject({ startPose: 'crouch', endPose: 'catch' });
    expect(wandPounceClipMs(m)).toBe(4000);
  });

  it('without the derived loop the stalk clip loops as the hub (no separate stalk role)', () => {
    const plan = wandDeck(media({ wand_stalking: U.stalking, wand_pouncing: U.pouncing, wand_catching: U.catching }), 'video');
    expect(plan.initial).toBe('crouch');
    expect(plan.clips.map((c) => [c.role, c.url, c.loop])).toEqual([
      ['crouch', U.stalking, true],
      ['pounce', U.pouncing, false],
      ['catching', U.catching, false],
    ]);
  });

  it('an older set (no wand phase group) loops the state video for every role', () => {
    const plan = wandDeck(media({}, []), 'video');
    expect(new Set(plan.clips.map((c) => c.url))).toEqual(new Set([U.idle]));
    expect(plan.specs.every((s) => s.startPose === 'stand' && s.endPose === 'stand')).toBe(true);
  });

  it('»Slika«: no clip at all, the poses instead', () => {
    const plan = wandDeck(media(FULL), 'picture');
    expect(plan.clips.every((c) => c.url === null)).toBe(true);
    expect(plan.clips.map((c) => c.picture)).toEqual([U.ref, U.crouch, U.crouch, U.catch]);
    expect(plan.specs.every((s) => s.endGraceMs === 0)).toBe(true);
  });
});
