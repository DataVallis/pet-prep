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
import { makeBehaviourEvent, makeLiveChildState, makeMedia, makePet, makePetProfile, makeTakeOut } from '@/test-utils/fixtures';
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
