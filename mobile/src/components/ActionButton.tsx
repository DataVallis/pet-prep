import type { ReactNode } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';
import { Text } from '@/components/ui/Text';
import { alpha, palette } from '@/theme';

export interface ActionButtonProps {
  /** Lucide icon node displayed inside the circular button. */
  icon: ReactNode;
  /** Uppercase label shown beneath the button. */
  label: string;
  /** Press handler invoked when the button is tapped. */
  onPress: () => void;
  /** When true, renders the disabled style and blocks presses. */
  disabled?: boolean;
  /** Small line under the label explaining a disabled button ("ob 17:00"). */
  hint?: string | null;
  /**
   * The care that is due right now (CGP v2: "the care button that is due is solid mint").
   * Pass a graphite icon when due.
   */
  due?: boolean;
  /** Shows a spinner-like dimmed state while the request runs. */
  busy?: boolean;
  /** Smaller button / label for a five-button dock (puppy "Pelji ven", M5-R02). */
  compact?: boolean;
  /** Spoken label when it should say more than the visible one (e.g. the full countdown). */
  accessibilityHint?: string;
  testID?: string;
}

/**
 * Circular glassmorphism action button used in the bottom control dock.
 */
export default function ActionButton({
  icon,
  label,
  onPress,
  disabled,
  hint,
  busy,
  due,
  compact,
  accessibilityHint,
  testID,
}: ActionButtonProps) {
  const blocked = disabled === true || busy === true;
  return (
    <View style={[styles.container, compact && styles.containerCompact]}>
      <Pressable
        testID={testID}
        onPress={onPress}
        disabled={blocked}
        accessibilityRole="button"
        accessibilityLabel={hint ? `${label}, ${hint}` : label}
        accessibilityHint={accessibilityHint}
        accessibilityState={{ disabled: blocked, busy: busy === true }}
        style={({ pressed }) => [
          styles.button,
          compact && styles.buttonCompact,
          disabled ? styles.buttonDisabled : due ? styles.buttonDue : styles.buttonActive,
          busy && styles.buttonBusy,
          pressed && !blocked && (due && !disabled ? styles.buttonDuePressed : styles.buttonPressed),
        ]}
      >
        {icon}
      </Pressable>

      <Text
        style={[
          styles.label,
          compact && styles.labelCompact,
          disabled ? styles.labelDisabled : due ? styles.labelDue : styles.labelActive,
        ]}
        numberOfLines={1}
      >
        {label}
      </Text>
      {hint ? (
        <Text style={[styles.hint, compact && styles.hintCompact]} numberOfLines={1}>
          {hint}
        </Text>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    alignItems: 'center',
    gap: 8,
  },
  containerCompact: {
    gap: 6,
  },
  button: {
    width: 64,
    height: 64,
    borderRadius: 32,
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: palette.black,
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.35,
    shadowRadius: 8,
    elevation: 6,
  },
  buttonCompact: {
    width: 54,
    height: 54,
    borderRadius: 27,
  },
  buttonActive: {
    borderWidth: 1.5,
    borderColor: alpha(palette.white, 0.3),
    backgroundColor: alpha(palette.white, 0.16),
  },
  buttonDue: {
    borderWidth: 1.5,
    borderColor: palette.mint,
    backgroundColor: palette.mint,
    shadowColor: palette.mint,
    shadowOpacity: 0.35,
  },
  buttonDuePressed: {
    transform: [{ scale: 0.9 }],
    opacity: 0.85,
  },
  buttonDisabled: {
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.08),
    backgroundColor: alpha(palette.n850, 0.45),
    opacity: 0.5,
  },
  buttonBusy: {
    opacity: 0.7,
  },
  buttonPressed: {
    transform: [{ scale: 0.9 }],
    backgroundColor: alpha(palette.white, 0.28),
  },
  label: {
    fontSize: 11,
    fontWeight: '700',
    textTransform: 'uppercase',
    letterSpacing: 1,
  },
  labelCompact: {
    fontSize: 9,
    letterSpacing: 0.4,
  },
  labelActive: {
    color: alpha(palette.white, 0.85),
  },
  labelDue: {
    color: palette.mint,
  },
  labelDisabled: {
    color: alpha(palette.white, 0.3),
  },
  hint: {
    marginTop: -4,
    maxWidth: 76,
    fontSize: 10,
    fontWeight: '600',
    color: palette.warnDark,
    textAlign: 'center',
  },
  hintCompact: {
    maxWidth: 64,
    fontSize: 9,
  },
});
