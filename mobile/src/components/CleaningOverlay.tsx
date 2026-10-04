import { useEffect, useRef, useState } from 'react';
import { Pressable, Text, View } from 'react-native';

const TOTAL_SPOTS = 5;

/** User-visible strings (i18n with M1-18). */
export const CLEANING_STRINGS = {
  title: 'Pospravi za kužkom!',
  progress: (done: number, total: number) => `${done} / ${total} madežev`,
  hint: 'Tapni madeže, da jih zdrgneš.',
  close: 'Kasneje',
  spot: 'Madež',
} as const;

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

export interface CleaningOverlayProps {
  /** Every spot is gone → the HUD sends `POST /api/child/pet/clean`. */
  onCleaned: () => void;
  /**
   * Close without cleaning (only offered when the child opened it; a real mess
   * — hygiene 0 % — keeps the overlay until it's cleaned, PRODUCT_SPEC §8).
   */
  onClose?: () => void;
}

/**
 * Cleaning mini-game overlay: the child taps each dirt spot; when all are gone
 * `onCleaned` fires once.
 */
export default function CleaningOverlay({ onCleaned, onClose }: CleaningOverlayProps) {
  const [spots, setSpots] = useState<DirtSpot[]>([]);
  const reportedRef = useRef(false);

  // Generate randomly positioned dirt spots on mount.
  useEffect(() => {
    const generated: DirtSpot[] = Array.from({ length: TOTAL_SPOTS }, (_, i) => ({
      id: i,
      x: 10 + Math.random() * 80, // 10%–90%
      y: 18 + Math.random() * 62, // 18%–80%
      size: 44 + Math.random() * 28, // 44–72px
      cleaned: false,
    }));
    setSpots(generated);
  }, []);

  const cleanedCount = spots.filter((s) => s.cleaned).length;

  useEffect(() => {
    if (spots.length > 0 && cleanedCount === TOTAL_SPOTS && !reportedRef.current) {
      reportedRef.current = true;
      onCleaned();
    }
  }, [cleanedCount, spots.length, onCleaned]);

  const handleCleanSpot = (id: number) => {
    setSpots((prev) => prev.map((s) => (s.id === id ? { ...s, cleaned: true } : s)));
  };

  return (
    <View className="absolute inset-0 z-20 bg-amber-950/70" testID="cleaning-overlay">
      <View className="items-center pt-16">
        <View className="flex-col items-center gap-2 rounded-2xl border border-white/10 bg-slate-900/40 px-6 py-4 backdrop-blur-md">
          <Text className="text-2xl font-bold text-white">{CLEANING_STRINGS.title}</Text>
          <Text className="font-mono text-sm text-amber-300">
            {CLEANING_STRINGS.progress(cleanedCount, TOTAL_SPOTS)}
          </Text>
        </View>
        {cleanedCount === 0 && (
          <Text className="mt-3 text-sm text-amber-400/80">{CLEANING_STRINGS.hint}</Text>
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
            testID={`dirt-spot-${spot.id}`}
            accessibilityRole="button"
            accessibilityLabel={CLEANING_STRINGS.spot}
            onPress={() => handleCleanSpot(spot.id)}
            className="absolute items-center justify-center rounded-full bg-amber-700/80 border border-amber-600/40 active:scale-75"
            style={{ left: `${spot.x}%`, top: `${spot.y}%`, width: spot.size, height: spot.size }}
          />
        ),
      )}

      {onClose && (
        <View className="absolute bottom-16 w-full items-center">
          <Pressable
            onPress={onClose}
            accessibilityRole="button"
            className="rounded-xl bg-white/15 px-6 py-3 active:scale-95"
          >
            <Text className="text-sm font-semibold text-white">{CLEANING_STRINGS.close}</Text>
          </Pressable>
        </View>
      )}
    </View>
  );
}
