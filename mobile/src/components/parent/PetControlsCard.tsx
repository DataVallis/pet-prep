/**
 * Controls of one family pet (M2-05 / M2-01a): who cares for it, its state, and the
 * hard stop (PRODUCT_SPEC §9: red button with confirmation).
 *
 * Safety (PR #20 review M1): opening the confirmation records the parent's INTENT
 * (stop / resume) and the request SETS that state (`{pet_id, active}`, idempotent on
 * the server) — never a blind toggle. If the pet already reaches the intended state
 * while the confirmation is open (another parent, a broadcast, a refetch), the box
 * closes and nothing is sent. A ref guards against a double tap. After a network
 * error (lost response?) the dashboard is refetched before the parent can retry, so
 * a stop that did land is shown instead of being sent again.
 */

import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, View } from 'react-native';
import { Text } from '@/components/ui/Text';
import { PauseCircle, PlayCircle } from 'lucide-react-native';
import { useQueryClient } from '@tanstack/react-query';

import { ApiError } from '@/api/client';
import { useSetHardStop } from '@/hooks/queries/useParentQueries';
import { parentDashboardKey } from '@/modules/family/live';
import { Card, PARENT_COLORS as C } from '@/components/parent/ParentUi';
import {
  PET_STATUS_LABELS,
  breedLabel,
  caretakerNames,
  petStatus,
  type FamilyOverview,
  type FamilyPet,
} from '@/modules/family/family';
import { palette } from '@/theme';
import { t } from '@/i18n';
import { strings } from '@/i18n/strings';


/** All user-visible strings (`parent:petControls`, M1-18). */
export const PET_CONTROLS_STRINGS = strings('parent', 'petControls', {
  caretakers: (names: string) => t('parent:petControls.caretakers', { names }),
  a11y: (action: string, pet: string, names: string) =>
    names ? t('parent:petControls.a11yNames', { action, pet, names }) : t('parent:petControls.a11y', { action, pet }),
  confirmStop: (names: string) =>
    names ? t('parent:petControls.confirmStopFor', { names }) : t('parent:petControls.confirmStop'),
});

const S = PET_CONTROLS_STRINGS;

function errorText(error: unknown): string {
  if (!(error instanceof ApiError)) return S.errors.offline;
  return error.status === 404 ? S.errors.not_found : S.errors.server;
}

export default function PetControlsCard({ pet, family }: { pet: FamilyPet; family: FamilyOverview }) {
  const queryClient = useQueryClient();
  const setHardStop = useSetHardStop();
  /** null = no confirmation open; true / false = the state the parent wants. */
  const [intent, setIntent] = useState<boolean | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  // Message as a thunk: translated at render, so it follows a language switch (M1-18 review).
  const [result, setResult] = useState<{ text: () => string; isError: boolean } | null>(null);
  const inFlight = useRef(false);
  const names = caretakerNames(pet, family);
  const status = petStatus(pet);
  const stopped = pet.is_hard_stopped;
  const petName = breedLabel(pet.breed_type, pet.species);
  const canControl = pet.is_active && !pet.is_game_over;

  // The pet reached the intended state by other means → nothing left to send.
  useEffect(() => {
    if (intent === null || inFlight.current) return;
    if (stopped === intent) {
      setIntent(null);
      setResult({ text: () => intent ? S.alreadyStopped : S.alreadyRunning, isError: false });
    }
  }, [intent, stopped]);

  const open = () => {
    setResult(null);
    setIntent(!stopped);
  };

  const send = () => {
    if (intent === null || inFlight.current || refreshing) return;
    if (stopped === intent) {
      setIntent(null);
      setResult({ text: () => intent ? S.alreadyStopped : S.alreadyRunning, isError: false });
      return;
    }
    inFlight.current = true;
    setResult(null);
    setHardStop.mutate(
      { petId: pet.id, active: intent },
      {
        onSuccess: (res) => {
          setIntent(null);
          setResult({ text: () => res.is_hard_stopped ? S.stopped : S.resumed, isError: false });
        },
        onError: (err) => {
          setResult({ text: () => errorText(err), isError: true });
          if (!(err instanceof ApiError)) {
            // The request may have landed (lost response): show the real state before a retry.
            setRefreshing(true);
            void queryClient
              .refetchQueries({ queryKey: parentDashboardKey })
              .finally(() => setRefreshing(false));
          }
        },
        onSettled: () => {
          inFlight.current = false;
        },
      },
    );
  };

  const busy = setHardStop.isPending || refreshing;

  return (
    <Card testID={`pet-controls-${pet.id}`}>
      <View>
        <Text style={styles.title}>{petName}</Text>
        <Text style={styles.muted} testID={`pet-caretakers-${pet.id}`}>
          {names ? S.caretakers(names) : S.noCaretakers}
        </Text>
        <Text style={[styles.status, stopped && styles.statusStopped]} testID={`pet-status-${pet.id}`}>
          {status ? PET_STATUS_LABELS[status] : S.playing}
        </Text>
      </View>

      {canControl &&
        (intent !== null ? (
          <View style={[styles.confirmBox, intent && styles.confirmDanger]} testID={`hard-stop-confirm-${pet.id}`}>
            <Text style={styles.confirmText}>{intent ? S.confirmStop(names) : S.confirmResume}</Text>
            {refreshing && <Text style={styles.muted}>{S.checking}</Text>}
            <View style={styles.row}>
              <Pressable
                style={({ pressed }) => [styles.button, styles.flex, pressed && styles.pressed]}
                onPress={() => setIntent(null)}
                disabled={setHardStop.isPending}
                accessibilityRole="button"
              >
                <Text style={styles.buttonText}>{S.cancel}</Text>
              </Pressable>
              <Pressable
                style={({ pressed }) => [
                  styles.button,
                  styles.flex,
                  intent ? styles.danger : styles.primary,
                  busy && styles.disabled,
                  pressed && styles.pressed,
                ]}
                onPress={send}
                disabled={busy}
                accessibilityRole="button"
                accessibilityState={{ disabled: busy, busy }}
                accessibilityLabel={S.a11y(S.confirm, petName, names)}
                testID={`hard-stop-confirm-button-${pet.id}`}
              >
                {setHardStop.isPending ? (
                  <ActivityIndicator color={palette.white} />
                ) : (
                  <Text style={[styles.buttonText, styles.whiteText]}>{S.confirm}</Text>
                )}
              </Pressable>
            </View>
          </View>
        ) : (
          <Pressable
            style={({ pressed }) => [styles.button, stopped ? styles.outline : styles.danger, pressed && styles.pressed]}
            onPress={open}
            accessibilityRole="button"
            accessibilityLabel={S.a11y(stopped ? S.resume : S.stop, petName, names)}
            testID={`hard-stop-${pet.id}`}
          >
            {stopped ? <PlayCircle color={C.accent} size={18} /> : <PauseCircle color={palette.white} size={18} />}
            <Text style={[styles.buttonText, !stopped && styles.whiteText]}>{stopped ? S.resume : S.stop}</Text>
          </Pressable>
        ))}

      {result && (
        <Text style={[styles.result, result.isError && styles.resultError]} testID={`hard-stop-result-${pet.id}`}>
          {result.text()}
        </Text>
      )}
    </Card>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  row: { flexDirection: 'row', gap: 8 },
  title: { fontSize: 16, fontWeight: '800', color: C.text },
  muted: { fontSize: 13, color: C.muted, marginTop: 2 },
  status: { fontSize: 13, fontWeight: '600', color: C.greenText, marginTop: 4 },
  statusStopped: { color: C.redText },
  button: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    minHeight: 46,
    borderRadius: 14,
    paddingHorizontal: 14,
    borderWidth: 1,
    borderColor: C.border,
    backgroundColor: C.bg,
  },
  outline: { backgroundColor: C.accentSoft, borderColor: palette.mintBorder },
  danger: { backgroundColor: palette.danger, borderColor: palette.danger },
  primary: { backgroundColor: C.accent, borderColor: C.accent },
  disabled: { opacity: 0.5 },
  buttonText: { fontSize: 14, fontWeight: '700', color: C.accent },
  whiteText: { color: palette.white },
  confirmBox: {
    gap: 10,
    padding: 12,
    borderRadius: 14,
    backgroundColor: C.accentSoft,
    borderWidth: 1,
    borderColor: palette.mintBorder,
  },
  confirmDanger: { backgroundColor: C.redSoft, borderColor: palette.dangerBorder },
  confirmText: { fontSize: 13, lineHeight: 18, color: C.text },
  result: { fontSize: 13, color: C.greenText },
  resultError: { color: C.redText },
  pressed: { opacity: 0.85 },
});
