/**
 * M5-T12 »Vpiši sprehod« / "Log a walk" (David 2026-10-21): in adapted mode the PARENT can
 * enter walk minutes for the child — on iPhone pushes are recorded automatically only by an
 * Apple Watch, so the child needs to do nothing. Today or yesterday (family time zone),
 * 1–240 minutes; the server counts minutes × 100 steps (the step-goal rule) on top of any
 * pushes from the phone. One entry id per entry, kept across a retry, so it never counts twice.
 */

import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, View } from 'react-native';
import { Footprints } from 'lucide-react-native';

import { ApiError } from '@/api/client';
import { Text, TextInput } from '@/components/ui/Text';
import { Card, PARENT_COLORS as C, SectionTitle, Segmented } from '@/components/parent/ParentUi';
import { newWalkEntryId, useLogParentWalk } from '@/hooks/queries/useFamilyMutations';
import { familyCalendar } from '@/modules/childPet/familyTime';
import { palette } from '@/theme';
import { t } from '@/i18n';
import { strings } from '@/i18n/strings';

/** All user-visible strings (`family:children.logWalk`). */
export const LOG_WALK_STRINGS = strings('family', 'children', {
  saved: (minutes: number) => t('family:children.logWalk.saved', { count: minutes }),
});

export const MAX_WALK_MINUTES = 240;

/** "30" → 30; anything outside 1–240 or not a whole number → null. */
export function parseWalkMinutes(text: string): number | null {
  const trimmed = text.trim();
  if (!/^\d{1,3}$/.test(trimmed)) return null;
  const n = Number(trimmed);
  return n >= 1 && n <= MAX_WALK_MINUTES ? n : null;
}

/** Family-local today and yesterday ("Y-m-d"). */
export function walkDates(timezone: string | null, nowMs: number = Date.now()): { today: string; yesterday: string } {
  const cal = familyCalendar(timezone, null);
  const today = cal.dateOf(nowMs);
  return { today, yesterday: cal.dateOf(cal.startOfDay(nowMs) - 1) };
}

type ErrorKind = 'adapted_mode_off' | 'date_not_allowed' | 'locked' | 'other';

function errorKind(error: unknown): ErrorKind {
  if (error instanceof ApiError) {
    const reason = (error.data as { reason?: unknown } | undefined)?.reason;
    if (reason === 'adapted_mode_off' || reason === 'date_not_allowed') return reason;
    if (error.status === 423) return 'locked';
  }
  return 'other';
}

interface LogWalkCardProps {
  childId: number;
  timezone: string | null;
  testID?: string;
}

export default function LogWalkCard({ childId, timezone, testID = 'log-walk' }: LogWalkCardProps) {
  const S = LOG_WALK_STRINGS.logWalk;
  const mutation = useLogParentWalk();
  const [minutes, setMinutes] = useState('');
  const [day, setDay] = useState<'today' | 'yesterday'>('today');
  // One id per entry: a retry after a failure re-sends the same one (never counted twice).
  const [entryId, setEntryId] = useState(() => newWalkEntryId());
  const [saved, setSaved] = useState<number | null>(null);
  const [error, setError] = useState<ErrorKind | null>(null);
  const parsed = parseWalkMinutes(minutes);

  const submit = () => {
    if (parsed === null || mutation.isPending) return;
    const dates = walkDates(timezone);
    setError(null);
    setSaved(null);
    mutation.mutate(
      { childId, minutes: parsed, localDate: day === 'today' ? dates.today : dates.yesterday, entryId },
      {
        onSuccess: (res) => {
          setSaved(res.entry.minutes);
          setMinutes('');
          setEntryId(newWalkEntryId());
        },
        onError: (e) => setError(errorKind(e)),
      },
    );
  };

  return (
    <Card testID={testID}>
      <SectionTitle>{S.title}</SectionTitle>
      <Text style={styles.hint}>{S.hint}</Text>
      <Segmented<'today' | 'yesterday'>
        options={[
          { value: 'today', label: S.today },
          { value: 'yesterday', label: S.yesterday },
        ]}
        value={day}
        onChange={setDay}
        label={(v) => (v === 'today' ? S.today : S.yesterday)}
        testIDPrefix={`${testID}-day`}
      />
      <View style={styles.row}>
        <TextInput
          value={minutes}
          onChangeText={setMinutes}
          keyboardType="number-pad"
          maxLength={3}
          placeholder={S.placeholder}
          accessibilityLabel={S.minutesLabel}
          style={styles.input}
          testID={`${testID}-minutes`}
        />
        <Text style={styles.unit}>{S.unit}</Text>
        <Pressable
          onPress={submit}
          disabled={parsed === null || mutation.isPending}
          accessibilityRole="button"
          accessibilityState={{ disabled: parsed === null || mutation.isPending }}
          style={({ pressed }) => [styles.button, (parsed === null || mutation.isPending) && styles.disabled, pressed && styles.pressed]}
          testID={`${testID}-submit`}
        >
          {mutation.isPending ? (
            <ActivityIndicator color={palette.white} />
          ) : (
            <>
              <Footprints color={palette.white} size={16} />
              <Text style={styles.buttonText}>{S.submit}</Text>
            </>
          )}
        </Pressable>
      </View>
      {minutes.trim() !== '' && parsed === null && (
        <Text style={styles.error} testID={`${testID}-invalid`}>
          {S.invalid}
        </Text>
      )}
      {saved !== null && (
        <Text style={styles.ok} testID={`${testID}-saved`}>
          {LOG_WALK_STRINGS.saved(saved)}
        </Text>
      )}
      {error !== null && (
        <Text style={styles.error} testID={`${testID}-error`}>
          {S.errors[error]}
        </Text>
      )}
    </Card>
  );
}

const styles = StyleSheet.create({
  hint: { fontSize: 13, lineHeight: 18, color: palette.n600 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  input: {
    width: 72,
    height: 44,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: palette.n200,
    paddingHorizontal: 12,
    fontSize: 16,
    color: palette.graphite,
    backgroundColor: palette.white,
  },
  unit: { flex: 1, fontSize: 14, color: palette.n600 },
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
  ok: { fontSize: 13, color: C.green },
  error: { fontSize: 13, color: palette.danger },
});
