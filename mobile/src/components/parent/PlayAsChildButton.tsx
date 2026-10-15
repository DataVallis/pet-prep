/**
 * »Igraj kot {ime}« / "Play as {name}" (M2-11, David 2026-10-14): on the parent dashboard,
 * per child that has a pet. Switches this phone into that child's app (`switchToChild`)
 * and signs the parent out; back to the parent = »Nazaj k staršu« → the normal parent
 * login. Below the button: steps count only while this child's profile is chosen. A child
 * without a pet has no button (the first pairing still needs the PIN). Light parent theme.
 */

import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, View } from 'react-native';
import { Gamepad2 } from 'lucide-react-native';

import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { Text } from '@/components/ui/Text';
import { t } from '@/i18n';
import { strings } from '@/i18n/strings';
import type { FamilyChild } from '@/modules/family/family';
import { classifySwitchError, switchToChild, type ProfileSwitchErrorKind } from '@/modules/session/profileSwitch';

/** User-visible strings (`parent:childCard.playAs*`). */
export const PLAY_AS_STRINGS = strings('parent', 'childCard', {
  playAs: (name: string) => t('parent:childCard.playAs', { name }),
  playAsHint: (name: string) => t('parent:childCard.playAsHint', { name }),
  playAsSteps: (name: string) => t('parent:childCard.playAsSteps', { name }),
});

const S = PLAY_AS_STRINGS;

interface PlayAsChildButtonProps {
  child: Pick<FamilyChild, 'id' | 'name' | 'pet_id'>;
  /** Tests: the switch (default `switchToChild`). */
  onSwitch?: (child: { id: number }) => Promise<void>;
}

export default function PlayAsChildButton({ child, onSwitch = switchToChild }: PlayAsChildButtonProps) {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<ProfileSwitchErrorKind | null>(null);

  if (child.pet_id === null) return null;

  const start = async () => {
    if (pending) return;
    setError(null);
    setPending(true);
    try {
      // Success re-routes the whole app to the child (this card unmounts).
      await onSwitch({ id: child.id });
    } catch (err) {
      const kind = classifySwitchError(err);
      // A double tap while a switch runs: the first one answers.
      if (kind !== 'busy') setError(kind);
      setPending(false);
    }
  };

  return (
    <View style={styles.block}>
      <Pressable
        style={({ pressed }) => [styles.button, pending && styles.disabled, pressed && styles.pressed]}
        onPress={() => void start()}
        disabled={pending}
        accessibilityRole="button"
        accessibilityLabel={S.playAs(child.name)}
        accessibilityHint={S.playAsHint(child.name)}
        accessibilityState={{ disabled: pending, busy: pending }}
        testID={`child-play-as-${child.id}`}
      >
        {pending ? (
          <ActivityIndicator color={C.accent} />
        ) : (
          <>
            <Gamepad2 color={C.accent} size={16} />
            <Text style={styles.text}>{S.playAs(child.name)}</Text>
          </>
        )}
      </Pressable>
      <Text style={styles.note} testID={`child-play-as-steps-${child.id}`}>
        {S.playAsSteps(child.name)}
      </Text>
      {error && error !== 'busy' && (
        <Text style={styles.error} testID={`child-play-as-error-${child.id}`} accessibilityLiveRegion="polite">
          {S.playAsErrors[error]}
        </Text>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  block: { gap: 6 },
  button: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    minHeight: 44,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: C.border,
    backgroundColor: C.bg,
  },
  text: { fontSize: 14, fontWeight: '700', color: C.accent },
  error: { fontSize: 13, color: C.redText },
  note: { fontSize: 12, lineHeight: 17, color: C.muted },
  disabled: { opacity: 0.6 },
  pressed: { opacity: 0.85 },
});
