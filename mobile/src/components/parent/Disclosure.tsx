/**
 * Collapsible parent UI (David 2026-11-16, »Nadzor in družina«: basics first, the rest behind
 * a tap). Two pieces, light parent theme (ADR-007):
 *
 * - `ExpandRow` — a full-width row »Več nastavitev« with a chevron (≥ 44 pt, whole row
 *   tappable, `accessibilityState.expanded`). The owner keeps the open state (UI-only local
 *   state) and calls `animateDisclosure(reduceMotion)` before changing it.
 * - `MoreInfo` — a small »Več o tem« link that reveals a setting's long explanation in place.
 *   Never put text a parent must read before acting (consent) behind it.
 *
 * Animation = `LayoutAnimation` (core RN, no native module → OTA-safe), skipped with the
 * system reduce-motion setting (`useReduceMotion`).
 */

import { useState, type ReactNode } from 'react';
import { LayoutAnimation, Pressable, StyleSheet, View } from 'react-native';
import { ChevronDown, ChevronUp, Info } from 'lucide-react-native';

import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { Text } from '@/components/ui/Text';
import { useReduceMotion } from '@/hooks/useReduceMotion';
import { palette } from '@/theme';

/** Animate the next layout change (expand / collapse) unless reduce motion is on. */
export function animateDisclosure(reduceMotion: boolean): void {
  if (reduceMotion) return;
  LayoutAnimation.configureNext(LayoutAnimation.create(200, LayoutAnimation.Types.easeInEaseOut, LayoutAnimation.Properties.opacity));
}

interface ExpandRowProps {
  label: string;
  /** Screen-reader label (e.g. with the child's nickname). */
  accessibilityLabel: string;
  expanded: boolean;
  onToggle: () => void;
  testID?: string;
}

export function ExpandRow({ label, accessibilityLabel, expanded, onToggle, testID }: ExpandRowProps) {
  const Chevron = expanded ? ChevronUp : ChevronDown;
  return (
    <Pressable
      onPress={onToggle}
      style={({ pressed }) => [styles.expandRow, pressed && styles.pressed]}
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel}
      accessibilityState={{ expanded }}
      testID={testID}
    >
      <Text style={styles.expandText}>{label}</Text>
      <Chevron color={C.link} size={18} />
    </Pressable>
  );
}

interface MoreInfoProps {
  label: string;
  accessibilityLabel: string;
  children: ReactNode;
  testID?: string;
}

export function MoreInfo({ label, accessibilityLabel, children, testID }: MoreInfoProps) {
  const [open, setOpen] = useState(false);
  const reduceMotion = useReduceMotion();
  return (
    <View>
      <Pressable
        onPress={() => {
          animateDisclosure(reduceMotion);
          setOpen((v) => !v);
        }}
        hitSlop={8}
        style={({ pressed }) => [styles.infoLink, pressed && styles.pressed]}
        accessibilityRole="button"
        accessibilityLabel={accessibilityLabel}
        accessibilityState={{ expanded: open }}
        testID={testID}
      >
        <Info color={C.link} size={13} />
        <Text style={styles.infoText}>{label}</Text>
      </Pressable>
      {open && (
        <View style={styles.infoBody} testID={testID ? `${testID}-body` : undefined}>
          {children}
        </View>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  expandRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    minHeight: 44,
    paddingHorizontal: 12,
    borderRadius: 12,
    backgroundColor: palette.fog,
    borderWidth: 1,
    borderColor: palette.n200,
  },
  expandText: { fontSize: 14, fontWeight: '700', color: C.link },
  infoLink: { flexDirection: 'row', alignItems: 'center', gap: 4, alignSelf: 'flex-start', minHeight: 28 },
  infoText: { fontSize: 12, fontWeight: '700', color: C.link },
  infoBody: { gap: 4, paddingBottom: 2 },
  pressed: { opacity: 0.7 },
});
