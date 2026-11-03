/**
 * M6-W03 HUD: bought items in the pet's scenes — the bed replaces the sleeping loop (picture mode:
 * its image), the bowls play once after an accepted water / meal, the treat clip plays once on the
 * stage after »Priboljšek«; without items the normal scene stays.
 */
import { act, fireEvent, screen, waitFor } from '@testing-library/react-native';

import { api } from '@/api/client';
import ChildHudScreen from '@/screens/ChildHudScreen';
import { useAppStore } from '@/store/appStore';
import { i18n } from '@/i18n';
import { makeLiveChildState, makeMedia, makePet } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';
import { liveVideoPlayers, playerUris, resetMockVideoPlayers, sourceUri } from '@/test-utils/videoPlayers';
import { resetToyTurnForTests } from '@/modules/petMedia/itemMedia';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: { ...actual.api, getChildPet: jest.fn(), getChildPetGrowth: jest.fn(), syncSteps: jest.fn(), logout: jest.fn(), giveTreat: jest.fn(), getChildWishes: jest.fn(), waterPet: jest.fn() },
  };
});
jest.mock('@/hooks/usePetWebSocket', () => ({ usePetWebSocket: jest.fn() }));
jest.mock('@/modules/session/useSessionBootstrap', () => ({ useSessionBootstrap: () => ({ retry: jest.fn() }) }));
jest.mock('@/modules/push/pushPrompt', () => ({ maybeAskForPush: jest.fn(() => Promise.resolve('skipped')) }));

jest.setTimeout(20_000);

const u = (id: number, v: string) => `https://api.petprep.si/api/media/${id}?expires=1&v=${v}&signature=a`;
const IMG = u(1, 'img');
const IDLE = u(2, 'idle');
const SLEEPING = u(3, 'sleep');
const BED_IMG = u(10, 'bedimg');
const BED_VID = u(11, 'bedvid');
const BOWLS_IMG = u(12, 'bowlimg');
const BOWLS_VID = u(13, 'bowlvid');
const TREAT_VID = u(14, 'treatvid');

const ITEMS = {
  bed: { image: BED_IMG, video: BED_VID, video_seconds: 3, loop: true },
  bowls: { image: BOWLS_IMG, video: BOWLS_VID, video_seconds: 3, loop: false },
  treats: { image: null, video: TREAT_VID, video_seconds: 2, loop: false },
};

function media(items: unknown = ITEMS) {
  return { ...makeMedia({ status: 'ready', reference_image_url: IMG, videos: { idle: IDLE, sleeping: SLEEPING }, states: ['idle', 'sleeping'] }), items };
}

function state(petState: 'idle' | 'sleeping', items: unknown = ITEMS, picture = false) {
  return {
    ...makeLiveChildState({
      pet: { pet_state: petState, media: media(items) as never },
      ...(picture ? { settings: { media_display: 'picture' as const } } : {}),
    }),
    wishes: { open_count: 0, items: [], treats: { left: 5, given_today: 0, daily_limit: 2 } },
  };
}

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
  renderWithQuery(<ChildHudScreen />);
  await screen.findByTestId('hud-pet-media');
}

it('a sleeping pet with its bed: the bed loop plays instead of the sleeping loop', async () => {
  await renderHud(state('sleeping'));
  await waitFor(() => expect(playerUris()).toContain(BED_VID));
  expect(playerUris()).not.toContain(SLEEPING);
  expect(liveVideoPlayers().find((p) => sourceUri(p) === BED_VID)?.loop).toBe(true);
});

it('no bed (not owned / content not ready): the normal sleeping loop', async () => {
  await renderHud(state('sleeping', {}));
  await waitFor(() => expect(playerUris()).toContain(SLEEPING));
  expect(playerUris()).not.toContain(BED_VID);
});

it('picture mode: the bed image, no player', async () => {
  await renderHud(state('sleeping', ITEMS, true));
  await waitFor(() => expect(screen.getByTestId('hud-pet-media-image').props.source).toEqual({ uri: BED_IMG }));
  expect(liveVideoPlayers()).toHaveLength(0);
});

it('an accepted water plays the bowls once, then back to the normal stage', async () => {
  await renderHud(state('idle'));
  (api.waterPet as jest.Mock).mockResolvedValue({ status: 'accepted', state: state('idle') });
  fireEvent.press(screen.getByTestId('action-water'));
  await waitFor(() => expect(playerUris()).toContain(BOWLS_VID));
  const bowls = liveVideoPlayers().find((p) => sourceUri(p) === BOWLS_VID);
  expect(bowls?.loop).toBe(false);
  await act(async () => {
    bowls?.emit('playToEnd', {});
  });
  await waitFor(() => expect(liveVideoPlayers().some((p) => sourceUri(p) === BOWLS_VID && !p.released)).toBe(false));
});

it('without bowls an accepted water keeps the normal stage (no extra player)', async () => {
  await renderHud(state('idle', {}));
  (api.waterPet as jest.Mock).mockResolvedValue({ status: 'accepted', state: state('idle', {}) });
  fireEvent.press(screen.getByTestId('action-water'));
  await waitFor(() => expect(api.waterPet).toHaveBeenCalledTimes(1));
  expect(playerUris()).not.toContain(BOWLS_VID);
});

it('»Priboljšek« plays the treat clip once on the stage', async () => {
  await renderHud(state('idle'));
  (api.giveTreat as jest.Mock).mockResolvedValue({ treats_left: 4, given_today: 1, daily_limit: 2, media: null, state: state('idle') });
  fireEvent.press(screen.getByTestId('hud-treat'));
  await waitFor(() => expect(playerUris()).toContain(TREAT_VID));
  expect(liveVideoPlayers().find((p) => sourceUri(p) === TREAT_VID)?.loop).toBe(false);
  // The old full-screen treat overlay is gone — the clip plays on the stage.
  expect(screen.queryByTestId('hud-treat-clip')).toBeNull();
});
