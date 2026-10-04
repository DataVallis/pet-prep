/**
 * Controls of one family pet (M2-05 / M2-01a): who cares for it, its state, and the
 * hard stop (PRODUCT_SPEC §9: red button with confirmation). The endpoint toggles,
 * so the screen always confirms in-app first and sends the pet's id — with several
 * pets, the parent stops exactly this one (every caretaker child is locked).
 */

import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { PauseCircle, PlayCircle } from 'lucide-react-native';

import { ApiError } from '@/api/client';
import { useToggleHardStop } from '@/hooks/queries/useParentQueries';
import { Card, PARENT_COLORS as C } from '@/components/parent/ParentUi';
import {
  PET_STATUS_LABELS,
  breedLabel,
  caretakerNames,
  petStatus,
  type FamilyOverview,
  type FamilyPet,
} from '@/modules/family/family';

export const PET_CONTROLS_STRINGS = {
  caretakers: (names: string) => `Skrbi: ${names}`,
  noCaretakers: 'Še nihče ne skrbi zanj',
  playing: 'Igra teče',
  stop: 'Ustavi igro (hard stop)',
  resume: 'Nadaljuj igro',
  confirmStop: (names: string) =>
    `Ustaviti igro${names ? ` za ${names}` : ''}? Kuža se zamrzne in otrok ne more ničesar narediti, dokler igre ne nadaljujete.`,
  confirmResume: 'Nadaljevati igro? Kuža se odmrzne in otrok lahko spet skrbi zanj.',
  confirm: 'Potrdi',
  cancel: 'Prekliči',
  stopped: 'Igra je ustavljena.',
  resumed: 'Igra spet teče.',
  errors: {
    offline: 'Ni povezave s strežnikom. Poskusite znova.',
    not_found: 'Tega psa ni več v vaši družini.',
    server: 'Ukaz ni uspel. Poskusite znova.',
  },
} as const;

const S = PET_CONTROLS_STRINGS;

function errorText(error: unknown): string {
  if (!(error instanceof ApiError)) return S.errors.offline;
  return error.status === 404 ? S.errors.not_found : S.errors.server;
}

export default function PetControlsCard({ pet, family }: { pet: FamilyPet; family: FamilyOverview }) {
  const toggle = useToggleHardStop();
  const [confirming, setConfirming] = useState(false);
  const [result, setResult] = useState<{ text: string; isError: boolean } | null>(null);
  const names = caretakerNames(pet, family);
  const status = petStatus(pet);
  const stopped = pet.is_hard_stopped;

  const send = () => {
    setResult(null);
    toggle.mutate(pet.id, {
      onSuccess: (res) => {
        setConfirming(false);
        setResult({ text: res.is_hard_stopped ? S.stopped : S.resumed, isError: false });
      },
      onError: (err) => setResult({ text: errorText(err), isError: true }),
    });
  };

  return (
    <Card testID={`pet-controls-${pet.id}`}>
      <View>
        <Text style={styles.title}>{breedLabel(pet.breed_type)}</Text>
        <Text style={styles.muted} testID={`pet-caretakers-${pet.id}`}>
          {names ? S.caretakers(names) : S.noCaretakers}
        </Text>
        <Text style={[styles.status, stopped && styles.statusStopped]} testID={`pet-status-${pet.id}`}>
          {status ? PET_STATUS_LABELS[status] : S.playing}
        </Text>
      </View>

      {!pet.is_game_over &&
        (confirming ? (
          <View style={[styles.confirmBox, !stopped && styles.confirmDanger]} testID={`hard-stop-confirm-${pet.id}`}>
            <Text style={styles.confirmText}>{stopped ? S.confirmResume : S.confirmStop(names)}</Text>
            <View style={styles.row}>
              <Pressable
                style={({ pressed }) => [styles.button, styles.flex, pressed && styles.pressed]}
                onPress={() => setConfirming(false)}
                disabled={toggle.isPending}
                accessibilityRole="button"
              >
                <Text style={styles.buttonText}>{S.cancel}</Text>
              </Pressable>
              <Pressable
                style={({ pressed }) => [styles.button, styles.flex, stopped ? styles.primary : styles.danger, pressed && styles.pressed]}
                onPress={send}
                disabled={toggle.isPending}
                accessibilityRole="button"
                testID={`hard-stop-confirm-button-${pet.id}`}
              >
                {toggle.isPending ? (
                  <ActivityIndicator color="#ffffff" />
                ) : (
                  <Text style={[styles.buttonText, styles.whiteText]}>{S.confirm}</Text>
                )}
              </Pressable>
            </View>
          </View>
        ) : (
          <Pressable
            style={({ pressed }) => [styles.button, stopped ? styles.outline : styles.danger, pressed && styles.pressed]}
            onPress={() => {
              setResult(null);
              setConfirming(true);
            }}
            accessibilityRole="button"
            testID={`hard-stop-${pet.id}`}
          >
            {stopped ? <PlayCircle color={C.accent} size={18} /> : <PauseCircle color="#ffffff" size={18} />}
            <Text style={[styles.buttonText, !stopped && styles.whiteText]}>{stopped ? S.resume : S.stop}</Text>
          </Pressable>
        ))}

      {result && (
        <Text style={[styles.result, result.isError && styles.resultError]} testID={`hard-stop-result-${pet.id}`}>
          {result.text}
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
  outline: { backgroundColor: C.accentSoft, borderColor: '#c7d2fe' },
  danger: { backgroundColor: '#e11d48', borderColor: '#e11d48' },
  primary: { backgroundColor: C.accent, borderColor: C.accent },
  buttonText: { fontSize: 14, fontWeight: '700', color: C.accent },
  whiteText: { color: '#ffffff' },
  confirmBox: {
    gap: 10,
    padding: 12,
    borderRadius: 14,
    backgroundColor: C.accentSoft,
    borderWidth: 1,
    borderColor: '#c7d2fe',
  },
  confirmDanger: { backgroundColor: C.redSoft, borderColor: '#fecdd3' },
  confirmText: { fontSize: 13, lineHeight: 18, color: C.text },
  result: { fontSize: 13, color: C.greenText },
  resultError: { color: C.redText },
  pressed: { opacity: 0.85 },
});
