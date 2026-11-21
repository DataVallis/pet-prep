/**
 * M5-08 dashboard card »Kje ste izvedeli za PetPrep?« for parents who signed up before the
 * question existed. Shown once: only while `GET /api/user` says `acquisition_source: null`
 * (missing key = older server → never), and never again on this phone after »Ne zdaj«, an answer,
 * or the question at sign-up here (`acquisitionPrefs.ts`). »Shrani« → `PATCH /api/parent/settings`;
 * a failure keeps the card with an error, no retry loop.
 */

import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, View } from 'react-native';

import AcquisitionPicker from '@/components/auth/AcquisitionPicker';
import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { Text } from '@/components/ui/Text';
import { ApiError } from '@/api/client';
import { useParentAccount, useSaveAcquisition } from '@/hooks/queries/useValidationQueries';
import {
  ACQUISITION_STRINGS as S,
  acquisitionFields,
  EMPTY_ACQUISITION,
  hasAnswer,
  shouldOfferAcquisitionCard,
  type AcquisitionChoice,
} from '@/modules/validation/acquisition';
import { markAcquisitionPromptDone, useAcquisitionPromptDone } from '@/modules/validation/acquisitionPrefs';
import { fonts, MIN_TOUCH, radius, tightTracking } from '@/theme';

export default function AcquisitionCard({ userId }: { userId: number }) {
  const prefs = useAcquisitionPromptDone(userId);
  const account = useParentAccount(prefs.loaded && !prefs.dismissed);
  const save = useSaveAcquisition();
  const [choice, setChoice] = useState<AcquisitionChoice>(EMPTY_ACQUISITION);
  const [error, setError] = useState<string | null>(null);

  if (!shouldOfferAcquisitionCard({ user: account.data, prefsLoaded: prefs.loaded, dismissed: prefs.dismissed })) return null;

  const busy = save.isPending;
  const submit = async () => {
    if (busy || !hasAnswer(choice)) return;
    setError(null);
    try {
      await save.mutateAsync(acquisitionFields(choice));
      void markAcquisitionPromptDone(userId);
    } catch (err) {
      setError(err instanceof ApiError ? S.cardError : S.cardOffline);
    }
  };

  return (
    <View style={styles.card} testID="acquisition-card">
      <Text style={styles.title} accessibilityRole="header">
        {S.title}
      </Text>
      <Text style={styles.body}>{S.cardBody}</Text>
      <AcquisitionPicker value={choice} onChange={setChoice} disabled={busy} showTitle={false} testID="acquisition-card-picker" />
      {error && (
        <Text style={styles.error} accessibilityRole="alert" testID="acquisition-card-error">
          {error}
        </Text>
      )}
      <View style={styles.actions}>
        <Pressable
          style={({ pressed }) => [styles.secondary, pressed && styles.pressed]}
          onPress={() => void markAcquisitionPromptDone(userId)}
          disabled={busy}
          accessibilityRole="button"
          testID="acquisition-card-close"
        >
          <Text style={styles.secondaryText}>{S.cardClose}</Text>
        </Pressable>
        <Pressable
          style={({ pressed }) => [styles.primary, (busy || !hasAnswer(choice)) && styles.disabled, pressed && styles.pressed]}
          onPress={() => void submit()}
          disabled={busy || !hasAnswer(choice)}
          accessibilityRole="button"
          accessibilityState={{ disabled: busy || !hasAnswer(choice), busy }}
          testID="acquisition-card-save"
        >
          {busy ? <ActivityIndicator color={C.card} /> : <Text style={styles.primaryText}>{S.cardSave}</Text>}
        </Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  card: { borderRadius: radius.card, padding: 16, gap: 10, backgroundColor: C.card, borderWidth: 1, borderColor: C.border },
  title: { fontFamily: fonts.display, fontSize: 18, letterSpacing: tightTracking(18), color: C.text },
  body: { fontSize: 13, lineHeight: 18, color: C.muted },
  error: { fontSize: 13, color: C.redText },
  actions: { flexDirection: 'row', justifyContent: 'flex-end', gap: 10 },
  primary: { minHeight: MIN_TOUCH, paddingHorizontal: 16, borderRadius: 14, backgroundColor: C.accent, alignItems: 'center', justifyContent: 'center' },
  primaryText: { fontSize: 14, fontWeight: '700', color: C.card },
  secondary: { minHeight: MIN_TOUCH, paddingHorizontal: 12, alignItems: 'center', justifyContent: 'center' },
  secondaryText: { fontSize: 14, fontWeight: '600', color: C.muted },
  disabled: { opacity: 0.5 },
  pressed: { opacity: 0.85 },
});
