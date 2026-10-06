/** Hotfix 2026-10-06: a render error in the child HUD shows a retry card, not a dead app. */
import { fireEvent, screen } from '@testing-library/react-native';

import AppNavigator from '@/navigation/AppNavigator';
import { useAppStore } from '@/store/appStore';
import { makePet } from '@/test-utils/fixtures';
import { renderWithQuery } from '@/test-utils/renderWithQuery';

const mockHud = { fail: true };
jest.mock('@/screens/ChildHudScreen', () => {
  const { Text } = jest.requireActual<typeof import('react-native')>('react-native');
  return {
    __esModule: true,
    default: () => {
      if (mockHud.fail) throw new Error('Maximum update depth exceeded');
      return <Text>CHILD_HUD</Text>;
    },
  };
});
const mockLocked = { fail: false };
jest.mock('@/screens/LockedScreen', () => {
  const { Text } = jest.requireActual<typeof import('react-native')>('react-native');
  return {
    __esModule: true,
    default: () => {
      if (mockLocked.fail) throw new Error('Cannot read properties of null');
      return <Text>LOCKED</Text>;
    },
  };
});
jest.mock('@/modules/session/useSessionBootstrap', () => ({ useSessionBootstrap: () => ({ retry: jest.fn() }) }));

function signInChild() {
  useAppStore.setState(useAppStore.getInitialState(), true);
  useAppStore.getState().signIn({
    token: 't',
    user: { id: 2, name: 'Maja', email: null, role: 'child' },
    pet: makePet({ id: 3, born_at: '2026-10-01T08:00:00Z' }),
    awaitingContract: false,
  });
}

function renderErrorLogs(spy: jest.SpyInstance): unknown[][] {
  return spy.mock.calls.filter((c: unknown[]) => typeof c[0] === 'string' && c[0].startsWith('[render-error:'));
}

describe('AppNavigator — HUD error boundary', () => {
  beforeEach(() => {
    mockHud.fail = true;
    mockLocked.fail = false;
  });

  it('catches the HUD, keeps the session, retry brings the HUD back', () => {
    const spy = jest.spyOn(console, 'error').mockImplementation(() => undefined);
    signInChild();
    renderWithQuery(<AppNavigator />);

    expect(screen.getByTestId('hud-error-boundary')).toBeTruthy();
    expect(useAppStore.getState().authToken).toBe('t');

    mockHud.fail = false;
    fireEvent.press(screen.getByTestId('hud-error-retry'));
    expect(screen.getByText('CHILD_HUD')).toBeTruthy();
    spy.mockRestore();
  });

  it('logs the caught HUD error through the render-error log path (no user data)', () => {
    const spy = jest.spyOn(console, 'error').mockImplementation(() => undefined);
    signInChild();
    renderWithQuery(<AppNavigator />);

    const logged = renderErrorLogs(spy);
    expect(logged).toHaveLength(1);
    expect(logged[0][0]).toBe('[render-error:child-hud] Error: Maximum update depth exceeded');
    expect(JSON.stringify(logged)).not.toContain('Maja');
    spy.mockRestore();
  });

  it('a crash in the LockedScreen overlay is caught and logged; HUD and session survive', () => {
    const spy = jest.spyOn(console, 'error').mockImplementation(() => undefined);
    mockHud.fail = false;
    mockLocked.fail = true;
    signInChild();
    useAppStore.getState().setLockState('hard_stop');
    renderWithQuery(<AppNavigator />);

    expect(screen.getByText('CHILD_HUD')).toBeTruthy(); // the HUD underneath stays mounted
    expect(screen.getByTestId('hud-error-boundary')).toBeTruthy(); // overlay fallback card
    expect(screen.queryByText('Nekaj je šlo narobe')).toBeNull(); // not the app-wide boundary
    expect(useAppStore.getState().authToken).toBe('t');
    expect(renderErrorLogs(spy).map((c) => c[0])).toEqual(['[render-error:locked-overlay] Error: Cannot read properties of null']);

    mockLocked.fail = false;
    fireEvent.press(screen.getByTestId('hud-error-retry'));
    expect(screen.getByText('LOCKED')).toBeTruthy();
    spy.mockRestore();
  });
});
