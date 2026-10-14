/**
 * "Starš?" / "Parent?" (David 2026-10-14): a small, quiet link on every child screen that
 * could be a dead end (lock overlay, contract, HUD load error / crash card). On the child's
 * own phone it opens the parental gate and a correct answer signs the child out; in a
 * shared child session (M2-11, »Igraj kot« on the parent's phone) it opens the parent login
 * — the parent's real sign-in. `useChildExit` decides. Dark child theme.
 */

import { Pressable, StyleSheet } from 'react-native';

import ParentalGate, { PARENTAL_GATE_STRINGS as S } from '@/components/ParentalGate';
import { Text } from '@/components/ui/Text';
import { useChildExit } from '@/modules/session/useChildExit';
import { useParentalGate } from '@/modules/parentalGate/useParentalGate';
import { palette } from '@/theme';

interface ParentExitLinkProps {
  /** Tests / reuse: a custom action behind the parental gate (always the gate, never the login). */
  onPass?: () => void;
  testID?: string;
}

export default function ParentExitLink({ onPass, testID = 'parent-exit-link' }: ParentExitLinkProps) {
  const exit = useChildExit();
  const custom = useParentalGate({ onPass: onPass ?? (() => undefined) });
  const open = onPass ? custom.open : exit.open;
  const shared = !onPass && exit.shared;
  return (
    <>
      <Pressable
        onPress={open}
        hitSlop={12}
        style={({ pressed }) => [styles.link, pressed && styles.pressed]}
        accessibilityRole="button"
        accessibilityLabel={shared ? S.linkA11ySwitch : S.linkA11y}
        testID={testID}
      >
        <Text style={styles.text}>{S.link}</Text>
      </Pressable>
      <ParentalGate {...(onPass ? custom.props : exit.gateProps)} />
    </>
  );
}

const styles = StyleSheet.create({
  link: { alignSelf: 'center', minHeight: 44, justifyContent: 'center', paddingHorizontal: 12 },
  text: { fontSize: 13, color: palette.n400, textDecorationLine: 'underline' },
  pressed: { opacity: 0.7 },
});
