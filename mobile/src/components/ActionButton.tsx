import type { ReactNode } from 'react';
import { Pressable, Text, View } from 'react-native';

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
    <View className="items-center gap-2">
      <Pressable
        onPress={onPress}
        disabled={disabled}
        className={`h-16 w-16 items-center justify-center rounded-full active:scale-90 ${
          disabled
            ? 'border border-white/5 bg-slate-800/40'
            : 'border border-white/25 bg-white/15 backdrop-blur-md'
        }`}
      >
        {icon}
      </Pressable>

      <Text className={`font-mono text-[10px] uppercase tracking-widest ${
        disabled ? 'text-white/25' : 'text-white/70'
      }`}>
        {label}
      </Text>
    </View>
  );
}
