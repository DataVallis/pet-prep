/**
 * Family quiet hours (school + bedtime, family-local "HH:MM"; one configuration per
 * family, any parent may edit — M1-03 / M2-01). Moved to TanStack Query and the
 * light parent theme with M2-05.
 */

import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Switch, Text, TextInput, View } from 'react-native';

import { ApiError } from '@/api/client';
import { useQuietHours, useUpdateQuietHours } from '@/hooks/queries/useParentQueries';
import { Card, ErrorBanner, PARENT_COLORS as C, SectionTitle } from '@/components/parent/ParentUi';
import type { QuietHours } from '@/types';

export const QUIET_HOURS_STRINGS = {
  title: 'Tihe ure',
  hint: 'V tihih urah kuža skoraj ne lakoti, ni neredov in ni obvestil. Ure veljajo po času družine.',
  active: 'Vklopljeno',
  school: 'Šola',
  bedtime: 'Spanje',
  start: 'Začetek',
  end: 'Konec',
  save: 'Shrani',
  saved: 'Shranjeno.',
  invalidTime: 'Čas vpišite kot UU:MM (npr. 08:00).',
  loadError: 'Tihih ur ni bilo mogoče naložiti.',
  retry: 'Poskusi znova',
  saveError: 'Shranjevanje ni uspelo. Preverite čase in poskusite znova.',
  offline: 'Ni povezave s strežnikom. Poskusite znova.',
} as const;

const S = QUIET_HOURS_STRINGS;

type Times = Pick<QuietHours, 'school_start' | 'school_end' | 'bedtime_start' | 'bedtime_end' | 'is_active'>;

const DEFAULT_TIMES: Times = {
  school_start: '08:00',
  school_end: '13:00',
  bedtime_start: '21:00',
  bedtime_end: '07:00',
  is_active: true,
};

/** "HH:MM", 24 h. */
export function isValidTime(value: string): boolean {
  return /^([01]\d|2[0-3]):[0-5]\d$/.test(value);
}

function TimeInput({ label, value, onChange, testID }: { label: string; value: string; onChange: (v: string) => void; testID: string }) {
  return (
    <View style={styles.flex}>
      <Text style={styles.inputLabel}>{label}</Text>
      <TextInput
        style={styles.input}
        value={value}
        onChangeText={onChange}
        placeholder="08:00"
        placeholderTextColor={C.faint}
        keyboardType="numbers-and-punctuation"
        maxLength={5}
        testID={testID}
        accessibilityLabel={label}
      />
    </View>
  );
}

export default function QuietHoursCard() {
  const query = useQuietHours();
  const update = useUpdateQuietHours();
  const [times, setTimes] = useState<Times>(DEFAULT_TIMES);
  const [message, setMessage] = useState<{ text: string; isError: boolean } | null>(null);

  useEffect(() => {
    if (query.data) {
      const { school_start, school_end, bedtime_start, bedtime_end, is_active } = query.data;
      setTimes({ school_start, school_end, bedtime_start, bedtime_end, is_active });
    }
  }, [query.data]);

  const set = (field: keyof Times, value: string | boolean) => {
    setMessage(null);
    setTimes((prev) => ({ ...prev, [field]: value }));
  };

  const save = () => {
    const values = [times.school_start, times.school_end, times.bedtime_start, times.bedtime_end];
    if (values.some((t) => t !== null && t !== '' && !isValidTime(t))) {
      setMessage({ text: S.invalidTime, isError: true });
      return;
    }
    const orNull = (t: string | null) => (t === '' ? null : t);
    update.mutate(
      {
        school_start: orNull(times.school_start),
        school_end: orNull(times.school_end),
        bedtime_start: orNull(times.bedtime_start),
        bedtime_end: orNull(times.bedtime_end),
        is_active: times.is_active,
      },
      {
        onSuccess: () => setMessage({ text: S.saved, isError: false }),
        onError: (err) => setMessage({ text: err instanceof ApiError ? S.saveError : S.offline, isError: true }),
      },
    );
  };

  return (
    <Card testID="quiet-hours">
      <SectionTitle
        right={
          <View style={styles.row}>
            <Text style={styles.muted}>{S.active}</Text>
            <Switch
              value={times.is_active}
              onValueChange={(v) => set('is_active', v)}
              trackColor={{ false: C.track, true: C.accent }}
              accessibilityLabel={S.active}
            />
          </View>
        }
      >
        {S.title}
      </SectionTitle>
      <Text style={styles.muted}>{S.hint}</Text>

      {query.isError && !query.data && (
        <ErrorBanner text={S.loadError} retryLabel={S.retry} onRetry={() => void query.refetch()} />
      )}

      <Text style={styles.group}>{S.school}</Text>
      <View style={styles.row}>
        <TimeInput label={S.start} value={times.school_start ?? ''} onChange={(v) => set('school_start', v)} testID="qh-school-start" />
        <TimeInput label={S.end} value={times.school_end ?? ''} onChange={(v) => set('school_end', v)} testID="qh-school-end" />
      </View>
      <Text style={styles.group}>{S.bedtime}</Text>
      <View style={styles.row}>
        <TimeInput label={S.start} value={times.bedtime_start ?? ''} onChange={(v) => set('bedtime_start', v)} testID="qh-bed-start" />
        <TimeInput label={S.end} value={times.bedtime_end ?? ''} onChange={(v) => set('bedtime_end', v)} testID="qh-bed-end" />
      </View>

      {message && (
        <Text style={[styles.message, message.isError && styles.messageError]} testID="qh-message">
          {message.text}
        </Text>
      )}

      <Pressable
        style={({ pressed }) => [styles.save, (update.isPending || query.isPending) && styles.disabled, pressed && styles.pressed]}
        onPress={save}
        disabled={update.isPending || query.isPending}
        accessibilityRole="button"
        testID="qh-save"
      >
        {update.isPending ? <ActivityIndicator color="#ffffff" /> : <Text style={styles.saveText}>{S.save}</Text>}
      </Pressable>
    </Card>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  muted: { fontSize: 13, color: C.muted, lineHeight: 18 },
  group: { fontSize: 13, fontWeight: '700', color: C.text, marginTop: 4 },
  inputLabel: { fontSize: 12, color: C.muted, marginBottom: 4 },
  input: {
    height: 46,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: C.border,
    backgroundColor: C.bg,
    paddingHorizontal: 12,
    fontSize: 16,
    color: C.text,
  },
  message: { fontSize: 13, color: C.greenText },
  messageError: { color: C.redText },
  save: {
    alignItems: 'center',
    justifyContent: 'center',
    minHeight: 46,
    borderRadius: 14,
    backgroundColor: C.accent,
  },
  saveText: { fontSize: 15, fontWeight: '700', color: '#ffffff' },
  disabled: { opacity: 0.5 },
  pressed: { opacity: 0.85 },
});
