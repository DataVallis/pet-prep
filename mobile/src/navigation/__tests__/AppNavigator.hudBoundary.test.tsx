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
jest.mock('@/modules/session/useSessionBootstrap', () => ({ useSessionBootstrap: () => ({ retry: jest.fn() }) }));

describe('AppNavigator — HUD error boundary', () => {
  it('catches the HUD, keeps the session, retry brings the HUD back', () => {
    const spy = jest.spyOn(console, 'error').mockImplementation(() => undefined);
    useAppStore.setState(useAppStore.getInitialState(), true);
    useAppStore.getState().signIn({
      token: 't',
      user: { id: 2, name: 'Maja', email: null, role: 'child' },
      pet: makePet({ id: 3, born_at: '2026-10-01T08:00:00Z' }),
      awaitingContract: false,
    });
    renderWithQuery(<AppNavigator />);

    expect(screen.getByTestId('hud-error-boundary')).toBeTruthy();
    expect(useAppStore.getState().authToken).toBe('t');

    mockHud.fail = false;
    fireEvent.press(screen.getByTestId('hud-error-retry'));
    expect(screen.getByText('CHILD_HUD')).toBeTruthy();
    spy.mockRestore();
  });
});
