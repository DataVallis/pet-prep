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
}

/**
 * Circular glassmorphism action button used in the bottom control dock.
 */
export default function ActionButton({ icon, label, onPress, disabled }: ActionButtonProps) {
  return (
    <View style={styles.container}>
      <Pressable
        onPress={onPress}
        disabled={disabled}
        style={({ pressed }) => [
          styles.button,
          disabled ? styles.buttonDisabled : styles.buttonActive,
          pressed && !disabled && styles.buttonPressed,
        ]}
      >
        {icon}
      </Pressable>

      <Text
        style={[
          styles.label,
          disabled ? styles.labelDisabled : styles.labelActive,
        ]}
      >
        {label}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    alignItems: 'center',
    gap: 8,
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
  labelActive: {
    color: 'rgba(255, 255, 255, 0.85)',
  },
  labelDisabled: {
    color: 'rgba(255, 255, 255, 0.3)',
  },
});
