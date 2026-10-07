/**
 * Push wiring for the signed-in session (M3-02), mounted once in `AppNavigator`:
 *  - foreground presentation handler (once);
 *  - register this install on login / app start when the user already allowed
 *    notifications (no prompt here), and again when the device token rotates;
 *  - route taps — also the tap that cold-started the app;
 *  - Android: rename the notification channels after a language switch (M1-18).
 */

import { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import * as Notifications from 'expo-notifications';
import { Platform } from 'react-native';

import { queryClient } from '@/api/queryClient';
import { ensureAndroidChannels, handlePushTokenEvent, isPushAllowed, registerForPush } from '@/modules/push/pushRegistration';
import { configurePushPresentation, routePushTap } from '@/modules/push/pushRouting';
import { useAppStore } from '@/store/appStore';

function pushSupported(): boolean {
  return Platform.OS === 'ios' || Platform.OS === 'android';
}

export function usePushNotifications(): void {
  const userId = useAppStore((s) => s.user?.id ?? null);
  const role = useAppStore((s) => s.user?.role ?? null);
  const signedIn = useAppStore((s) => s.authToken !== null && s.bootStatus === 'ready');

  useEffect(() => {
    if (pushSupported()) configurePushPresentation();
  }, []);

  // Channel names are shown in Android's system settings: re-save them in the new
  // language (only when notifications are already allowed — never a prompt). Once per switch.
  const { i18n } = useTranslation();
  const language = i18n.language;
  const channelLanguage = useRef(language);
  useEffect(() => {
    if (Platform.OS !== 'android' || language === channelLanguage.current) return;
    channelLanguage.current = language;
    void Notifications.getPermissionsAsync()
      .then((permissions) => (isPushAllowed(permissions) ? ensureAndroidChannels() : undefined))
      .catch(() => undefined);
  }, [language]);

  // Registration: on every sign-in / restore of a session.
  useEffect(() => {
    if (!pushSupported() || !signedIn || userId === null) return;
    void registerForPush();
    // Hotfix 2026-10-06: on iOS our own token fetch emits this event again with the same
    // token — re-registering on every event looped thousands of times per minute.
    const subscription = Notifications.addPushTokenListener((token) => {
      if (typeof token.data === 'string') handlePushTokenEvent(token.data);
    });
    return () => subscription.remove();
  }, [signedIn, userId]);

  // Taps: live listener + the response that opened the app (handled once).
  useEffect(() => {
    if (!pushSupported() || !signedIn || role === null) return;
    let active = true;

    void Notifications.getLastNotificationResponseAsync()
      .then((response) => {
        if (!active || response === null) return;
        routePushTap(response.notification.request.content.data, role, queryClient);
        return Notifications.clearLastNotificationResponseAsync();
      })
      .catch(() => undefined);

    const subscription = Notifications.addNotificationResponseReceivedListener((response) => {
      routePushTap(response.notification.request.content.data, useAppStore.getState().user?.role, queryClient);
    });

    return () => {
      active = false;
      subscription.remove();
    };
  }, [signedIn, role, userId]);
}
