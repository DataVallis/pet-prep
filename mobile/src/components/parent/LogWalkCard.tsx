/**
 * M5-T12 »Vpiši sprehod« / "Log a walk" (David 2026-10-21): in adapted mode the PARENT can
 * enter walk minutes for the child — on iPhone pushes are recorded automatically only by an
 * Apple Watch, so the child needs to do nothing. Today or yesterday (family time zone, default
 * Europe/Ljubljana), 1–240 minutes, at most 240 logged minutes per child and day in total; the
 * server counts minutes × 100 steps (the step-goal rule) on top of any pushes from the phone.
 * A retry of the identical entry (same minutes and day) reuses its entry id, so it never counts
 * twice; changed minutes or day are a new entry with a new id.
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
  dailyLimit: (remaining: number) => t('family:children.logWalk.dailyLimit', { count: remaining }),
});

export const MAX_WALK_MINUTES = 240;

/** "30" → 30; anything outside 1–240 or not a whole number → null. */
export function parseWalkMinutes(text: string): number | null {
  const trimmed = text.trim();
  if (!/^\d{1,3}$/.test(trimmed)) return null;
  const n = Number(trimmed);
  return n >= 1 && n <= MAX_WALK_MINUTES ? n : null;
}

/** The family default zone (`families.timezone` default) when the family has none set. */
export const FAMILY_DEFAULT_TIMEZONE = 'Europe/Ljubljana';

/**
 * Family-local today and yesterday ("Y-m-d"). A null family zone falls back to the family
 * default (Europe/Ljubljana, as on the server) — never the device zone.
 */
export function walkDates(timezone: string | null, nowMs: number = Date.now()): { today: string; yesterday: string } {
  const cal = familyCalendar(timezone ?? FAMILY_DEFAULT_TIMEZONE, null);
  const today = cal.dateOf(nowMs);
  return { today, yesterday: cal.dateOf(cal.startOfDay(nowMs) - 1) };
}

type ErrorKind = 'adapted_mode_off' | 'date_not_allowed' | 'daily_limit_reached' | 'entry_id_conflict' | 'locked' | 'other';

interface WalkError {
  kind: ErrorKind;
  /** `daily_limit_reached`: minutes still allowed that day. */
  remaining: number | null;
}

function walkError(error: unknown): WalkError {
  if (error instanceof ApiError) {
    const data = error.data as { reason?: unknown; remaining_minutes?: unknown } | undefined;
    const reason = data?.reason;
    if (reason === 'daily_limit_reached') {
      return { kind: reason, remaining: typeof data?.remaining_minutes === 'number' ? data.remaining_minutes : null };
    }
    if (reason === 'adapted_mode_off' || reason === 'date_not_allowed' || reason === 'entry_id_conflict') return { kind: reason, remaining: null };
    if (error.status === 423) return { kind: 'locked', remaining: null };
  }
  return { kind: 'other', remaining: null };
}

/** What one request sent — a retry reuses the entry id only for an identical entry. */
interface SentEntry {
  entryId: string;
  minutes: number;
  day: 'today' | 'yesterday';
}

/**
 * The entry id for this submit: the failed request's id when minutes and day are the same
 * (a retry of the same walk — the server counts it once), otherwise a new one.
 */
export function entryIdFor(failed: SentEntry | null, minutes: number, day: 'today' | 'yesterday', makeId: () => string = newWalkEntryId): string {
  return failed !== null && failed.minutes === minutes && failed.day === day ? failed.entryId : makeId();
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
  // The last request that failed: re-sending the SAME minutes and day reuses its entry id
  // (never counted twice); any change is a new entry with a new id.
  const [failed, setFailed] = useState<SentEntry | null>(null);
  const [saved, setSaved] = useState<number | null>(null);
  const [error, setError] = useState<WalkError | null>(null);
  const parsed = parseWalkMinutes(minutes);

  const submit = () => {
    if (parsed === null || mutation.isPending) return;
    const dates = walkDates(timezone);
    const sent: SentEntry = { entryId: entryIdFor(failed, parsed, day), minutes: parsed, day };
    setError(null);
    setSaved(null);
    mutation.mutate(
      { childId, minutes: parsed, localDate: day === 'today' ? dates.today : dates.yesterday, entryId: sent.entryId },
      {
        onSuccess: (res) => {
          setSaved(res.entry.minutes);
          setMinutes('');
          setFailed(null);
        },
        onError: (e) => {
          const err = walkError(e);
          setError(err);
          // Nothing was counted: an identical retry may reuse the id — except after a
          // conflict (that id already belongs to another walk).
          setFailed(err.kind === 'entry_id_conflict' ? null : sent);
        },
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
          {error.kind === 'daily_limit_reached' && error.remaining !== null
            ? LOG_WALK_STRINGS.dailyLimit(error.remaining)
            : S.errors[error.kind]}
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
