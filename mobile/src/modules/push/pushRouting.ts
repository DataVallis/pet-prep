/**
 * What happens when a PetPrep push is tapped or shown (M3-02).
 *
 *  - Foreground: show the banner and list entry; sound only for urgent pushes.
 *  - Tap, child: the child app has one place — the HUD. Close the album over it and
 *    refetch the pet so the HUD shows the state the push was about.
 *  - Tap, parent: ask the dashboard (store `pushTarget`) to open the detail of the
 *    child caring for that pet, and refetch the dashboard.
 */

import type { QueryClient } from '@tanstack/react-query';
import * as Notifications from 'expo-notifications';

import { childPetKey } from '@/hooks/queries/useChildPet';
import { parentDashboardKey } from '@/modules/family/live';
import { isUrgentPush, parsePushData, type PushData } from '@/modules/push/pushData';
import { useAppStore, type AppUser } from '@/store/appStore';

let handlerConfigured = false;

/** Install the foreground presentation handler once per app process. */
export function configurePushPresentation(): void {
  if (handlerConfigured) return;
  handlerConfigured = true;
  Notifications.setNotificationHandler({
    handleNotification: async (notification) => {
      const data = parsePushData(notification.request.content.data);
      return {
        shouldShowBanner: true,
        shouldShowList: true,
        shouldPlaySound: isUrgentPush(data?.type),
        shouldSetBadge: false,
      };
    },
  });
}

/** Test hook: forget that the handler was installed. */
export function resetPushPresentationForTests(): void {
  handlerConfigured = false;
}

/** Route one tapped push for the signed-in role. Returns the parsed data (null = not ours). */
export function routePushTap(
  data: unknown,
  role: AppUser['role'] | null | undefined,
  queryClient: QueryClient,
): PushData | null {
  const push = parsePushData(data);
  if (push === null || !role) return null;

  const store = useAppStore.getState();
  if (role === 'child') {
    store.setAlbumVisible(false);
    void queryClient.invalidateQueries({ queryKey: childPetKey });
  } else {
    store.setPushTarget({ petId: push.petId });
    void queryClient.invalidateQueries({ queryKey: parentDashboardKey });
  }
  return push;
}
