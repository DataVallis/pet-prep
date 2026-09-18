import { useEffect, useState } from 'react';
import { Pressable, Text, View } from 'react-native';

import { useAppStore } from '@/store/appStore';

const TOTAL_SPOTS = 5;

interface DirtSpot {
  id: number;
  /** Horizontal position as a percentage of the overlay width. */
  x: number;
  /** Vertical position as a percentage of the overlay height. */
  y: number;
  /** Size of the spot in pixels. */
  size: number;
  cleaned: boolean;
}

/**
 * Interactive cleaning mini-game overlay.
 * The child taps each dirt spot to remove it; once all spots are
 * cleaned the overlay dismisses itself via the app store.
 */
export default function CleaningOverlay() {
  const setCleaningOverlayVisible = useAppStore((s) => s.setCleaningOverlayVisible);
  const [spots, setSpots] = useState<DirtSpot[]>([]);

  // Generate randomly positioned dirt spots on mount.
  useEffect(() => {
    const generated: DirtSpot[] = Array.from({ length: TOTAL_SPOTS }, (_, i) => ({
      id: i,
      x: 10 + Math.random() * 80, // 10%–90%
      y: 18 + Math.random() * 62, // 18%–80%
      size: 40 + Math.random() * 28, // 40–68px
      cleaned: false,
    }));
    setSpots(generated);
  }, []);

  const cleanedCount = spots.filter((s) => s.cleaned).length;

  // Dismiss once every spot has been cleaned.
  useEffect(() => {
    if (spots.length > 0 && cleanedCount === TOTAL_SPOTS) {
      setCleaningOverlayVisible(false);
    }
  }, [cleanedCount, spots.length, setCleaningOverlayVisible]);

  const handleCleanSpot = (id: number) => {
    setSpots((prev) => prev.map((s) => (s.id === id ? { ...s, cleaned: true } : s)));
  };

  return (
    <View className="absolute inset-0 z-20 bg-amber-950/70">
      <View className="items-center pt-16">
        <View className="flex-col items-center gap-2 rounded-2xl border border-white/10 bg-slate-900/40 px-6 py-4 backdrop-blur-md">
          <Text className="text-2xl font-bold text-white">Clean Your Pet!</Text>
          <Text className="font-mono text-sm text-amber-300">
            {cleanedCount} / {TOTAL_SPOTS} spots cleaned
          </Text>
        </View>
        {cleanedCount === 0 && (
          <Text className="mt-3 text-sm text-amber-400/80">Tap the dirt spots to clean them</Text>
        )}
      </View>

      {/* Progress indicator */}
      <View className="absolute left-1/2 top-[120] h-1.5 w-32 -translate-x-1/2 overflow-hidden rounded-full bg-white/10">
        <View
          className="h-full rounded-full bg-amber-400"
          style={{ width: `${(cleanedCount / TOTAL_SPOTS) * 100}%` }}
        />
      </View>

      {spots.map((spot) =>
        spot.cleaned ? null : (
          <Pressable
            key={spot.id}
            onPress={() => handleCleanSpot(spot.id)}
            className="absolute items-center justify-center rounded-full bg-amber-700/80 border border-amber-600/40 active:scale-75"
            style={{ left: `${spot.x}%`, top: `${spot.y}%`, width: spot.size, height: spot.size }}
          />
        ),
      )}
    </View>
  );
}
