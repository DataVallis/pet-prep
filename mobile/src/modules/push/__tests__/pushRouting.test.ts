/**
 * M3-02: push payload parsing, foreground presentation and tap routing.
 */
import { QueryClient } from '@tanstack/react-query';
import * as Notifications from 'expo-notifications';
import { act, renderHook, waitFor } from '@testing-library/react-native';

import { queryClient as appQueryClient } from '@/api/queryClient';
import { childPetKey } from '@/hooks/queries/useChildPet';
import { parentDashboardKey } from '@/modules/family/live';
import { isUrgentPush, parsePushData } from '@/modules/push/pushData';
import { configurePushPresentation, resetPushPresentationForTests, routePushTap } from '@/modules/push/pushRouting';
import { usePushNotifications } from '@/modules/push/usePushNotifications';
import * as registration from '@/modules/push/pushRegistration';
import { useAppStore } from '@/store/appStore';

function response(data: Record<string, unknown>): Notifications.NotificationResponse {
  return {
    actionIdentifier: 'expo.modules.notifications.actions.DEFAULT',
    notification: { request: { content: { data } } },
  } as unknown as Notifications.NotificationResponse;
}

function signIn(role: 'parent' | 'child') {
  useAppStore.getState().signIn({ token: 'tok', user: { id: 5, name: 'X', email: null, role }, pet: null });
}

beforeEach(() => {
  jest.clearAllMocks();
  useAppStore.setState(useAppStore.getInitialState(), true);
});

describe('parsePushData', () => {
  it('accepts exactly {type, pet_id} of a PetPrep escalation push', () => {
    expect(parsePushData({ type: 'critical_alert', pet_id: 7 })).toEqual({ type: 'critical_alert', petId: 7 });
    expect(parsePushData({ type: 'soft_warning', pet_id: '12' })).toEqual({ type: 'soft_warning', petId: 12 });
    expect(parsePushData({ type: 'walk_reminder', pet_id: 3 })).toEqual({ type: 'walk_reminder', petId: 3 });
  });

  it.each([null, 'x', {}, { type: 'marketing', pet_id: 1 }, { type: 'soft_warning' }, { type: 'soft_warning', pet_id: -1 }, { type: 'soft_warning', pet_id: 1.5 }])(
    'ignores %p',
    (data) => {
      expect(parsePushData(data)).toBeNull();
    },
  );

  it('treats phase 2 and above as urgent', () => {
    expect(isUrgentPush('soft_warning')).toBe(false);
    expect(isUrgentPush('critical_alert')).toBe(true);
    expect(isUrgentPush('walk_reminder')).toBe(false);
    expect(isUrgentPush('parent_intervention_alarm')).toBe(true);
    expect(isUrgentPush(null)).toBe(false);
  });
});

describe('foreground presentation', () => {
  it('shows a banner for every push and plays sound only for urgent ones', async () => {
    resetPushPresentationForTests();
    configurePushPresentation();
    configurePushPresentation(); // idempotent

    expect(Notifications.setNotificationHandler).toHaveBeenCalledTimes(1);
    const handler = jest.mocked(Notifications.setNotificationHandler).mock.calls[0]?.[0];
    const present = (data: Record<string, unknown>) =>
      handler?.handleNotification({ request: { content: { data } } } as unknown as Notifications.Notification);

    await expect(present({ type: 'soft_warning', pet_id: 1 })).resolves.toEqual({
      shouldShowBanner: true,
      shouldShowList: true,
      shouldPlaySound: false,
      shouldSetBadge: false,
    });
    await expect(present({ type: 'critical_alert', pet_id: 1 })).resolves.toMatchObject({ shouldPlaySound: true, shouldShowBanner: true });
  });
});

describe('routePushTap', () => {
  it('child: closes the album over the HUD and refetches the pet', () => {
    const client = new QueryClient();
    const invalidate = jest.spyOn(client, 'invalidateQueries');
    useAppStore.getState().setAlbumVisible(true);

    expect(routePushTap({ type: 'soft_warning', pet_id: 3 }, 'child', client)).toEqual({ type: 'soft_warning', petId: 3 });

    expect(useAppStore.getState().isAlbumVisible).toBe(false);
    expect(invalidate).toHaveBeenCalledWith({ queryKey: childPetKey });
    expect(useAppStore.getState().pushTarget).toBeNull();
  });

  it('parent: asks the dashboard to open the child of that pet and refetches it', () => {
    const client = new QueryClient();
    const invalidate = jest.spyOn(client, 'invalidateQueries');

    routePushTap({ type: 'parent_intervention_alarm', pet_id: 9 }, 'parent', client);

    expect(useAppStore.getState().pushTarget).toEqual({ petId: 9 });
    expect(invalidate).toHaveBeenCalledWith({ queryKey: parentDashboardKey });
  });

  it('ignores foreign payloads and signed-out sessions', () => {
    const client = new QueryClient();
    const invalidate = jest.spyOn(client, 'invalidateQueries');

    expect(routePushTap({ url: 'https://evil.example' }, 'parent', client)).toBeNull();
    expect(routePushTap({ type: 'soft_warning', pet_id: 1 }, null, client)).toBeNull();

    expect(invalidate).not.toHaveBeenCalled();
    expect(useAppStore.getState().pushTarget).toBeNull();
  });
});

describe('usePushNotifications', () => {
  it('registers on sign-in (no prompt) and re-registers when the device token rotates', async () => {
    const register = jest.spyOn(registration, 'registerForPush').mockResolvedValue({ status: 'no_permission' });
    let rotate: (() => void) | undefined;
    jest.mocked(Notifications.addPushTokenListener).mockImplementation((listener) => {
      rotate = () => listener({ type: 'ios', data: 'x' });
      return { remove: jest.fn() };
    });

    renderHook(() => usePushNotifications());
    expect(register).not.toHaveBeenCalled(); // signed out

    act(() => signIn('child'));
    await waitFor(() => expect(register).toHaveBeenCalledTimes(1));
    expect(Notifications.requestPermissionsAsync).not.toHaveBeenCalled();

    rotate?.();
    expect(register).toHaveBeenCalledTimes(2);
  });

  it('routes the tap that cold-started the app once, then clears it', async () => {
    jest.spyOn(registration, 'registerForPush').mockResolvedValue({ status: 'no_permission' });
    const invalidate = jest.spyOn(appQueryClient, 'invalidateQueries');
    jest.mocked(Notifications.getLastNotificationResponseAsync).mockResolvedValueOnce(
      response({ type: 'illness_triggered', pet_id: 4 }),
    );
    signIn('parent');

    renderHook(() => usePushNotifications());

    await waitFor(() => expect(useAppStore.getState().pushTarget).toEqual({ petId: 4 }));
    expect(Notifications.clearLastNotificationResponseAsync).toHaveBeenCalledTimes(1);
    expect(invalidate).toHaveBeenCalledWith({ queryKey: parentDashboardKey });
  });

  it('routes a live tap with the current role', async () => {
    jest.spyOn(registration, 'registerForPush').mockResolvedValue({ status: 'no_permission' });
    let tap: ((r: Notifications.NotificationResponse) => void) | undefined;
    jest.mocked(Notifications.addNotificationResponseReceivedListener).mockImplementation((listener) => {
      tap = listener;
      return { remove: jest.fn() };
    });
    signIn('child');
    useAppStore.getState().setAlbumVisible(true);

    renderHook(() => usePushNotifications());
    await waitFor(() => expect(tap).toBeDefined());
    tap?.(response({ type: 'critical_alert', pet_id: 2 }));

    expect(useAppStore.getState().isAlbumVisible).toBe(false);
  });
});
