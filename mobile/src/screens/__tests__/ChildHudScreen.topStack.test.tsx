/**
 * David 2026-10-25 »pes mora biti viden«: the HUD's message cards sit in one compact stack under
 * the header (left of the metric column), most urgent first, at most two cards — the rest behind
 * "+N". Only the action dock stays at the bottom.
 */
import { configure, fireEvent, screen, within } from '@testing-library/react-native';
import { StyleSheet, type ViewStyle } from 'react-native';

import { api } from '@/api/client';
import ChildHudScreen from '@/screens/ChildHudScreen';
import { METRICS_RESERVED_RIGHT } from '@/modules/hud/hudLayout';
import { useAppStore } from '@/store/appStore';
import { makeBehaviourEvent, makeLiveChildState, makeMedia, makePet, makePetProfile, makePlayState, makeTakeOut } from '@/test-utils/fixtures';
import { resetTopStackPrefsForTests, setTopStackPrefsStoreForTests, useTopStackPrefs } from '@/modules/hud/topStackPrefs';
import { verticalSwipe } from '@/test-utils/stackSwipe';
import { palette } from '@/theme';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

configure({ defaultIncludeHiddenElements: true });

jest.mock('@/api/client', () => {
  const actual = jest.requireActual<typeof import('@/api/client')>('@/api/client');
  return {
    ...actual,
    api: { ...actual.api, getChildPet: jest.fn(), getChildPetGrowth: jest.fn(), syncSteps: jest.fn(), logout: jest.fn() },
  };
});
// iPhone SE.
const mockWindow = { width: 375, height: 667, scale: 2, fontScale: 1 };
jest.mock('react-native/Libraries/Utilities/useWindowDimensions', () => ({ __esModule: true, default: () => mockWindow }));
jest.mock('@/hooks/usePetWebSocket', () => ({ usePetWebSocket: jest.fn() }));
jest.mock('@/modules/session/useSessionBootstrap', () => ({ useSessionBootstrap: () => ({ retry: jest.fn() }) }));
jest.mock('@/modules/push/pushPrompt', () => ({ maybeAskForPush: jest.fn(() => Promise.resolve('skipped')) }));

jest.setTimeout(20_000);

const getChildPet = api.getChildPet as jest.Mock;

async function renderHud(state: ReturnType<typeof makeLiveChildState>) {
  getChildPet.mockResolvedValue(state);
  renderWithQuery(<ChildHudScreen />);
  await screen.findByTestId('action-feed');
}

/** Puppy with a chewed slipper, a running take-out clock and media still getting ready = 3 cards. */
const busyPuppy = () =>
  makeLiveChildState({
    pet: { profile: makePetProfile(), hygiene_level: 40, needs_cleaning: true, media: makeMedia({ status: 'pending' }) },
    behaviour: {
      take_out: makeTakeOut(),
      can_take_out: true,
      active_events: [makeBehaviourEvent('chewing')],
      scene: 'chewing',
      can_resolve_chewing: true,
    },
  });

beforeEach(() => {
  jest.clearAllMocks();
  useAppStore.setState(useAppStore.getInitialState(), true);
  useAppStore.getState().signIn({
    token: 'child-token',
    user: { id: 2, name: 'Maja', email: null, role: 'child' },
    pet: makePet({ id: 7 }),
    awaitingContract: false,
  });
  useAppStore.getState().setWsStatus('connected');
});

describe('ChildHudScreen top stack', () => {
  it('hangs under the header, left of the metric column — the cards are no longer above the dock', async () => {
    await renderHud(busyPuppy());
    const stack = screen.getByTestId('hud-top-stack');
    const style = StyleSheet.flatten(stack.props.style) as ViewStyle;
    expect(style).toMatchObject({ position: 'absolute', left: 16, right: METRICS_RESERVED_RIGHT });
    expect(typeof style.top).toBe('number');
    expect(style.bottom).toBeUndefined();
    // Never reaches the dock.
    expect(style.maxHeight).toBeLessThan(667 - 134);
    expect(within(stack).getByTestId('hud-behaviour')).toBeTruthy();
    expect(within(stack).getByTestId('action-resolve-chewing')).toBeTruthy();
    // The dock keeps all five buttons at the bottom.
    const dock = screen.getByTestId('hud-dock');
    for (const id of ['action-feed', 'action-water', 'action-take-out', 'action-walk', 'action-clean']) {
      expect(within(dock).getByTestId(id)).toBeTruthy();
    }
  });

  it('shows the two most urgent cards; the rest open from "+N" and close again with "Manj"', async () => {
    await renderHud(busyPuppy());
    const stack = screen.getByTestId('hud-behaviour');
    // The take-out clock and the mess card (order by the clock's level, topStack.test.ts);
    // the less urgent "getting ready" notice waits behind "+1".
    const shown = within(stack)
      .getAllByTestId(/^hud-(scene-chewing|take-out-countdown|pet-media-pending)$/)
      .map((el) => String(el.props.testID));
    expect([...shown].sort()).toEqual(['hud-scene-chewing', 'hud-take-out-countdown']);
    const more = screen.getByTestId('hud-top-stack-more');
    expect(within(more).getByText('+1 več')).toBeTruthy();
    expect(more.props.accessibilityLabel).toBe('Pokaži še druga obvestila (1)');

    fireEvent.press(more);
    expect(within(stack).getByTestId('hud-pet-media-pending')).toBeTruthy();
    fireEvent.press(screen.getByTestId('hud-top-stack-less'));
    expect(within(stack).queryByTestId('hud-pet-media-pending')).toBeNull();
    expect(screen.getByTestId('hud-top-stack-more')).toBeTruthy();
  });

  it('no "+N" when everything fits', async () => {
    await renderHud(makeLiveChildState({ pet: { profile: makePetProfile() }, behaviour: { take_out: makeTakeOut(), can_take_out: true } }));
    expect(screen.getByTestId('hud-take-out-countdown')).toBeTruthy();
    expect(screen.queryByTestId('hud-top-stack-more')).toBeNull();
  });
});

describe('ChildHudScreen collapsible top stack (David 2026-10-26)', () => {
  const withPlay = () => {
    const state = busyPuppy();
    return { ...state, play: makePlayState() } as typeof state;
  };

  it('× (icon only, "Skrij sporočila") collapses the stack into one pill + icon-only chips', async () => {
    await renderHud(withPlay());
    const close = screen.getByTestId('hud-top-stack-collapse');
    expect(close.props.accessibilityLabel).toBe('Skrij sporočila');
    expect(within(close).queryByText(/Skrij/)).toBeNull();
    expect(within(screen.getByTestId('hud-chip-row')).getByText('Igra')).toBeTruthy();

    fireEvent.press(close);
    const collapsed = screen.getByTestId('hud-top-stack-collapsed');
    // No card bodies any more — only the pill (the most urgent card: the orange take-out clock) …
    expect(screen.queryByTestId('hud-take-out-countdown')).toBeNull();
    expect(screen.queryByTestId('hud-scene-chewing')).toBeNull();
    const pill = within(collapsed).getByTestId('hud-top-stack-pill');
    expect(within(pill).getByTestId('hud-top-stack-pill-text').props.children).toBe('Ven čez ~55 min');
    expect(within(pill).getByText('+2')).toBeTruthy();
    expect(pill.props.accessibilityLabel).toBe('Ven čez ~55 min. Pokaži sporočila (3)');
    // … in the red style (urgent top card).
    const pillStyle = StyleSheet.flatten(pill.props.style) as ViewStyle;
    expect(pillStyle.borderColor).toBe(palette.dangerDark);
    // The chips keep their action and full name, without the text.
    const play = within(collapsed).getByTestId('hud-play-open');
    expect(play.props.accessibilityLabel).toBeTruthy();
    expect(within(play).queryByText('Igra')).toBeNull();
    fireEvent.press(play);
    expect(useAppStore.getState().playOverlay).toBe('pick');
    // Remembered on the device.
    expect(useTopStackPrefs.getState().collapsed).toBe(true);
  });

  it('tapping the pill expands it again', async () => {
    await renderHud(withPlay());
    fireEvent.press(screen.getByTestId('hud-top-stack-collapse'));
    fireEvent.press(screen.getByTestId('hud-top-stack-pill'));
    expect(screen.queryByTestId('hud-top-stack-collapsed')).toBeNull();
    expect(screen.getByTestId('hud-take-out-countdown')).toBeTruthy();
    expect(within(screen.getByTestId('hud-chip-row')).getByText('Igra')).toBeTruthy();
    expect(useTopStackPrefs.getState().collapsed).toBe(false);
  });

  it('swipe up on the stack collapses it, swipe down on the pill row expands it; a tap-sized move does nothing', async () => {
    await renderHud(withPlay());
    expect(verticalSwipe(screen.getByTestId('hud-behaviour'), -4)).toBe(false);
    expect(verticalSwipe(screen.getByTestId('hud-behaviour'), 30)).toBe(false);
    expect(verticalSwipe(screen.getByTestId('hud-behaviour'), -60)).toBe(true);
    expect(screen.getByTestId('hud-top-stack-collapsed')).toBeTruthy();

    expect(verticalSwipe(screen.getByTestId('hud-behaviour'), -60)).toBe(false);
    expect(verticalSwipe(screen.getByTestId('hud-behaviour'), 60)).toBe(true);
    expect(screen.queryByTestId('hud-top-stack-collapsed')).toBeNull();
    expect(screen.getByTestId('hud-take-out-countdown')).toBeTruthy();
  });

  it('starts collapsed when this device remembered it (no auto-expand for items already seen)', async () => {
    const state = withPlay();
    // First visit: collapse with the current alerts on screen.
    await renderHud(state);
    fireEvent.press(screen.getByTestId('hud-top-stack-collapse'));
    const stored = { collapsed: true, seen: useTopStackPrefs.getState().seen };
    screen.unmount();

    resetTopStackPrefsForTests();
    setTopStackPrefsStoreForTests({
      getItemAsync: () => Promise.resolve(JSON.stringify(stored)),
      setItemAsync: () => Promise.resolve(),
    });
    await renderHud(state);
    expect(await screen.findByTestId('hud-top-stack-collapsed')).toBeTruthy();
    expect(screen.queryByTestId('hud-take-out-countdown')).toBeNull();
  });

  it('only chips: collapsed shows the icon chips and a ▾ to expand', async () => {
    // A legacy pet (no profile → no meals row) with play: no cards at all, only the "Igra" chip.
    const state = makeLiveChildState({ pet: { profile: makePetProfile({ legacy: true }) } });
    await renderHud({ ...state, play: makePlayState() } as typeof state);
    expect(screen.queryByTestId('hud-top-stack-pill')).toBeNull();
    const close = within(screen.getByTestId('hud-chip-row')).getByTestId('hud-top-stack-collapse');
    fireEvent.press(close);
    expect(screen.queryByTestId('hud-top-stack-pill')).toBeNull();
    const expand = screen.getByTestId('hud-top-stack-expand');
    expect(expand.props.accessibilityLabel).toBe('Pokaži sporočila');
    expect(within(screen.getByTestId('hud-top-stack-collapsed')).getByTestId('hud-play-open')).toBeTruthy();
    fireEvent.press(expand);
    expect(within(screen.getByTestId('hud-chip-row')).getByText('Igra')).toBeTruthy();
  });
});
