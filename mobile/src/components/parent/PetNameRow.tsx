/**
 * M5-R08 — "Ime" row of a family pet for the parent (child detail + Nadzor pet card):
 * the current name (or "Še brez imena") and an action that opens a small sheet with a text
 * input, "Shrani" and "Odstrani ime". Only parents see it (the parent app); the server is
 * the authority (word filter, normalisation) — the sheet mirrors its length / character
 * rules so most mistakes are explained before a request. Light parent theme (ADR-007).
 */

import { useContext, useState } from 'react';
import { ActivityIndicator, KeyboardAvoidingView, Modal, Platform, Pressable, StyleSheet, View } from 'react-native';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';
import { Pencil, X } from 'lucide-react-native';

import { Text, TextInput } from '@/components/ui/Text';
import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { useSetPetName } from '@/hooks/queries/useParentQueries';
import {
  PET_NAME_MAX_LENGTH,
  PET_NAME_STRINGS as S,
  classifyPetNameError,
  normalizePetName,
  petLabel,
  petNameErrorText,
  petNameLength,
  readPetName,
  validatePetName,
  type PetNameErrorKind,
} from '@/modules/petName/petName';
import { MIN_TOUCH, alpha, fonts, palette, radius, tightTracking } from '@/theme';

/** Room to type past the limit, so "največ 20 znakov" is explained instead of input being cut. */
const INPUT_MAX_LENGTH = PET_NAME_MAX_LENGTH * 2;

export interface PetNameTarget {
  id: number;
  name?: unknown;
  breed_type: string;
  species?: string | null;
}

interface PetNameSheetProps {
  pet: PetNameTarget;
  onClose: () => void;
  onDone: (outcome: 'saved' | 'removed') => void;
  testID: string;
}

function PetNameSheet({ pet, onClose, onDone, testID }: PetNameSheetProps) {
  const current = readPetName(pet.name);
  const [value, setValue] = useState(current ?? '');
  const [error, setError] = useState<PetNameErrorKind | null>(null);
  const setName = useSetPetName();
  const bottomInset = useContext(SafeAreaInsetsContext)?.bottom ?? 0;
  const busy = setName.isPending;
  const length = petNameLength(normalizePetName(value) ?? '');
  const tooLong = length > PET_NAME_MAX_LENGTH;

  const send = (name: string | null) => {
    if (busy) return;
    setError(null);
    setName.mutate(
      { petId: pet.id, name },
      {
        onSuccess: (res) => onDone(res.name === null ? 'removed' : 'saved'),
        onError: (err) => setError(classifyPetNameError(err)),
      },
    );
  };

  const save = () => {
    const result = validatePetName(value);
    if (!result.ok) {
      setError(result.code);
      return;
    }
    // Nothing to change (empty input on a pet without a name, or the same name again).
    if (result.name === current) {
      onClose();
      return;
    }
    send(result.name);
  };

  return (
    <KeyboardAvoidingView style={styles.backdrop} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <Pressable
        style={StyleSheet.absoluteFill}
        onPress={busy ? undefined : onClose}
        accessible={false}
        importantForAccessibility="no"
        testID={`${testID}-backdrop`}
      />
      <View style={[styles.sheet, { paddingBottom: 20 + bottomInset }]} testID={testID} accessibilityViewIsModal>
        <View style={styles.headerRow}>
          <Text style={styles.title} accessibilityRole="header">
            {S.title}
          </Text>
          <Pressable
            onPress={onClose}
            disabled={busy}
            accessibilityRole="button"
            accessibilityLabel={S.close}
            style={({ pressed }) => [styles.close, pressed && styles.pressed]}
            testID={`${testID}-close`}
          >
            <X color={C.text} size={20} />
          </Pressable>
        </View>
        <Text style={styles.hint}>{S.hint}</Text>
        <TextInput
          style={[styles.input, error !== null && styles.inputError]}
          value={value}
          onChangeText={(v) => {
            setValue(v);
            setError(null);
          }}
          placeholder={S.placeholder}
          placeholderTextColor={palette.n500}
          maxLength={INPUT_MAX_LENGTH}
          autoCapitalize="words"
          autoCorrect={false}
          autoComplete="off"
          autoFocus
          editable={!busy}
          returnKeyType="done"
          onSubmitEditing={save}
          accessibilityLabel={S.inputLabel}
          accessibilityHint={S.rules}
          testID={`${testID}-input`}
        />
        <View style={styles.metaRow}>
          <Text style={styles.rules}>{S.rules}</Text>
          <Text style={[styles.counter, tooLong && styles.counterOver]} testID={`${testID}-counter`}>
            {S.counter(length)}
          </Text>
        </View>
        {error !== null && (
          <Text style={styles.error} accessibilityRole="alert" accessibilityLiveRegion="polite" testID={`${testID}-error`}>
            {petNameErrorText(error)}
          </Text>
        )}
        <Pressable
          style={({ pressed }) => [styles.primary, busy && styles.disabled, pressed && styles.pressed]}
          onPress={save}
          disabled={busy}
          accessibilityRole="button"
          accessibilityState={{ disabled: busy, busy }}
          testID={`${testID}-save`}
        >
          {busy ? <ActivityIndicator color={palette.white} /> : <Text style={styles.primaryText}>{S.save}</Text>}
        </Pressable>
        {current !== null && (
          <Pressable
            style={({ pressed }) => [styles.secondary, busy && styles.disabled, pressed && styles.pressed]}
            onPress={() => send(null)}
            disabled={busy}
            accessibilityRole="button"
            accessibilityState={{ disabled: busy }}
            testID={`${testID}-remove`}
          >
            <Text style={styles.secondaryText}>{S.remove}</Text>
          </Pressable>
        )}
      </View>
    </KeyboardAvoidingView>
  );
}

interface PetNameRowProps {
  pet: PetNameTarget;
  testID?: string;
}

export default function PetNameRow({ pet, testID = `pet-name-${pet.id}` }: PetNameRowProps) {
  const [open, setOpen] = useState(false);
  const [outcome, setOutcome] = useState<'saved' | 'removed' | null>(null);
  const name = readPetName(pet.name);

  return (
    <View testID={testID}>
      <View style={styles.row}>
        <View style={styles.flex}>
          <Text style={styles.label}>{S.label}</Text>
          <Text style={[styles.value, name === null && styles.valueNone]} testID={`${testID}-value`}>
            {name ?? S.none}
          </Text>
        </View>
        <Pressable
          style={({ pressed }) => [styles.editButton, pressed && styles.pressed]}
          onPress={() => {
            setOutcome(null);
            setOpen(true);
          }}
          accessibilityRole="button"
          accessibilityLabel={S.editA11y(petLabel(pet))}
          testID={`${testID}-edit`}
        >
          <Pencil color={C.accent} size={16} />
          <Text style={styles.editText}>{name === null ? S.add : S.edit}</Text>
        </Pressable>
      </View>
      {outcome !== null && (
        <Text style={styles.result} accessibilityLiveRegion="polite" testID={`${testID}-result`}>
          {outcome === 'saved' ? S.saved : S.removed}
        </Text>
      )}
      <Modal visible={open} transparent animationType="slide" onRequestClose={() => setOpen(false)} statusBarTranslucent>
        {open && (
          <PetNameSheet
            pet={pet}
            onClose={() => setOpen(false)}
            onDone={(o) => {
              setOpen(false);
              setOutcome(o);
            }}
            testID={`${testID}-sheet`}
          />
        )}
      </Modal>
    </View>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  label: { fontSize: 12, fontWeight: '700', textTransform: 'uppercase', color: C.muted },
  value: { marginTop: 2, fontSize: 16, fontWeight: '700', color: C.text },
  valueNone: { fontWeight: '400', color: C.muted },
  editButton: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    minHeight: MIN_TOUCH,
    paddingHorizontal: 14,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: C.border,
    backgroundColor: C.bg,
  },
  editText: { fontSize: 14, fontWeight: '700', color: C.accent },
  result: { marginTop: 6, fontSize: 13, color: C.greenText },
  backdrop: { flex: 1, justifyContent: 'flex-end', backgroundColor: alpha(palette.black, 0.4) },
  sheet: {
    paddingHorizontal: 20,
    paddingTop: 16,
    gap: 10,
    borderTopLeftRadius: radius.sheet,
    borderTopRightRadius: radius.sheet,
    backgroundColor: C.card,
  },
  headerRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12 },
  title: { flex: 1, fontFamily: fonts.displayBold, fontSize: 20, letterSpacing: tightTracking(20), color: C.text },
  close: { width: MIN_TOUCH, height: MIN_TOUCH, alignItems: 'center', justifyContent: 'center' },
  hint: { fontSize: 14, lineHeight: 20, color: C.text },
  input: {
    height: 50,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: palette.n300,
    backgroundColor: palette.fog,
    paddingHorizontal: 14,
    fontSize: 16,
    color: palette.graphite,
  },
  inputError: { borderColor: C.red },
  metaRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 12 },
  rules: { flex: 1, fontSize: 12, lineHeight: 16, color: C.muted },
  counter: { fontSize: 12, fontWeight: '600', color: C.muted },
  counterOver: { color: C.redText },
  error: { fontSize: 13, lineHeight: 18, color: C.redText },
  primary: {
    height: 50,
    borderRadius: 14,
    backgroundColor: palette.graphite,
    alignItems: 'center',
    justifyContent: 'center',
  },
  primaryText: { fontSize: 16, fontWeight: '700', color: palette.white },
  secondary: {
    height: 46,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: palette.dangerBorder,
    backgroundColor: C.redSoft,
    alignItems: 'center',
    justifyContent: 'center',
  },
  secondaryText: { fontSize: 15, fontWeight: '700', color: C.redText },
  disabled: { opacity: 0.5 },
  pressed: { opacity: 0.85 },
});
