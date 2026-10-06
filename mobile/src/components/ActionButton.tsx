import type { ReactNode } from 'react';
import { Platform, Pressable, StyleSheet, Text, View } from 'react-native';

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
          disabled ? styles.buttonDisabled : styles.buttonActive,
          busy && styles.buttonBusy,
          pressed && !blocked && styles.buttonPressed,
        ]}
      >
        {icon}
      </Pressable>

      <Text
        style={[
          styles.label,
          compact && styles.labelCompact,
          disabled ? styles.labelDisabled : styles.labelActive,
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
    shadowColor: '#000',
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
    borderColor: 'rgba(255, 255, 255, 0.3)',
    backgroundColor: 'rgba(255, 255, 255, 0.16)',
  },
  buttonDisabled: {
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.08)',
    backgroundColor: 'rgba(30, 41, 59, 0.45)',
    opacity: 0.5,
  },
  buttonBusy: {
    opacity: 0.7,
  },
  buttonPressed: {
    transform: [{ scale: 0.9 }],
    backgroundColor: 'rgba(255, 255, 255, 0.28)',
  },
  label: {
    fontFamily: Platform.OS === 'ios' ? 'Courier New' : 'monospace',
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
    color: 'rgba(255, 255, 255, 0.85)',
  },
  labelDisabled: {
    color: 'rgba(255, 255, 255, 0.3)',
  },
  hint: {
    marginTop: -4,
    maxWidth: 76,
    fontSize: 10,
    fontWeight: '600',
    color: '#fbbf24',
    textAlign: 'center',
  },
  hintCompact: {
    maxWidth: 64,
    fontSize: 9,
  },
});
