import { Pressable, Text, View } from 'react-native';
import { Footprints, X } from 'lucide-react-native';

import { useAppStore } from '@/store/appStore';

/**
 * Walk tracker overlay — MVP placeholder.
 *
 * Rendered by ChildHudScreen when `isWalkModalVisible` is true.
 * The full pedometer-driven step tracker will be implemented in a
 * subsequent task; this provides a dismissible overlay in the meantime.
 */
export default function WalkTrackerOverlay() {
  const setWalkModalVisible = useAppStore((s) => s.setWalkModalVisible);

  return (
    <View className="absolute inset-0 z-20 items-center justify-center bg-slate-950/80">
      <View className="items-center gap-4">
        <Footprints color="#ffffff" size={48} />
        <Text className="text-xl font-bold text-white">Walk Tracker</Text>
        <Text className="text-sm text-slate-300">Step counting coming soon</Text>

        <Pressable
          className="flex-row items-center gap-2 rounded-xl bg-white/20 px-6 py-3 active:scale-95"
          onPress={() => setWalkModalVisible(false)}
        >
          <X color="#ffffff" size={18} />
          <Text className="text-sm font-semibold text-white">End Walk</Text>
        </Pressable>
      </View>
    </View>
  );
}
