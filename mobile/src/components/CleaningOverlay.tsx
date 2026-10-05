import { useContext, useEffect, useRef, useState } from 'react';
import { Platform, Pressable, StyleSheet, Text, View } from 'react-native';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';

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
  // Context (not the hook): renders without a SafeAreaProvider too (tests).
  const insets = useContext(SafeAreaInsetsContext) ?? ZERO_INSETS;

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
    <View style={styles.backdrop} testID="cleaning-overlay">
      <View style={[styles.top, { paddingTop: Math.max(insets.top, 20) + 16 }]}>
        <View style={styles.card}>
          <Text style={styles.title}>{CLEANING_STRINGS.title}</Text>
          <Text style={styles.progressText}>{CLEANING_STRINGS.progress(cleanedCount, TOTAL_SPOTS)}</Text>
          <View style={styles.progressTrack} testID="cleaning-progress">
            <View style={[styles.progressFill, { width: `${(cleanedCount / TOTAL_SPOTS) * 100}%` }]} />
          </View>
        </View>
        {cleanedCount === 0 && <Text style={styles.hint}>{CLEANING_STRINGS.hint}</Text>}
      </View>

      {spots.map((spot) =>
        spot.cleaned ? null : (
          <Pressable
            key={spot.id}
            testID={`dirt-spot-${spot.id}`}
            accessibilityRole="button"
            accessibilityLabel={CLEANING_STRINGS.spot}
            onPress={() => handleCleanSpot(spot.id)}
            hitSlop={6}
            style={({ pressed }) => [
              styles.spot,
              {
                left: `${spot.x}%`,
                top: `${spot.y}%`,
                width: spot.size,
                height: spot.size,
                borderRadius: spot.size / 2,
                marginLeft: -spot.size / 2,
              },
              pressed && styles.spotPressed,
            ]}
          />
        ),
      )}

      {onClose && (
        <View style={[styles.bottom, { bottom: Math.max(insets.bottom, 16) + 32 }]}>
          <Pressable
            onPress={onClose}
            accessibilityRole="button"
            testID="cleaning-close"
            style={({ pressed }) => [styles.closeButton, pressed && styles.pressed]}
          >
            <Text style={styles.closeText}>{CLEANING_STRINGS.close}</Text>
          </Pressable>
        </View>
      )}
    </View>
  );
}

const ZERO_INSETS = { top: 0, bottom: 0 } as const;

/** Dark glass HUD card over a warm brown "mess" veil. */
const styles = StyleSheet.create({
  backdrop: {
    ...StyleSheet.absoluteFill,
    zIndex: 20,
    backgroundColor: 'rgba(69, 26, 3, 0.72)',
  },
  top: {
    alignItems: 'center',
    paddingHorizontal: 16,
  },
  card: {
    alignItems: 'center',
    gap: 8,
    paddingHorizontal: 24,
    paddingVertical: 16,
    borderRadius: 24,
    backgroundColor: 'rgba(15, 23, 42, 0.85)',
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.15)',
  },
  title: {
    fontSize: 22,
    fontWeight: '800',
    color: '#ffffff',
    textAlign: 'center',
  },
  progressText: {
    fontFamily: Platform.OS === 'ios' ? 'Courier New' : 'monospace',
    fontSize: 14,
    fontWeight: '700',
    color: '#fcd34d',
  },
  progressTrack: {
    marginTop: 4,
    height: 6,
    width: 128,
    overflow: 'hidden',
    borderRadius: 3,
    backgroundColor: 'rgba(255, 255, 255, 0.1)',
  },
  progressFill: {
    height: '100%',
    borderRadius: 3,
    backgroundColor: '#fbbf24',
  },
  hint: {
    marginTop: 12,
    fontSize: 14,
    fontWeight: '600',
    color: 'rgba(251, 191, 36, 0.85)',
    textAlign: 'center',
  },
  spot: {
    position: 'absolute',
    backgroundColor: 'rgba(180, 83, 9, 0.85)',
    borderWidth: 1,
    borderColor: 'rgba(217, 119, 6, 0.45)',
  },
  spotPressed: {
    transform: [{ scale: 0.75 }],
  },
  bottom: {
    position: 'absolute',
    left: 0,
    right: 0,
    alignItems: 'center',
  },
  closeButton: {
    paddingHorizontal: 24,
    paddingVertical: 12,
    borderRadius: 16,
    backgroundColor: 'rgba(255, 255, 255, 0.16)',
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.25)',
  },
  closeText: {
    fontSize: 14,
    fontWeight: '700',
    color: '#ffffff',
  },
  pressed: {
    transform: [{ scale: 0.95 }],
    opacity: 0.85,
  },
});
