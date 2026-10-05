/**
 * PR #35: the push question on the first dashboard / HUD view of a session, and the
 * parent's "Obvestila" row in Nadzor.
 */
import { act, fireEvent, render, renderHook, screen } from '@testing-library/react-native';

import NotificationsCard, { NOTIFICATIONS_STRINGS as S } from '@/components/parent/NotificationsCard';
import { enablePushNotifications, getPushPermissionStatus, maybeAskForPush } from '@/modules/push/pushPrompt';
import { resetFirstViewPromptForTests, usePushPromptOnFirstView } from '@/modules/push/usePushPromptOnFirstView';
import { useAppStore } from '@/store/appStore';

jest.mock('@/modules/push/pushPrompt', () => ({
  maybeAskForPush: jest.fn(() => Promise.resolve('skipped')),
  getPushPermissionStatus: jest.fn(),
  enablePushNotifications: jest.fn(),
}));

const ask = jest.mocked(maybeAskForPush);
const getStatus = jest.mocked(getPushPermissionStatus);
const enable = jest.mocked(enablePushNotifications);

function signIn(token: string, role: 'parent' | 'child' = 'parent') {
  useAppStore.getState().signIn({ token, user: { id: 1, name: 'X', email: null, role }, pet: null });
}

async function flush() {
  await act(async () => {
    await new Promise((r) => setTimeout(r, 0));
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  resetFirstViewPromptForTests();
  useAppStore.setState(useAppStore.getInitialState(), true);
});

describe('usePushPromptOnFirstView', () => {
  it('asks once per session, only while undecided (first-view mode)', () => {
    signIn('tok-1');
    const { rerender, unmount } = renderHook(({ a }: { a: 'parent' | 'child' }) => usePushPromptOnFirstView(a), {
      initialProps: { a: 'parent' },
    });
    rerender({ a: 'parent' });
    unmount();
    renderHook(() => usePushPromptOnFirstView('parent')); // e.g. back from a tab

    expect(ask).toHaveBeenCalledTimes(1);
    expect(ask).toHaveBeenCalledWith('parent', expect.any(Number), { onlyIfUndetermined: true });
  });

  it('asks again after a new login, and never while signed out', () => {
    renderHook(() => usePushPromptOnFirstView('child'));
    expect(ask).not.toHaveBeenCalled();

    act(() => signIn('tok-1', 'child'));
    act(() => signIn('tok-2', 'child'));

    expect(ask).toHaveBeenCalledTimes(2);
    expect(ask).toHaveBeenLastCalledWith('child', expect.any(Number), { onlyIfUndetermined: true });
  });
});

describe('NotificationsCard (Nadzor → Obvestila)', () => {
  it('on: shows the status and no button', async () => {
    getStatus.mockResolvedValue('on');
    render(<NotificationsCard />);
    await flush();

    expect(screen.getByTestId('notifications-status-on')).toHaveTextContent(S.status.on);
    expect(screen.getByText(S.quietHours)).toBeTruthy();
    expect(screen.queryByTestId('notifications-enable')).toBeNull();
  });

  it('off: "Vklopi obvestila" enables and updates the status', async () => {
    getStatus.mockResolvedValue('off');
    enable.mockResolvedValueOnce('on');
    render(<NotificationsCard />);
    await flush();

    expect(screen.getByText(S.enable)).toBeTruthy();
    fireEvent.press(screen.getByTestId('notifications-enable'));
    await flush();

    expect(enable).toHaveBeenCalledTimes(1);
    expect(screen.getByTestId('notifications-status-on')).toBeTruthy();
  });

  it('blocked: offers the phone settings', async () => {
    getStatus.mockResolvedValue('blocked');
    enable.mockResolvedValueOnce('blocked');
    render(<NotificationsCard />);
    await flush();

    expect(screen.getByTestId('notifications-status-blocked')).toHaveTextContent(S.status.blocked);
    fireEvent.press(screen.getByText(S.openSettings));
    await flush();
    expect(enable).toHaveBeenCalledTimes(1);
  });

  it('is hidden where push is not supported', async () => {
    getStatus.mockResolvedValue('unsupported');
    render(<NotificationsCard />);
    await flush();

    expect(screen.queryByTestId('notifications-card')).toBeNull();
  });
});
