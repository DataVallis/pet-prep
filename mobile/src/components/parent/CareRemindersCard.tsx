/**
 * M5-R18 / R20 (David 2026-10-24): the parent's care reminders — a professional groomer visit
 * and a bath, only for breeds whose source states how often. One row per reminder of the
 * current period: how often, due by / done on, and »Opravljeno« to confirm. Never scored
 * (the card says so). The server's `care_reminders` of the dashboard pet are the truth; a
 * confirmation refetches the dashboard.
 */

import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, View } from 'react-native';
import { Check } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { Card, PARENT_COLORS as C, SectionTitle } from '@/components/parent/ParentUi';
import { useConfirmCareReminder } from '@/hooks/queries/useCareTasks';
import { CARE_REMINDER_STRINGS, careReminderTitle, frequencyText, readCareReminders, type CareReminderKey } from '@/modules/careTasks/careTasks';
import { localParts } from '@/modules/childPet/familyTime';
import { palette } from '@/theme';

/** "3. 11." — the family-local date (parent wording). */
export function reminderDate(iso: string | null, timezone: string | null): string {
  if (iso === null) return '';
  const parts = localParts(iso, timezone);
  if (parts === null) return '';
  const [, month, day] = parts.date.split('-').map(Number);
  return `${day}. ${month}.`;
}

interface CareRemindersCardProps {
  petId: number;
  /** The dashboard pet's raw `care_reminders`. */
  reminders: unknown;
  timezone: string | null;
  testID?: string;
}

export default function CareRemindersCard({ petId, reminders, timezone, testID = 'care-reminders' }: CareRemindersCardProps) {
  const S = CARE_REMINDER_STRINGS;
  const items = readCareReminders(reminders);
  const mutation = useConfirmCareReminder();
  const [pending, setPending] = useState<CareReminderKey | null>(null);
  const [failed, setFailed] = useState<CareReminderKey | null>(null);
  if (items.length === 0) return null;

  const confirm = (task: CareReminderKey) => {
    if (mutation.isPending) return;
    setPending(task);
    setFailed(null);
    mutation.mutate(
      { petId, task },
      {
        onError: () => setFailed(task),
        onSettled: () => setPending(null),
      },
    );
  };

  return (
    <Card testID={testID}>
      <SectionTitle>{S.title}</SectionTitle>
      {items.map((r) => (
        <View key={r.task} style={styles.row} testID={`${testID}-${r.task}`}>
          <View style={styles.flex}>
            <Text style={styles.title}>{careReminderTitle(r.task)}</Text>
            <Text style={styles.muted}>{S.every(frequencyText(1, r.period_days))}</Text>
            <Text style={r.done ? styles.ok : styles.muted} testID={`${testID}-${r.task}-status`}>
              {r.done ? S.doneOn(reminderDate(r.done_at, timezone)) : S.dueBy(reminderDate(r.period_ends_at, timezone))}
            </Text>
            {failed === r.task && <Text style={styles.error}>{S.error}</Text>}
          </View>
          {!r.done && (
            <Pressable
              onPress={() => confirm(r.task)}
              disabled={mutation.isPending}
              accessibilityRole="button"
              accessibilityLabel={`${careReminderTitle(r.task)}: ${S.confirm}`}
              accessibilityHint={S.confirmA11y}
              accessibilityState={{ disabled: mutation.isPending }}
              style={({ pressed }) => [styles.button, mutation.isPending && styles.disabled, pressed && styles.pressed]}
              testID={`${testID}-${r.task}-confirm`}
            >
              {pending === r.task ? (
                <ActivityIndicator color={palette.white} />
              ) : (
                <>
                  <Check color={palette.white} size={16} />
                  <Text style={styles.buttonText}>{S.confirm}</Text>
                </>
              )}
            </Pressable>
          )}
        </View>
      ))}
      <Text style={styles.muted}>{S.note}</Text>
    </Card>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 6 },
  flex: { flex: 1, gap: 2 },
  title: { fontSize: 15, fontWeight: '700', color: palette.graphite },
  muted: { fontSize: 13, lineHeight: 18, color: palette.n600 },
  ok: { fontSize: 13, color: C.green },
  error: { fontSize: 13, color: palette.danger },
  button: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    height: 44,
    paddingHorizontal: 14,
    borderRadius: 12,
    backgroundColor: palette.graphite,
  },
  buttonText: { fontSize: 14, fontWeight: '700', color: palette.white },
  disabled: { opacity: 0.45 },
  pressed: { opacity: 0.85 },
});
