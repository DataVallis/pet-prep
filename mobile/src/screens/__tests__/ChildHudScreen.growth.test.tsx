/**
 * Child HUD, M5-R04 part 2: the growth section of the "Moj kuža" album (fetched only when
 * the album opens, hidden on error) and the "Obroki danes" row with "nahrani starš".
 */
import { fireEvent, screen, waitFor, within } from '@testing-library/react-native';

import { api } from '@/api/client';
import ChildHudScreen from '@/screens/ChildHudScreen';
import { GROWTH_STRINGS } from '@/modules/petMedia/growth';
import { MEAL_STRINGS } from '@/modules/childPet/mealWindows';
import { useAppStore } from '@/store/appStore';
import {
  makeBehaviourEvent,
  makeLegacyPetProfile,
  makeLiveChildState,
  makeMedia,
  makePet,
  makePetProfile,
  makeTwoStageGrowth,
} from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: { ...actual.api, getChildPet: jest.fn(), getChildPetGrowth: jest.fn(), syncSteps: jest.fn(), logout: jest.fn() },
  };
});
// Window size per test (the HUD layout picks the metric variant from the height).
const mockWindow = { width: 390, height: 844, scale: 3, fontScale: 1 };
jest.mock('react-native/Libraries/Utilities/useWindowDimensions', () => ({ __esModule: true, default: () => mockWindow }));
jest.mock('@/hooks/usePetWebSocket', () => ({ usePetWebSocket: jest.fn() }));
jest.mock('@/modules/session/useSessionBootstrap', () => ({ useSessionBootstrap: () => ({ retry: jest.fn() }) }));
jest.mock('@/modules/push/pushPrompt', () => ({ maybeAskForPush: jest.fn(() => Promise.resolve('skipped')) }));

jest.setTimeout(20_000);

const getChildPet = api.getChildPet as jest.Mock;
const getChildPetGrowth = api.getChildPetGrowth as jest.Mock;
const IMG = 'https://api.petprep.si/api/media/1?expires=1&v=img&signature=a';
const withAlbum = (o: Parameters<typeof makeLiveChildState>[0] = {}) =>
  makeLiveChildState({ ...o, pet: { media: makeMedia({ status: 'ready', reference_image_url: IMG, states: [] }), ...o.pet } });

async function renderHud(state = withAlbum()) {
  getChildPet.mockResolvedValue(state);
  const utils = renderWithQuery(<ChildHudScreen />);
  await screen.findByTestId('action-feed');
  return utils;
}

beforeEach(() => {
  jest.clearAllMocks();
  mockWindow.height = 844;
  useAppStore.setState(useAppStore.getInitialState(), true);
  useAppStore.getState().signIn({
    token: 'child-token',
    user: { id: 2, name: 'Maja', email: null, role: 'child' },
    pet: makePet({ id: 7 }),
    awaitingContract: false,
  });
  useAppStore.getState().setWsStatus('connected');
});

describe('ChildHudScreen — "Album rasti"', () => {
  it('fetches the growth album only when the album opens and shows "Kako je kuža rasel"', async () => {
    // URLs valid for 30 min (an already expired answer triggers one bounded refresh).
    getChildPetGrowth.mockResolvedValue(makeTwoStageGrowth(new Date(Date.now() + 30 * 60_000).toISOString()));
    await renderHud();
    expect(getChildPetGrowth).not.toHaveBeenCalled();

    fireEvent.press(screen.getByTestId('hud-album-open'));
    expect(await screen.findByText(GROWTH_STRINGS.section)).toBeTruthy();
    expect(getChildPetGrowth).toHaveBeenCalledTimes(1);
    expect(within(screen.getByTestId('album-growth-2')).getByText(GROWTH_STRINGS.current)).toBeTruthy();

    fireEvent.press(screen.getByTestId('album-growth-2'));
    expect(screen.getByTestId('album-viewer')).toBeTruthy();
    expect(screen.getByText('Mlad pes · 9 mesecev')).toBeTruthy();
  });

  it('an error hides the section silently; the album still works', async () => {
    getChildPetGrowth.mockRejectedValue(new Error('offline'));
    await renderHud();
    fireEvent.press(screen.getByTestId('hud-album-open'));
    await waitFor(() => expect(getChildPetGrowth).toHaveBeenCalled());
    await waitFor(() => expect(screen.getByTestId('album-item-photo')).toBeTruthy());
    expect(screen.queryByTestId('album-growth')).toBeNull();
    expect(screen.queryByTestId('album-empty')).toBeNull();
  });
});

describe('ChildHudScreen — "Obroki danes"', () => {
  it('shows today\'s windows with ✓, the current one and "nahrani starš"', async () => {
    await renderHud(
      withAlbum({
        pet: {
          profile: makePetProfile({
            today: {
              ...makePetProfile().today,
              feed_windows: makePetProfile().today.feed_windows.map((w) => ({ ...w, fed: w.start === '11:00' })),
            },
          }),
        },
        server_time: '2026-10-04T15:30:00+02:00',
        feeding: {
          current_window: { start: '2026-10-04T15:00:00+02:00', end: '2026-10-04T17:00:00+02:00' },
          fed_in_current_window: true,
          can_feed: false,
          last_fed_at: '2026-10-04T15:05:00+02:00',
        },
      }),
    );
    const row = screen.getByTestId('hud-meals');
    expect(within(row).getByText(MEAL_STRINGS.title)).toBeTruthy();
    expect(within(row).getByText('7–9')).toBeTruthy();
    expect(within(row).getByText('19–21')).toBeTruthy();
    expect(screen.getByTestId('hud-meal-11:00-13:00-parent')).toBeTruthy();
    expect(within(row).getAllByText(MEAL_STRINGS.byParent)).toHaveLength(1);
    expect(screen.getByTestId('hud-meal-15:00-17:00-done')).toBeTruthy();
    // 11–13 ticked from the server's `fed` (the parent's meal); 15–17 from the optimistic flag.
    expect(screen.getByTestId('hud-meal-11:00-13:00-done')).toBeTruthy();
    expect(screen.queryByTestId('hud-meal-07:00-09:00-done')).toBeNull();
    expect(row.props.accessibilityLabel).toBe('Obroki danes: 7–9 minil, 11–13 nahranjen nahrani starš, 15–17 nahranjen, 19–21');
  });

  it('nothing for a legacy pet or while locked', async () => {
    const { unmount } = await renderHud(withAlbum({ pet: { profile: makeLegacyPetProfile() } }));
    expect(screen.queryByTestId('hud-meals')).toBeNull();
    unmount();
    await renderHud(withAlbum({ pet: { is_hard_stopped: true }, lock: { is_locked: true, reason: 'hard_stopped' } }));
    expect(screen.queryByTestId('hud-meals')).toBeNull();
  });

  it('hidden while a behaviour scene card is open (small screens would crowd the dog)', async () => {
    await renderHud(withAlbum({ behaviour: { active_events: [makeBehaviourEvent('accident')], scene: 'accident' } }));
    expect(screen.getByTestId('hud-scene-accident')).toBeTruthy();
    expect(screen.queryByTestId('hud-meals')).toBeNull();
    expect(screen.queryByTestId('hud-meals-compact')).toBeNull();
  });

  it('regular phone: full card with the title; small phone (SE): one line without the title', async () => {
    const { unmount } = await renderHud();
    expect(within(screen.getByTestId('hud-meals')).getByText(MEAL_STRINGS.title)).toBeTruthy();
    unmount();

    mockWindow.height = 667;
    await renderHud();
    const compact = screen.getByTestId('hud-meals-compact');
    expect(screen.queryByTestId('hud-meals')).toBeNull();
    expect(within(compact).queryByText(MEAL_STRINGS.title)).toBeNull();
    expect(within(compact).getByText('7–9')).toBeTruthy();
    // The screen reader still hears the whole sentence.
    expect(compact.props.accessibilityLabel).toMatch(/^Obroki danes: 7–9/);
  });
});
