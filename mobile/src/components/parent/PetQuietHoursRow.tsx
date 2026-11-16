/**
 * »Tihe ure: Kot družina / Lastne ure« in a pet's card in Nadzor (David 2026-11-16, choice 1:
 * family default + own hours if wanted — the per-pet time zone pattern). »Kot družina« = the
 * pet follows the family's quiet hours (summary shown); »Lastne ure« opens the same editor as
 * the family card, prefilled with the hours the pet follows today, saved only on »Shrani«.
 * Back to »Kot družina« saves `null` at once. A change of the family's hours never moves a
 * pet with its own. Hidden for an older server (no `quiet_hours_follow_family` on the pet).
 */

import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, View } from 'react-native';

import { ApiError } from '@/api/client';
import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import QuietHoursForm from '@/components/parent/QuietHoursForm';
import { Text } from '@/components/ui/Text';
import { useUpdatePetQuietHours } from '@/hooks/queries/useParentQueries';
import { t } from '@/i18n';
import type { FamilyPet } from '@/modules/family/family';
import {
  DEFAULT_QUIET_TIMES,
  quietSummary,
  quietTimesOf,
  readPetQuietHours,
  supportsPetQuietHours,
  type QuietTimes,
} from '@/modules/quietHours/quietHours';
import { useTimeFormat } from '@/modules/time/clock';
import { MIN_TOUCH, palette, radius } from '@/theme';

type Mode = 'family' | 'own';

export default function PetQuietHoursRow({ pet }: { pet: FamilyPet }) {
  useTimeFormat();
  const update = useUpdatePetQuietHours();
  const state = readPetQuietHours(pet);
  const current = state.times ?? DEFAULT_QUIET_TIMES;
  const [mode, setMode] = useState<Mode>(state.followsFamily ? 'family' : 'own');
  const [draft, setDraft] = useState<QuietTimes>(current);
  const [message, setMessage] = useState<{ text: () => string; isError: boolean } | null>(null);
  const key = JSON.stringify([state.followsFamily, current]);

  // Server truth wins whenever the pet's quiet hours change (save, another parent, a broadcast).
  useEffect(() => {
    setMode(state.followsFamily ? 'family' : 'own');
    setDraft(current);
    // eslint-disable-next-line react-hooks/exhaustive-deps -- `key` is the content of both
  }, [key]);

  if (!supportsPetQuietHours(pet)) return null;

  const id = `pqh-${pet.id}`;
  const errorText = (err: unknown) => () => (err instanceof ApiError ? t('parent:quietHours.pet.saveError') : t('parent:quietHours.pet.offline'));

  const followFamily = () => {
    setMessage(null);
    if (state.followsFamily) {
      setMode('family');
      return;
    }
    update.mutate(
      { petId: pet.id, quietHours: null },
      {
        onSuccess: () => setMessage({ text: () => t('parent:quietHours.pet.saved'), isError: false }),
        onError: (err) => setMessage({ text: errorText(err), isError: true }),
      },
    );
  };

  const save = () => {
    setMessage(null);
    update.mutate(
      { petId: pet.id, quietHours: quietTimesOf(draft) },
      {
        onSuccess: () => setMessage({ text: () => t('parent:quietHours.pet.saved'), isError: false }),
        onError: (err) => setMessage({ text: errorText(err), isError: true }),
      },
    );
  };

  const option = (value: Mode, label: string, onPress: () => void) => {
    const selected = mode === value;
    return (
      <Pressable
        onPress={onPress}
        disabled={update.isPending}
        style={({ pressed }) => [styles.pill, selected && styles.pillActive, pressed && styles.pressed]}
        accessibilityRole="radio"
        accessibilityState={{ selected, disabled: update.isPending }}
        testID={`${id}-${value}`}
      >
        <Text style={[styles.pillText, selected && styles.pillTextActive]}>{label}</Text>
      </Pressable>
    );
  };

  return (
    <View style={styles.root} testID={id}>
      <Text style={styles.title}>{t('parent:quietHours.pet.title')}</Text>
      <View style={styles.row} accessibilityRole="radiogroup" accessibilityLabel={t('parent:quietHours.pet.choice')}>
        {option('family', t('parent:quietHours.pet.family'), followFamily)}
        {option('own', t('parent:quietHours.pet.own'), () => {
          setMessage(null);
          setMode('own');
        })}
      </View>

      {mode === 'family' ? (
        <Text style={styles.muted} testID={`${id}-summary`}>
          {t('parent:quietHours.pet.followHint', { summary: quietSummary(current) })}
        </Text>
      ) : (
        <>
          <Text style={styles.muted}>{t('parent:quietHours.pet.ownHint')}</Text>
          <QuietHoursForm value={draft} onChange={(next) => { setMessage(null); setDraft(next); }} testID={id} showActive />
          <Pressable
            style={({ pressed }) => [styles.save, update.isPending && styles.disabled, pressed && styles.pressed]}
            onPress={save}
            disabled={update.isPending}
            accessibilityRole="button"
            testID={`${id}-save`}
          >
            {update.isPending ? <ActivityIndicator color={palette.white} /> : <Text style={styles.saveText}>{t('parent:quietHours.save')}</Text>}
          </Pressable>
        </>
      )}

      {message && (
        <Text style={[styles.message, message.isError && styles.messageError]} testID={`${id}-message`}>
          {message.text()}
        </Text>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  root: { gap: 8, paddingTop: 4 },
  title: { fontSize: 13, fontWeight: '700', color: C.text },
  row: { flexDirection: 'row', gap: 8 },
  pill: {
    flex: 1,
    minHeight: MIN_TOUCH,
    borderRadius: radius.button,
    borderWidth: 1,
    borderColor: C.border,
    backgroundColor: C.bg,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 8,
  },
  pillActive: { backgroundColor: C.accent, borderColor: C.accent },
  pillText: { fontSize: 14, fontWeight: '700', color: C.text },
  pillTextActive: { color: C.card },
  muted: { fontSize: 13, color: C.muted, lineHeight: 18 },
  save: { alignItems: 'center', justifyContent: 'center', minHeight: 46, borderRadius: 14, backgroundColor: C.accent },
  saveText: { fontSize: 15, fontWeight: '700', color: palette.white },
  message: { fontSize: 13, color: C.greenText },
  messageError: { color: C.redText },
  disabled: { opacity: 0.5 },
  pressed: { opacity: 0.85 },
});
