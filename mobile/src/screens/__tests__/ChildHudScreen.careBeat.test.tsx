/**
 * M5-T20 HUD care beat + soft order (David 2026-11-24): while a bowls clip plays the care buttons
 * are busy and taps are ignored (never queued, never `disabled`, ≤ 5 s, only with a clip); only the
 * next care step glows, everything stays pressable.
 */
import { act, fireEvent, screen, waitFor } from '@testing-library/react-native';
import { readFileSync } from 'fs';
import { join } from 'path';

import { ApiError, api } from '@/api/client';
import ChildHudScreen from '@/screens/ChildHudScreen';
import { useAppStore } from '@/store/appStore';
import { i18n } from '@/i18n';
import { childPetKey } from '@/hooks/queries/useChildPet';
import { normalizeChildState } from '@/modules/childPet/childPetView';
import { makeLiveChildState, makeMedia, makePet, makeTakeOut } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';
import { liveVideoPlayers, playerUris, resetMockVideoPlayers, sourceUri } from '@/test-utils/videoPlayers';
import { resetToyTurnForTests } from '@/modules/petMedia/itemMedia';
import { CARE_BEAT_MAX_MS } from '@/modules/childPet/careBeat';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: {
      ...actual.api,
      getChildPet: jest.fn(),
      getChildPetGrowth: jest.fn(),
      syncSteps: jest.fn(),
      logout: jest.fn(),
      getChildWishes: jest.fn(),
      feedPet: jest.fn(),
      waterPet: jest.fn(),
      takeOutPet: jest.fn(),
    },
  };
});
jest.mock('@/hooks/usePetWebSocket', () => ({ usePetWebSocket: jest.fn() }));
jest.mock('@/modules/session/useSessionBootstrap', () => ({ useSessionBootstrap: () => ({ retry: jest.fn() }) }));
jest.mock('@/modules/push/pushPrompt', () => ({ maybeAskForPush: jest.fn(() => Promise.resolve('skipped')) }));

jest.setTimeout(30_000);

const u = (id: number, v: string) => `https://api.petprep.si/api/media/${id}?expires=1&v=${v}&signature=a`;
const IMG = u(1, 'img');
const IDLE = u(2, 'idle');
const BOWLS_VID = u(13, 'bowlvid');

const BOWLS = { bowls: { image: u(12, 'bowlimg'), video: BOWLS_VID, video_seconds: 3, loop: false } };

type Overrides = Parameters<typeof makeLiveChildState>[0];

function state(items: unknown = BOWLS, o: Overrides = {}, picture = false) {
  return makeLiveChildState({
    feeding: { can_feed: true },
    ...o,
    pet: { pet_state: 'idle', media: { ...makeMedia({ status: 'ready', reference_image_url: IMG, videos: { idle: IDLE }, states: ['idle'] }), items } as never, ...o.pet },
    ...(picture ? { settings: { media_display: 'picture' as const } } : {}),
  });
}

const feedPet = api.feedPet as jest.Mock;
const waterPet = api.waterPet as jest.Mock;
const takeOutPet = api.takeOutPet as jest.Mock;

beforeEach(async () => {
  jest.clearAllMocks();
  resetMockVideoPlayers();
  resetToyTurnForTests();
  await i18n.changeLanguage('sl');
  useAppStore.setState(useAppStore.getInitialState(), true);
  useAppStore.getState().signIn({ token: 'child-token', user: { id: 2, name: 'Maja', email: null, role: 'child' }, pet: makePet({ id: 7 }), awaitingContract: false });
  useAppStore.getState().setWsStatus('connected');
  (api.getChildWishes as jest.Mock).mockReturnValue(new Promise(() => undefined));
});

async function renderHud(raw: ReturnType<typeof state>) {
  (api.getChildPet as jest.Mock).mockResolvedValue(raw);
  const r = renderWithQuery(<ChildHudScreen />);
  await screen.findByTestId('hud-pet-media', {}, { timeout: 5_000 });
  return r;
}

const busy = (id: string) => screen.getByTestId(id).props.accessibilityState?.busy === true;
const bowlsPlayer = () => liveVideoPlayers().find((p) => sourceUri(p) === BOWLS_VID && !p.released);

it('feed with bowls: water is busy while the eating clip plays and a tap never calls the server', async () => {
  await renderHud(state());
  feedPet.mockResolvedValue({ status: 'accepted', state: state() });
  fireEvent.press(screen.getByTestId('action-feed'));
  // The beat starts at press time.
  expect(busy('action-water')).toBe(true);
  expect(busy('action-walk')).toBe(true);
  expect(busy('action-clean')).toBe(true);
  await waitFor(() => expect(playerUris()).toContain(BOWLS_VID));
  fireEvent.press(screen.getByTestId('action-water'));
  fireEvent.press(screen.getByTestId('action-feed'));
  expect(waterPet).not.toHaveBeenCalled();
  expect(feedPet).toHaveBeenCalledTimes(1);

  await act(async () => {
    bowlsPlayer()?.emit('playToEnd', {});
  });
  await waitFor(() => expect(busy('action-water')).toBe(false));
  waterPet.mockResolvedValue({ status: 'accepted', state: state() });
  fireEvent.press(screen.getByTestId('action-water'));
  await waitFor(() => expect(waterPet).toHaveBeenCalledTimes(1));
});

it('a stuck clip: the buttons are pressable again after 5 s at the latest', async () => {
  await renderHud(state());
  feedPet.mockResolvedValue({ status: 'accepted', state: state() });
  fireEvent.press(screen.getByTestId('action-feed'));
  await waitFor(() => expect(playerUris()).toContain(BOWLS_VID));
  expect(busy('action-water')).toBe(true);
  await waitFor(() => expect(busy('action-water')).toBe(false), { timeout: CARE_BEAT_MAX_MS + 2_000 });
});

it('picture mode: never busy (no clip, no beat)', async () => {
  await renderHud(state(BOWLS, {}, true));
  feedPet.mockResolvedValue({ status: 'accepted', state: state(BOWLS, {}, true) });
  fireEvent.press(screen.getByTestId('action-feed'));
  expect(busy('action-water')).toBe(false);
  await waitFor(() => expect(feedPet).toHaveBeenCalledTimes(1));
  expect(busy('action-water')).toBe(false);
});

it('no bowls: never busy', async () => {
  await renderHud(state({}));
  feedPet.mockResolvedValue({ status: 'accepted', state: state({}) });
  fireEvent.press(screen.getByTestId('action-feed'));
  expect(busy('action-water')).toBe(false);
  await waitFor(() => expect(feedPet).toHaveBeenCalledTimes(1));
  expect(busy('action-water')).toBe(false);
});

it('a refused feed (422) ends the beat at once', async () => {
  await renderHud(state());
  feedPet.mockRejectedValue(new ApiError('refused', 422, { message: 'refused', reason: 'outside_window', state: state() }));
  fireEvent.press(screen.getByTestId('action-feed'));
  expect(busy('action-water')).toBe(true);
  await waitFor(() => expect(busy('action-water')).toBe(false));
  expect(playerUris()).not.toContain(BOWLS_VID);
});

it('a lock ends the beat', async () => {
  const { client } = await renderHud(state());
  feedPet.mockReturnValue(new Promise(() => undefined));
  fireEvent.press(screen.getByTestId('action-feed'));
  expect(busy('action-water')).toBe(true);
  await act(async () => {
    client.setQueryData(childPetKey, normalizeChildState(state(BOWLS, { lock: { is_locked: true, reason: 'hard_stopped' } })));
  });
  await act(async () => {
    client.setQueryData(childPetKey, normalizeChildState(state()));
  });
  await waitFor(() => expect(busy('action-water')).toBe(false));
});

it('soft order: take out glows, Feed stays pressable (no hard order)', async () => {
  const raw = state(BOWLS, { behaviour: { take_out: makeTakeOut({ next_due_at: '2026-10-04T11:30:00+02:00' }), can_take_out: true } });
  await renderHud(raw);
  // Overdue: take out pulses (the urgent, due step) …
  expect(screen.getByTestId('action-take-out-pulse')).toBeTruthy();
  expect(busy('action-feed')).toBe(false);
  expect(screen.getByTestId('action-feed').props.accessibilityState?.disabled).toBe(false);
  feedPet.mockResolvedValue({ status: 'accepted', state: raw });
  fireEvent.press(screen.getByTestId('action-feed'));
  await waitFor(() => expect(feedPet).toHaveBeenCalledTimes(1));
  expect(takeOutPet).not.toHaveBeenCalled();
});

describe('guard: core care is never locked by the beat (PRODUCT_SPEC §10.1)', () => {
  const root = join(__dirname, '..', '..');
  const hud = readFileSync(join(root, 'screens', 'ChildHudScreen.tsx'), 'utf8');
  const rules = readFileSync(join(root, 'modules', 'childPet', 'actionRules.ts'), 'utf8');

  it('actionRules.ts knows nothing of the beat', () => {
    expect(rules).not.toMatch(/beat/i);
  });

  it('no `disabled=` of the HUD reads the beat; core-care disabled flags come from the rules only', () => {
    const disabledLines = hud.split('\n').filter((l) => /disabled[=:]/.test(l) || /Disabled\s*=/.test(l));
    for (const line of disabledLines) expect(line).not.toMatch(/beat/i);
  });
});
