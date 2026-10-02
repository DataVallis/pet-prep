import { Text, View } from 'react-native';
import { Lock } from 'lucide-react-native';

import { useAppStore, type LockState } from '@/store/appStore';

const LOCK_MESSAGES: Record<LockState, string> = {
  none: '',
  hard_stop: 'Simulation paused. Speak with your parents.',
  game_over:
    'Virtual Shelter Protocol activated. Your pet has been taken to the shelter. Please speak with your parents to restart.',
  illness: 'Your pet is at the vet. It needs rest. Check back soon!',
};

const PARENT_PIN_LENGTH = 4;

export default function LockedScreen() {
  const lockState = useAppStore((s) => s.lockState);
  const message = LOCK_MESSAGES[lockState] || LOCK_MESSAGES.hard_stop;

  return (
    <View className="absolute inset-0 z-50 items-center justify-center bg-black px-6">
      {/* Red ambient glow */}
      <View className="absolute h-64 w-64 rounded-full bg-rose-600/15" />

      <View className="items-center">
        <View className="h-24 w-24 items-center justify-center rounded-full border border-rose-500/30 bg-rose-500/10">
          <Lock color="#ef4444" size={48} strokeWidth={2} />
        </View>
        <Text className="mt-8 text-center text-2xl font-bold tracking-tight text-white">
          Access Locked
        </Text>
        <Text className="mt-3 max-w-[280px] text-center text-base leading-7 text-slate-400">
          {message}
        </Text>
      </View>

      {/* Parent PIN unlock — display only for MVP */}
      <View className="absolute bottom-16 items-center">
        <Text className="mb-4 font-mono text-xs uppercase tracking-widest text-slate-600">
          Parent PIN Required
        </Text>
        <View className="flex-row gap-2.5">
          {Array.from({ length: PARENT_PIN_LENGTH }).map((_, i) => (
            <View
              key={i}
              className="h-12 w-10 items-center justify-center rounded-xl border border-slate-800 bg-slate-900/60"
            >
              <Text className="text-lg text-slate-700">—</Text>
            </View>
          ))}
        </View>
      </View>
    </View>
  );
}
