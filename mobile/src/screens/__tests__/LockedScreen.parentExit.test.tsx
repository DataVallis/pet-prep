/**
 * David 2026-10-14: the lock overlay is no dead end — every lock reason offers "Starš?",
 * which signs the child out only after the parental gate is answered correctly.
 */
import { fireEvent, render, screen } from '@testing-library/react-native';

import { PARENTAL_GATE_STRINGS as GATE } from '@/components/ParentalGate';
import { logout } from '@/modules/session/logout';
import LockedScreen from '@/screens/LockedScreen';
import { useAppStore, type LockState } from '@/store/appStore';

jest.mock('@/modules/session/logout', () => ({ logout: jest.fn(async () => undefined) }));

const logoutMock = logout as jest.Mock;

function product(): number {
  const [a, b] = (String(screen.getByTestId('parental-gate-question').props.children).match(/\d+/g) ?? []).map(Number);
  return a * b;
}

function answer(value: string) {
  fireEvent.changeText(screen.getByTestId('parental-gate-input'), value);
  fireEvent.press(screen.getByTestId('parental-gate-submit'));
}

describe('LockedScreen — way back to the parent', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    useAppStore.setState(useAppStore.getInitialState(), true);
  });

  it.each<LockState>(['payment_required', 'hard_stop', 'illness', 'game_over', 'inactive'])('%s shows the "Starš?" link', (lock) => {
    useAppStore.getState().setLockState(lock);
    render(<LockedScreen />);
    expect(screen.getByTestId('locked-parent-exit')).toBeTruthy();
    expect(screen.getByText(GATE.link)).toBeTruthy();
  });

  it('logs out only after a correct answer; wrong answers and cancel do nothing', () => {
    useAppStore.getState().setLockState('payment_required');
    render(<LockedScreen />);

    fireEvent.press(screen.getByTestId('locked-parent-exit'));
    answer(String(product() + 1));
    expect(logoutMock).not.toHaveBeenCalled();
    fireEvent.press(screen.getByTestId('parental-gate-cancel'));
    expect(logoutMock).not.toHaveBeenCalled();

    fireEvent.press(screen.getByTestId('locked-parent-exit'));
    answer(String(product()));
    expect(logoutMock).toHaveBeenCalledTimes(1);
  });

  it('three wrong answers close the gate without logging out', () => {
    useAppStore.getState().setLockState('hard_stop');
    render(<LockedScreen />);
    fireEvent.press(screen.getByTestId('locked-parent-exit'));
    answer('1');
    answer('1');
    answer('1');
    expect(screen.queryByTestId('parental-gate')).toBeNull();
    expect(logoutMock).not.toHaveBeenCalled();
  });
});
