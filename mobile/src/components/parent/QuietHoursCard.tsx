/**
 * Family quiet hours (school + bedtime, "HH:MM"; one configuration per family, any parent
 * may edit — M1-03 / M2-01) = the default of every pet without its own (David 2026-11-16,
 * per-pet »Lastne ure« in `PetQuietHoursRow`). Times are set with the alarm-clock wheels
 * (`QuietHoursForm`, no typing). Moved to TanStack Query and the light parent theme with M2-05.
 *
 * Shows server truth (fix/quiet-hours-default, 2026-10-08): the server creates
 * every family's quiet hours (night 21:00–07:00, active) and answers with what
 * it applies. `saved: false` (defaults, no stored row) or `quiet_hours: null`
 * (older server) → the form shows the defaults with "Default times apply — tap
 * Save to confirm or change them". Before, the card silently looked configured
 * while the server had nothing and sent pushes at night.
 */

import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Switch, View } from 'react-native';
import { Text } from '@/components/ui/Text';

import { ApiError } from '@/api/client';
import { useQuietHours, useUpdateQuietHours } from '@/hooks/queries/useParentQueries';
import { Card, ErrorBanner, PARENT_COLORS as C, SectionTitle } from '@/components/parent/ParentUi';
import QuietHoursForm from '@/components/parent/QuietHoursForm';
import { DEFAULT_QUIET_TIMES, quietTimesOf, readSchoolDays, type QuietTimes } from '@/modules/quietHours/quietHours';
import { palette } from '@/theme';
import { strings } from '@/i18n/strings';
import { currentLanguage } from '@/i18n';
import { phoneDiffersFromFamily, zoneCity } from '@/modules/familyZone/familyZone';
import { FAMILY_ZONE_STRINGS } from '@/modules/familyZone/strings';
import { usePhoneZone } from '@/modules/familyZone/usePhoneZone';


/** All user-visible strings (`parent:quietHours`, M1-18). */
export const QUIET_HOURS_STRINGS = strings('parent', 'quietHours');

const S = QUIET_HOURS_STRINGS;

type Times = QuietTimes;

/** Same values as the server default (`QuietHours::DEFAULTS`): night only, active. */
export const DEFAULT_TIMES: Times = DEFAULT_QUIET_TIMES;

interface QuietHoursCardProps {
  /**
   * The family zone (dashboard `family.timezone`, else the quiet-hours answer). When the phone's
   * clock differs from it, a small »po času družine (Ljubljana)« note says how the times are read
   * (David 2026-11-08).
   */
  familyZone?: string | null;
  /**
   * True when any pet of the family has its own zone (David 2026-11-09): the windows are read on
   * each pet's clock, so the note says »po uri vsakega ljubljenčka« instead (QA #891 m3).
   */
  perPetZones?: boolean;
}

export default function QuietHoursCard({ familyZone = null, perPetZones = false }: QuietHoursCardProps) {
  const query = useQuietHours();
  const phoneZone = usePhoneZone();
  const update = useUpdateQuietHours();
  const [times, setTimes] = useState<Times>(DEFAULT_TIMES);
  // Loaded, and no parent has saved quiet hours yet (server defaults, or an older server's null).
  const notSaved = query.isSuccess && (query.data === null || query.data.saved === false);
  // Message as a thunk: translated at render, so it follows a language switch (M1-18 review).
  const [message, setMessage] = useState<{ text: () => string; isError: boolean } | null>(null);

  // Seed the form from the server whenever the stored hours change (first load, after
  // a save, another parent's edit). Nothing polls this query, so a parent's unsaved
  // typing is only replaced by a deliberate refetch (e.g. after joining a family).
  useEffect(() => {
    if (query.data) {
      const { school_start, school_end, bedtime_start, bedtime_end, is_active, school_days } = query.data;
      // No `school_days` (older server) → null: chips hidden, the field is never sent.
      setTimes({ school_start, school_end, bedtime_start, bedtime_end, is_active, school_days: readSchoolDays(school_days) });
    }
  }, [query.data]);

  const setActive = (value: boolean) => {
    setMessage(null);
    setTimes((prev) => ({ ...prev, is_active: value }));
  };

  const save = () => {
    update.mutate(
      quietTimesOf(times),
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
              onValueChange={setActive}
              trackColor={{ false: C.track, true: palette.mintDeep }}
              accessibilityLabel={S.active}
            />
          </View>
        }
      >
        {S.title}
      </SectionTitle>
      <Text style={styles.muted}>{S.hint}</Text>
      {perPetZones ? (
        <Text style={styles.zoneNote} testID="qh-each-pet-time">
          {FAMILY_ZONE_STRINGS.eachPetTimeNote}
        </Text>
      ) : familyZone !== null && phoneDiffersFromFamily(familyZone, phoneZone, Date.now()) && (
        <Text style={styles.zoneNote} testID="qh-family-time">
          {FAMILY_ZONE_STRINGS.familyTimeNote(zoneCity(familyZone, currentLanguage()))}
        </Text>
      )}

      {notSaved && (
        <View style={styles.notSaved} testID="qh-not-saved" accessibilityRole="alert">
          <Text style={styles.notSavedText}>{S.defaultsApply}</Text>
        </View>
      )}

      {query.isError && !query.data && (
        <ErrorBanner text={S.loadError} retryLabel={S.retry} onRetry={() => void query.refetch()} />
      )}

      <QuietHoursForm
        value={times}
        onChange={(next) => {
          setMessage(null);
          setTimes(next);
        }}
        testID="qh"
      />

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
  row: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  muted: { fontSize: 13, color: C.muted, lineHeight: 18 },
  zoneNote: { fontSize: 13, fontWeight: '700', color: C.text },
  notSaved: { borderRadius: 12, padding: 12, backgroundColor: C.yellowSoft },
  notSavedText: { fontSize: 13, fontWeight: '600', color: C.text, lineHeight: 18 },
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
