/**
 * M5-R05 play & cuddle — pure logic: payload readers, eligibility display ("Igra" hidden /
 * disabled with a reason / invitation), the 30-minute mood timer and its priority, the
 * optimistic state, API result parsing, broadcasts, the mini-game gesture rules and the
 * parent texts.
 */
import { i18n } from '@/i18n';
import {
  applyBroadcast,
  nextRefreshDelay,
  normalizeChildState,
  type ChildPetView,
} from '@/modules/childPet/childPetView';
import {
  ballFetched,
  BALL_START,
  BALL_THROWS,
  broadcastPlay,
  HAPPY_MINUTES,
  isBallDone,
  isHappyAt,
  isStroke,
  isThrowRelease,
  moodSceneAt,
  nextPlayChangeMs,
  optimisticPlay,
  playBlock,
  playBlockText,
  playEntry,
  playRefusalMessage,
  playTimelineText,
  playTodayLine,
  readChildPlay,
  readPlayResponse,
  readPlayToday,
  STROKE_START,
  throwBall,
  trackStroke,
  visibleInvitation,
} from '@/modules/play/play';
import { activityText } from '@/modules/family/scoring';
import { videoChain, videoStateFor } from '@/modules/petMedia/petMedia';
import { makeBroadcast, makeLiveChildState, makePlayState } from '@/test-utils/fixtures';
import type { ChildPetState } from '@/api/client';

const NOW = Date.parse('2026-10-04T12:00:00+02:00');
const at = (iso: string) => Date.parse(iso);

function viewWith(play: unknown, pet: Partial<ChildPetState['pet']> = {}): ChildPetView {
  return normalizeChildState(makeLiveChildState({ play, pet }), 0, NOW);
}

const HAPPY = { happy_until: '2026-10-04T12:20:00+02:00', scene: 'playing' as const };
const INVITE = { id: 12, kind: 'play' as const, expires_at: '2026-10-04T13:30:00+02:00' };

describe('readChildPlay', () => {
  it('null / missing / malformed → null (no play, older server)', () => {
    expect(readChildPlay(null)).toBeNull();
    expect(readChildPlay(undefined)).toBeNull();
    expect(readChildPlay('yes')).toBeNull();
    expect(readChildPlay([])).toBeNull();
  });

  it('reads the full payload', () => {
    expect(readChildPlay(makePlayState({ invitation: INVITE, mood: HAPPY }))).toEqual({
      can_play: true,
      invitation: INVITE,
      mood: HAPPY,
    });
  });

  it('drops a malformed invitation and a scene without happy_until; can_play must be true', () => {
    const play = readChildPlay({
      can_play: 'true',
      invitation: { id: 'x', kind: 'fetch', expires_at: 'soon' },
      mood: { happy_until: null, scene: 'playing' },
    });
    expect(play).toEqual({ can_play: false, invitation: null, mood: { happy_until: null, scene: null } });
    expect(readChildPlay({ can_play: true, invitation: { id: '5', kind: 'cuddle', expires_at: INVITE.expires_at } })?.invitation).toEqual({
      id: 5,
      kind: 'cuddle',
      expires_at: INVITE.expires_at,
    });
  });

  it('normalizeChildState: play null, absent and present', () => {
    expect(viewWith(null).play).toBeNull();
    expect(normalizeChildState(makeLiveChildState({ play: 'absent' }), 0, NOW).play).toBeNull();
    expect(viewWith(makePlayState()).play?.can_play).toBe(true);
  });
});

describe('eligibility display (playEntry / playBlock)', () => {
  it('no play → hidden; a lock → hidden', () => {
    expect(playEntry(viewWith(null), NOW)).toEqual({ kind: 'hidden' });
    const locked = normalizeChildState(makeLiveChildState({ play: makePlayState(), lock: { is_locked: true, reason: 'hard_stopped' } }), 0, NOW);
    expect(playEntry(locked, NOW)).toEqual({ kind: 'hidden' });
  });

  it('can play → enabled button', () => {
    expect(playEntry(viewWith(makePlayState()), NOW)).toEqual({ kind: 'button', block: null });
  });

  it('cannot play → disabled with a reason: dirty › sleeping › other', () => {
    const blocked = makePlayState({ can_play: false });
    expect(playBlock(viewWith(blocked, { hygiene_level: 0, needs_cleaning: true }))).toBe('dirty');
    expect(playBlock(viewWith(blocked, { pet_state: 'sleeping' }))).toBe('sleeping');
    expect(playBlock(viewWith(blocked))).toBe('other');
    expect(playEntry(viewWith(blocked, { pet_state: 'sleeping' }), NOW)).toEqual({ kind: 'button', block: 'sleeping' });
    expect(playBlock(viewWith(makePlayState()))).toBeNull();
    expect(playBlock(viewWith(null))).toBeNull();
  });
});

describe('invitation', () => {
  it('shows while offered, can_play and before expiry', () => {
    const view = viewWith(makePlayState({ invitation: INVITE }));
    expect(playEntry(view, NOW)).toEqual({ kind: 'invitation', invitation: INVITE });
    expect(visibleInvitation(view.play, at(INVITE.expires_at) - 1)).toEqual(INVITE);
  });

  it('hidden once expired, after "Mogoče kasneje", or when can_play is false', () => {
    const view = viewWith(makePlayState({ invitation: INVITE }));
    expect(visibleInvitation(view.play, at(INVITE.expires_at))).toBeNull();
    expect(playEntry(view, NOW, new Set([12]))).toEqual({ kind: 'button', block: null });
    expect(visibleInvitation(viewWith(makePlayState({ can_play: false, invitation: INVITE })).play, NOW)).toBeNull();
  });

  it('the refresh delay includes the invitation end', () => {
    const view = viewWith(makePlayState({ invitation: { ...INVITE, expires_at: '2026-10-04T12:05:00+02:00' } }));
    // Device clock = server clock (skew 0 at NOW).
    expect(nextRefreshDelay(view, NOW)).toBe(5 * 60_000);
  });
});

describe('mood timer (30 min happy)', () => {
  it('happy until happy_until, not after', () => {
    const play = readChildPlay(makePlayState({ mood: HAPPY }));
    expect(isHappyAt(play, at(HAPPY.happy_until) - 1)).toBe(true);
    expect(isHappyAt(play, at(HAPPY.happy_until))).toBe(false);
    expect(isHappyAt(null, NOW)).toBe(false);
  });

  it('moodSceneAt: playing only while happy, unlocked, no behaviour scene, idle / playing', () => {
    expect(moodSceneAt(viewWith(makePlayState({ mood: HAPPY })), NOW)).toBe('playing');
    expect(moodSceneAt(viewWith(makePlayState({ mood: HAPPY })), at(HAPPY.happy_until))).toBeNull();
    // A need always wins (hungry / sleeping / sick).
    expect(moodSceneAt(viewWith(makePlayState({ mood: HAPPY }), { pet_state: 'hungry' }), NOW)).toBeNull();
    expect(moodSceneAt(viewWith(makePlayState({ mood: HAPPY }), { pet_state: 'sleeping' }), NOW)).toBeNull();
    // The server said no scene (e.g. a lock at snapshot time).
    expect(moodSceneAt(viewWith(makePlayState({ mood: { ...HAPPY, scene: null } })), NOW)).toBeNull();
    const behaviour = normalizeChildState(
      makeLiveChildState({ play: makePlayState({ mood: HAPPY }), behaviour: { scene: 'accident' } }),
      0,
      NOW,
    );
    expect(moodSceneAt(behaviour, NOW)).toBeNull();
  });

  it('nextPlayChangeMs: the nearer of happy end / invitation end; null when nothing pending', () => {
    expect(nextPlayChangeMs(viewWith(makePlayState({ mood: HAPPY, invitation: INVITE })), NOW)).toBe(20 * 60_000);
    expect(nextPlayChangeMs(viewWith(makePlayState({ invitation: INVITE })), NOW)).toBe(90 * 60_000);
    expect(nextPlayChangeMs(viewWith(makePlayState()), NOW)).toBeNull();
    expect(nextPlayChangeMs(viewWith(makePlayState({ mood: HAPPY })), at(HAPPY.happy_until))).toBeNull();
    expect(nextPlayChangeMs(viewWith(null), NOW)).toBeNull();
  });
});

describe('an open mess hides the happy mood (QA PR #86 M1)', () => {
  it('moodSceneAt: null while needs_cleaning', () => {
    expect(moodSceneAt(viewWith(makePlayState({ mood: HAPPY }), { needs_cleaning: true }), NOW)).toBeNull();
  });

  it('optimisticPlay: happy_until set, but no scene over a mess', () => {
    const next = optimisticPlay(viewWith(makePlayState(), { needs_cleaning: true }), 'cuddle', NOW);
    expect(next.play?.mood.happy_until).not.toBeNull();
    expect(next.play?.mood.scene).toBeNull();
  });
});

describe('optimisticPlay', () => {
  it('30 min happy with the scene, the invitation of that kind done', () => {
    const next = optimisticPlay(viewWith(makePlayState({ invitation: INVITE })), 'play', NOW);
    expect(next.play?.mood).toEqual({ happy_until: new Date(NOW + HAPPY_MINUTES * 60_000).toISOString(), scene: 'playing' });
    expect(next.play?.invitation).toBeNull();
    expect(moodSceneAt(next, NOW)).toBe('playing');
  });

  it('another kind keeps the invitation; a hungry dog is happy without the scene', () => {
    const next = optimisticPlay(viewWith(makePlayState({ invitation: INVITE }), { pet_state: 'hungry' }), 'cuddle', NOW);
    expect(next.play?.invitation).toEqual(INVITE);
    expect(next.play?.mood.scene).toBeNull();
    expect(isHappyAt(next.play, NOW + 1)).toBe(true);
  });

  it('a pet without play is unchanged', () => {
    const view = viewWith(null);
    expect(optimisticPlay(view, 'play', NOW)).toBe(view);
  });
});

describe('API result handling', () => {
  const state = makeLiveChildState({ play: makePlayState({ mood: HAPPY }) });

  it('accepted / unchanged bodies', () => {
    expect(readPlayResponse({ status: 'accepted', play: { kind: 'cuddle', source: 'invitation' }, state })).toEqual({
      status: 'accepted',
      play: { kind: 'cuddle', source: 'invitation' },
      state,
    });
    expect(readPlayResponse({ status: 'unchanged', state })).toEqual({ status: 'unchanged', play: null, state });
    expect(readPlayResponse({ status: 'accepted', play: { kind: 'play', source: '?' }, state })?.play).toEqual({ kind: 'play', source: 'free' });
  });

  it('anything else → null', () => {
    expect(readPlayResponse(null)).toBeNull();
    expect(readPlayResponse({ status: 'refused', state })).toBeNull();
    expect(readPlayResponse({ status: 'accepted' })).toBeNull();
    expect(readPlayResponse({ status: 'accepted', state: { pet: null } })).toBeNull();
  });

  it('422 text names the end of quiet hours when given', () => {
    expect(playRefusalMessage('2026-10-05T07:00:00+02:00', 'Europe/Ljubljana')).toBe('Kuža spi do 07:00. Potem se igrata.');
    expect(playRefusalMessage(null, 'Europe/Ljubljana')).toBe('Zdaj se ne moreta igrati.');
  });

  it('422 without a time takes the reason from the state that came with it', () => {
    const dirty = viewWith(makePlayState({ can_play: false }), { hygiene_level: 0, needs_cleaning: true });
    expect(playRefusalMessage(null, 'Europe/Ljubljana', dirty)).toBe('Najprej počisti, potem se igrata.');
    expect(playRefusalMessage(null, 'Europe/Ljubljana', viewWith(makePlayState()))).toBe('Zdaj se ne moreta igrati.');
  });
});

describe('playBlockText (note under a disabled "Igra")', () => {
  const tz = 'Europe/Ljubljana';
  it('sleeping + a known end of quiet hours still ahead → "Kuža spi do …"', () => {
    expect(playBlockText('sleeping', '2026-10-04T14:00:00+02:00', tz, NOW)).toBe('Kuža spi do 14:00. Potem se igrata.');
  });

  it('a past or missing time → the timeless text', () => {
    expect(playBlockText('sleeping', '2026-10-04T11:00:00+02:00', tz, NOW)).toBe('Kuža spi. Igrata se, ko se zbudi.');
    expect(playBlockText('sleeping', null, tz, NOW)).toBe('Kuža spi. Igrata se, ko se zbudi.');
    expect(playBlockText('sleeping', 'nonsense', tz, NOW)).toBe('Kuža spi. Igrata se, ko se zbudi.');
  });

  it('other reasons ignore the time', () => {
    expect(playBlockText('dirty', '2026-10-04T14:00:00+02:00', tz, NOW)).toBe('Najprej počisti, potem se igrata.');
    expect(playBlockText('other', null, tz, NOW)).toBe('Zdaj se ne moreta igrati.');
  });
});

describe('broadcasts (live updates)', () => {
  it('older server (no key) keeps the view; a lock turns can_play off', () => {
    const current = readChildPlay(makePlayState({ invitation: INVITE }));
    expect(broadcastPlay(current, undefined, false)).toBe(current);
    expect(broadcastPlay(current, undefined, true)).toEqual({ ...current, can_play: false, invitation: null });
    expect(broadcastPlay(null, undefined, false)).toBeNull();
  });

  it('null = the pet lost play; an object replaces it', () => {
    const current = readChildPlay(makePlayState());
    expect(broadcastPlay(current, null, false)).toBeNull();
    expect(broadcastPlay(current, makePlayState({ invitation: INVITE }), false)?.invitation).toEqual(INVITE);
    expect(broadcastPlay(current, makePlayState({ invitation: INVITE }), true)).toEqual({
      can_play: false,
      invitation: null,
      mood: { happy_until: null, scene: null },
    });
  });

  it('applyBroadcast carries play and asks for a refetch on event_type play', () => {
    const view = viewWith(makePlayState());
    const result = applyBroadcast(
      view,
      makeBroadcast({
        pet_id: view.pet.id,
        emitted_at: '2026-10-04T10:00:05.000Z',
        event_type: 'play',
        play: makePlayState({ mood: HAPPY }),
      }),
    );
    expect(result?.view.play?.mood).toEqual(HAPPY);
    expect(result?.refetch).toBe(true);
  });
});

describe('mini-game rules', () => {
  it('a throw is an upward release far or fast enough', () => {
    expect(isThrowRelease(-60, 0)).toBe(true);
    expect(isThrowRelease(-20, -0.6)).toBe(true);
    expect(isThrowRelease(-20, -0.2)).toBe(false);
    expect(isThrowRelease(-10, -2)).toBe(false);
    expect(isThrowRelease(80, 0)).toBe(false);
    expect(isThrowRelease(Number.NaN, -1)).toBe(false);
  });

  it('ball: three throws, one at a time, then done', () => {
    let s = BALL_START;
    for (let i = 0; i < BALL_THROWS; i += 1) {
      s = throwBall(s);
      expect(throwBall(s)).toBe(s); // still fetching
      expect(isBallDone(s)).toBe(false);
      s = ballFetched(s);
    }
    expect(s.throws).toBe(3);
    expect(isBallDone(s)).toBe(true);
    expect(throwBall(s)).toBe(s);
  });

  it('stroke: ≥ 60 pt of finger path in any direction (back and forth counts)', () => {
    let t = STROKE_START;
    t = trackStroke(t, 30, 0);
    t = trackStroke(t, 0, 0);
    expect(isStroke(t)).toBe(true);
    expect(isStroke(trackStroke(STROKE_START, 40, 30))).toBe(false);
    expect(isStroke(trackStroke(STROKE_START, 48, 36))).toBe(true);
  });
});

describe('parent texts', () => {
  afterEach(async () => {
    await i18n.changeLanguage('sl');
  });

  it('play_today: counts or null', () => {
    expect(readPlayToday({ play: 3, cuddle: '2' })).toEqual({ play: 3, cuddle: 2 });
    expect(readPlayToday({ play: -1 })).toEqual({ play: 0, cuddle: 0 });
    expect(readPlayToday(null)).toBeNull();
    expect(playTodayLine({ play: 3, cuddle: 2 })).toBe('Danes: 3× igra z žogo, 2× crkljanje');
    expect(playTodayLine(null)).toBeNull();
  });

  it('timeline rows with the merged count', () => {
    expect(playTimelineText('played_with_pet', 'Ana', 1)).toBe('Igra z žogo · Ana');
    expect(playTimelineText('played_with_pet', 'Ana', 3)).toBe('Igra z žogo ×3 · Ana');
    expect(playTimelineText('cuddled_pet', 'Ana', null)).toBe('Crkljanje · Ana');
    expect(playTimelineText('cuddled_pet', null, 2)).toBe('Crkljanje ×2');
    expect(playTimelineText('fed_pet', 'Ana', 1)).toBeNull();
    expect(activityText({ activity_type: 'cuddled_pet', actor_nickname: 'Ana', value: 4 })).toBe('Crkljanje ×4 · Ana');
  });

  it('English', async () => {
    await i18n.changeLanguage('en');
    expect(playTodayLine({ play: 1, cuddle: 0 })).toBe('Today: 1× ball game, 0× cuddles');
    expect(playTimelineText('played_with_pet', 'Ana', 2)).toBe('Ball game ×2 · Ana');
    expect(playRefusalMessage(null, null)).toBe("You can't play right now.");
  });
});

describe('video: the happy mood between the behaviour scene and the pet state', () => {
  it('videoStateFor + chain playing → idle', () => {
    expect(videoStateFor('idle', null, null, 'playing')).toBe('playing');
    expect(videoStateFor('idle', null, 'accident', 'playing')).toBe('accident');
    expect(videoStateFor('idle', 'hard_stopped', null, 'playing')).toBe('sleeping');
    expect(videoStateFor('idle', null, null, null)).toBe('idle');
    expect(videoChain('playing')).toEqual(['playing', 'idle']);
  });
});
