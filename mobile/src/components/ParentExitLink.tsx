/**
 * "Starš?" / "Parent?" (David 2026-10-14): a small, quiet link on every child screen that
 * could be a dead end (lock overlay, contract, HUD load error / crash card). It opens the
 * parental gate; only a correct answer signs the child out (`logout()` → StartScreen), so
 * a parent testing on one phone can get back to the parent login. Dark child theme.
 */

import { Pressable, StyleSheet } from 'react-native';

import ParentalGate, { PARENTAL_GATE_STRINGS as S } from '@/components/ParentalGate';
import { Text } from '@/components/ui/Text';
import { useParentalGate } from '@/modules/parentalGate/useParentalGate';
import { logout } from '@/modules/session/logout';
import { palette } from '@/theme';

interface ParentExitLinkProps {
  /** What a passed gate does (default: sign the child out). */
  onPass?: () => void;
  testID?: string;
}

export default function ParentExitLink({ onPass, testID = 'parent-exit-link' }: ParentExitLinkProps) {
  const gate = useParentalGate({ onPass: onPass ?? (() => void logout()) });
  return (
    <>
      <Pressable
        onPress={gate.open}
        hitSlop={12}
        style={({ pressed }) => [styles.link, pressed && styles.pressed]}
        accessibilityRole="button"
        accessibilityLabel={S.linkA11y}
        testID={testID}
      >
        <Text style={styles.text}>{S.link}</Text>
      </Pressable>
      <ParentalGate {...gate.props} />
    </>
  );
}

const styles = StyleSheet.create({
  link: { alignSelf: 'center', minHeight: 44, justifyContent: 'center', paddingHorizontal: 12 },
  text: { fontSize: 13, color: palette.n400, textDecorationLine: 'underline' },
  pressed: { opacity: 0.7 },
});
