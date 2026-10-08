/**
 * Family quiet hours (school + bedtime, family-local "HH:MM"; one configuration per
 * family, any parent may edit — M1-03 / M2-01). Moved to TanStack Query and the
 * light parent theme with M2-05.
 *
 * Shows server truth (fix/quiet-hours-default, 2026-10-08): the server creates
 * every family's quiet hours (night 21:00–07:00, active). If it still answers
 * `quiet_hours: null` (older server, edge case) the form is pre-filled with the
 * same defaults but clearly marked "not saved yet" — before, it silently looked
 * configured while the server had nothing and sent pushes at night.
 */

import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Switch, View } from 'react-native';
import { Text, TextInput } from '@/components/ui/Text';

import { ApiError } from '@/api/client';
import { useQuietHours, useUpdateQuietHours } from '@/hooks/queries/useParentQueries';
import { Card, ErrorBanner, PARENT_COLORS as C, SectionTitle } from '@/components/parent/ParentUi';
import type { QuietHours } from '@/types';
import { palette } from '@/theme';
import { strings } from '@/i18n/strings';


/** All user-visible strings (`parent:quietHours`, M1-18). */
export const QUIET_HOURS_STRINGS = strings('parent', 'quietHours');

const S = QUIET_HOURS_STRINGS;

type Times = Pick<QuietHours, 'school_start' | 'school_end' | 'bedtime_start' | 'bedtime_end' | 'is_active'>;

/** Same values as the server default (`QuietHours::DEFAULTS`): night only, active. */
export const DEFAULT_TIMES: Times = {
  school_start: null,
  school_end: null,
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
  // Loaded, and the server has no quiet hours for this family: nothing is in force yet.
  const notSaved = query.isSuccess && query.data === null;
  // Message as a thunk: translated at render, so it follows a language switch (M1-18 review).
  const [message, setMessage] = useState<{ text: () => string; isError: boolean } | null>(null);

  // Seed the form from the server whenever the stored hours change (first load, after
  // a save, another parent's edit). Nothing polls this query, so a parent's unsaved
  // typing is only replaced by a deliberate refetch (e.g. after joining a family).
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
      setMessage({ text: () => S.invalidTime, isError: true });
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
        onSuccess: () => setMessage({ text: () => S.saved, isError: false }),
        onError: (err) => setMessage({ text: () => err instanceof ApiError ? S.saveError : S.offline, isError: true }),
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
              trackColor={{ false: C.track, true: palette.mintDeep }}
              accessibilityLabel={S.active}
            />
          </View>
        }
      >
        {S.title}
      </SectionTitle>
      <Text style={styles.muted}>{S.hint}</Text>

      {notSaved && (
        <View style={styles.notSaved} testID="qh-not-saved" accessibilityRole="alert">
          <Text style={styles.notSavedTitle}>{S.notSavedTitle}</Text>
          <Text style={styles.notSavedText}>{S.notSavedText}</Text>
        </View>
      )}

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
          {message.text()}
        </Text>
      )}

      <Pressable
        style={({ pressed }) => [styles.save, (update.isPending || query.isPending) && styles.disabled, pressed && styles.pressed]}
        onPress={save}
        disabled={update.isPending || query.isPending}
        accessibilityRole="button"
        testID="qh-save"
      >
        {update.isPending ? <ActivityIndicator color={palette.white} /> : <Text style={styles.saveText}>{S.save}</Text>}
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
  notSaved: { borderRadius: 12, padding: 12, gap: 2, backgroundColor: C.yellowSoft },
  notSavedTitle: { fontSize: 14, fontWeight: '700', color: C.text },
  notSavedText: { fontSize: 13, color: C.text, lineHeight: 18 },
  message: { fontSize: 13, color: C.greenText },
  messageError: { color: C.redText },
  save: {
    alignItems: 'center',
    justifyContent: 'center',
    minHeight: 46,
    borderRadius: 14,
    backgroundColor: C.accent,
  },
  saveText: { fontSize: 15, fontWeight: '700', color: palette.white },
  disabled: { opacity: 0.5 },
  pressed: { opacity: 0.85 },
});
