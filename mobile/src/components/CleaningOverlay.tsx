import { useContext, useEffect, useRef, useState } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';
import { Text } from '@/components/ui/Text';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';
import { alpha, fonts, palette, tightTracking } from '@/theme';
import { t } from '@/i18n';
import { strings } from '@/i18n/strings';

const TOTAL_SPOTS = 5;

/** User-visible strings (`child:cleaning`, M1-18); `accident` = M5-R02 puddles instead of dirt. */
export const CLEANING_STRINGS = strings('child', 'cleaning', {
  progress: (done: number, total: number) => t('child:cleaning.progress', { done, total }),
});

/** Texts of the cat's mess next to the litter tray (`cat:hud.litterMess`, M5-R06-08b). */
const LITTER_MESS_STRINGS = strings('cat', 'hud').litterMess;

/** What is being cleaned: dirt (poop), a puppy's puddle (M5-R02 accident) or the cat's mess next to the tray (M5-R06-08b). */
export type CleaningMess = 'poop' | 'accident' | 'litter';

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
  /** `accident`: puddle-shaped spots and a puddle title (M5-R02); default dirt. */
  mess?: CleaningMess;
}

/**
 * Cleaning mini-game overlay: the child taps each dirt spot; when all are gone
 * `onCleaned` fires once.
 */
export default function CleaningOverlay({ onCleaned, onClose, mess = 'poop' }: CleaningOverlayProps) {
  const puddle = mess === 'accident';
  const litter = mess === 'litter';
  const title = puddle ? CLEANING_STRINGS.accident.title : litter ? LITTER_MESS_STRINGS.title : CLEANING_STRINGS.title;
  const hint = puddle ? CLEANING_STRINGS.accident.hint : litter ? LITTER_MESS_STRINGS.hint : CLEANING_STRINGS.hint;
  const spotLabel = puddle ? CLEANING_STRINGS.accident.spot : CLEANING_STRINGS.spot;
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
    <View style={[styles.backdrop, puddle && styles.backdropPuddle]} testID="cleaning-overlay">
      <View style={[styles.top, { paddingTop: Math.max(insets.top, 20) + 16 }]}>
        <View style={styles.card}>
          <Text style={styles.title}>{title}</Text>
          <Text style={styles.progressText}>{CLEANING_STRINGS.progress(cleanedCount, TOTAL_SPOTS)}</Text>
          <View style={styles.progressTrack} testID="cleaning-progress">
            <View style={[styles.progressFill, { width: `${(cleanedCount / TOTAL_SPOTS) * 100}%` }]} />
          </View>
        </View>
        {cleanedCount === 0 && <Text style={styles.hint}>{hint}</Text>}
      </View>

      {spots.map((spot) =>
        spot.cleaned ? null : (
          <Pressable
            key={spot.id}
            testID={`dirt-spot-${spot.id}`}
            accessibilityRole="button"
            accessibilityLabel={spotLabel}
            onPress={() => handleCleanSpot(spot.id)}
            hitSlop={6}
            style={({ pressed }) => [
              styles.spot,
              puddle && styles.spotPuddle,
              // A puddle is a flat oval; dirt is round.
              {
                left: `${spot.x}%`,
                top: `${spot.y}%`,
                width: puddle ? spot.size * 1.4 : spot.size,
                height: puddle ? spot.size * 0.75 : spot.size,
                borderRadius: spot.size / 2,
                marginLeft: puddle ? (-spot.size * 1.4) / 2 : -spot.size / 2,
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
    backgroundColor: alpha(palette.graphite, 0.85),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.15),
  },
  title: {
    fontSize: 22,
    letterSpacing: tightTracking(22),
    fontFamily: fonts.display,
    color: palette.white,
    textAlign: 'center',
  },
  progressText: {
    fontFamily: fonts.displayBold,
    fontVariant: ['tabular-nums'],
    fontSize: 14,
    fontWeight: '700',
    color: palette.warnDark,
  },
  progressTrack: {
    marginTop: 4,
    height: 6,
    width: 128,
    overflow: 'hidden',
    borderRadius: 3,
    backgroundColor: alpha(palette.white, 0.1),
  },
  progressFill: {
    height: '100%',
    borderRadius: 3,
    backgroundColor: palette.warnDark,
  },
  hint: {
    marginTop: 12,
    fontSize: 14,
    fontWeight: '600',
    color: alpha(palette.warnDark, 0.85),
    textAlign: 'center',
  },
  spot: {
    position: 'absolute',
    backgroundColor: 'rgba(180, 83, 9, 0.85)',
    borderWidth: 1,
    borderColor: 'rgba(217, 119, 6, 0.45)',
  },
  backdropPuddle: {
    backgroundColor: alpha(palette.n850, 0.72),
  },
  spotPuddle: {
    backgroundColor: 'rgba(253, 230, 138, 0.8)',
    borderColor: 'rgba(252, 211, 77, 0.9)',
    borderWidth: 2,
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
    backgroundColor: alpha(palette.white, 0.16),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.25),
  },
  closeText: {
    fontSize: 14,
    fontWeight: '700',
    color: palette.white,
  },
  pressed: {
    transform: [{ scale: 0.95 }],
    opacity: 0.85,
  },
});
