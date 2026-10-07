/**
 * "Obvestila" in the parent's Nadzor (M3-02, PR #35): is this phone allowed to get
 * PetPrep alarms, and a way to turn them on — the system prompt while it can still be
 * shown, else the phone's settings. Status is re-read when the app comes back to the
 * foreground (e.g. from the settings app).
 */

import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, AppState, Pressable, StyleSheet } from 'react-native';
import { Text } from '@/components/ui/Text';
import { Bell, BellOff } from 'lucide-react-native';

import { Card, PARENT_COLORS as C, SectionTitle } from '@/components/parent/ParentUi';
import { enablePushNotifications, getPushPermissionStatus, type PushPermissionStatus } from '@/modules/push/pushPrompt';
import { strings } from '@/i18n/strings';


/** All user-visible strings (`parent:notifications`, M1-18). */
export const NOTIFICATIONS_STRINGS = strings('parent', 'notifications');

const S = NOTIFICATIONS_STRINGS;

type Status = PushPermissionStatus | 'loading';

export default function NotificationsCard() {
  const [status, setStatus] = useState<Status>('loading');
  const [busy, setBusy] = useState(false);

  const refresh = useCallback(async () => {
    setStatus(await getPushPermissionStatus());
  }, []);

  useEffect(() => {
    void refresh();
    const subscription = AppState.addEventListener('change', (state) => {
      if (state === 'active') void refresh();
    });
    return () => subscription.remove();
  }, [refresh]);

  if (status === 'unsupported') return null;

  const enable = async () => {
    if (busy) return;
    setBusy(true);
    try {
      setStatus(await enablePushNotifications());
    } finally {
      setBusy(false);
    }
  };

  const on = status === 'on';
  return (
    <Card testID="notifications-card">
      <SectionTitle>{S.title}</SectionTitle>
      {status === 'loading' ? (
        <ActivityIndicator color={C.accent} />
      ) : (
        <>
          <Text style={[styles.status, on ? styles.on : styles.off]} testID={`notifications-status-${status}`}>
            {S.status[status]}
          </Text>
          <Text style={styles.hint}>{S.quietHours}</Text>
          {!on && (
            <Pressable
              onPress={() => void enable()}
              disabled={busy}
              style={({ pressed }) => [styles.button, pressed && styles.pressed]}
              accessibilityRole="button"
              testID="notifications-enable"
            >
              {status === 'blocked' ? <BellOff color={C.accent} size={17} /> : <Bell color={C.accent} size={17} />}
              <Text style={styles.buttonText}>{status === 'blocked' ? S.openSettings : S.enable}</Text>
            </Pressable>
          )}
        </>
      )}
    </Card>
  );
}

const styles = StyleSheet.create({
  status: { fontSize: 14, lineHeight: 19 },
  on: { color: C.greenText },
  off: { color: C.text },
  hint: { fontSize: 13, lineHeight: 18, color: C.muted },
  button: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    minHeight: 46,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: C.border,
    backgroundColor: C.bg,
  },
  buttonText: { fontSize: 15, fontWeight: '700', color: C.accent },
  pressed: { opacity: 0.85 },
});
