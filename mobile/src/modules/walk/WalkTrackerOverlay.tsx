import { Platform, Pressable, StyleSheet, View } from 'react-native';
import { Text } from '@/components/ui/Text';
import { Footprints, RefreshCw, X } from 'lucide-react-native';

import type { ChildPetView } from '@/modules/childPet/childPetView';
import { formatSteps } from '@/modules/steps/stepCounter';
import type { StepSync } from '@/modules/steps/useStepSync';
import { alpha, fonts, palette, tightTracking } from '@/theme';

/** User-visible strings (i18n with M1-18). */
export const WALK_STRINGS = {
  title: 'Sprehod',
  steps: (steps: string, goal: string) => `${steps} / ${goal} korakov`,
  energy: (pct: number) => `Energija ${pct} %`,
  mine: (steps: string) => `Tvoji koraki danes: ${steps}`,
  goalReached: 'Bravo! Današnji sprehod je opravljen.',
  allow: 'Dovoli štetje korakov',
  allowHint: 'Telefon bo štel tvoje korake, da kuža dobi energijo.',
  denied: 'Štetje korakov ni dovoljeno. Prosi starša, da ga vklopi v nastavitvah telefona.',
  unavailable: 'Ta telefon ne zna šteti korakov.',
  refresh: 'Osveži korake',
  syncing: 'Shranjujem …',
  androidNote: 'Koraki se štejejo, ko je aplikacija odprta.',
  close: 'Zapri',
} as const;

export interface WalkTrackerOverlayProps {
  view: ChildPetView;
  stepSync: StepSync;
  onClose: () => void;
}

/**
 * Daily walk (M1-14): today's steps and energy from the server (`state.steps`); the
 * phone's count is synced by `useStepSync` (foreground + every 5 min, or "Osveži").
 */
export default function WalkTrackerOverlay({ view, stepSync, onClose }: WalkTrackerOverlayProps) {
  const { steps_today: steps, goal, energy_level: energy, my_steps_today: mine } = view.steps;
  const progressPct = goal > 0 ? Math.min(100, Math.round((steps / goal) * 100)) : 0;
  const shared = view.pet.caretakers_count > 1;
  const { permission } = stepSync;

  return (
    <View style={styles.backdrop} testID="walk-overlay" accessibilityViewIsModal>
      <View style={styles.card}>
        <View style={styles.headerRow}>
          <View style={styles.titleRow}>
            <Footprints color={palette.white} size={24} />
            <Text style={styles.title}>{WALK_STRINGS.title}</Text>
          </View>
          <Pressable
            onPress={onClose}
            accessibilityRole="button"
            accessibilityLabel={WALK_STRINGS.close}
            hitSlop={8}
            testID="walk-close"
            style={({ pressed }) => [styles.closeButton, pressed && styles.pressedSmall]}
          >
            <X color={palette.white} size={20} />
          </Pressable>
        </View>

        <View style={styles.stats}>
          <Text style={styles.steps} adjustsFontSizeToFit numberOfLines={1}>
            {WALK_STRINGS.steps(formatSteps(steps), formatSteps(goal))}
          </Text>
          <Text style={styles.energy}>{WALK_STRINGS.energy(energy)}</Text>
          {shared && <Text style={styles.mine}>{WALK_STRINGS.mine(formatSteps(mine))}</Text>}
        </View>

        <View style={styles.progressTrack} testID="walk-progress">
          <View style={[styles.progressFill, { width: `${progressPct}%` }]} />
        </View>
        {progressPct >= 100 && <Text style={styles.goalReached}>{WALK_STRINGS.goalReached}</Text>}

        <View style={styles.actions}>
          {permission === 'unavailable' ? (
            <Text style={styles.note}>{WALK_STRINGS.unavailable}</Text>
          ) : permission === 'denied' ? (
            <Text style={styles.note}>{WALK_STRINGS.denied}</Text>
          ) : permission === 'undetermined' ? (
            <>
              <Text style={styles.hint}>{WALK_STRINGS.allowHint}</Text>
              <Pressable
                onPress={() => {
                  void stepSync.requestPermission();
                }}
                accessibilityRole="button"
                testID="walk-allow"
                style={({ pressed }) => [styles.button, styles.primaryButton, pressed && styles.pressed]}
              >
                <Footprints color={palette.white} size={18} />
                <Text style={styles.buttonText}>{WALK_STRINGS.allow}</Text>
              </Pressable>
            </>
          ) : permission === 'granted' ? (
            <>
              <Pressable
                onPress={() => {
                  void stepSync.syncNow();
                }}
                disabled={stepSync.isSyncing}
                accessibilityRole="button"
                accessibilityState={{ disabled: stepSync.isSyncing }}
                testID="walk-refresh"
                style={({ pressed }) => [
                  styles.button,
                  styles.secondaryButton,
                  stepSync.isSyncing && styles.disabled,
                  pressed && !stepSync.isSyncing && styles.pressed,
                ]}
              >
                <RefreshCw color={palette.white} size={18} />
                <Text style={styles.buttonText}>
                  {stepSync.isSyncing ? WALK_STRINGS.syncing : WALK_STRINGS.refresh}
                </Text>
              </Pressable>
              {Platform.OS === 'android' && <Text style={styles.footnote}>{WALK_STRINGS.androidNote}</Text>}
            </>
          ) : null}
        </View>
      </View>
    </View>
  );
}

/** Dark glass HUD card (same tokens as the HUD header / dock in ChildHudScreen). */
const styles = StyleSheet.create({
  backdrop: {
    ...StyleSheet.absoluteFill,
    zIndex: 30,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 16,
    backgroundColor: alpha(palette.graphite, 0.78),
  },
  card: {
    width: '100%',
    maxWidth: 340,
    padding: 24,
    borderRadius: 24,
    backgroundColor: alpha(palette.graphite, 0.92),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.15),
    shadowColor: palette.black,
    shadowOffset: { width: 0, height: 8 },
    shadowOpacity: 0.5,
    shadowRadius: 16,
    elevation: 12,
  },
  headerRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  titleRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
  },
  title: {
    fontSize: 18,
    letterSpacing: tightTracking(18),
    fontFamily: fonts.displayBold,
    color: palette.white,
  },
  closeButton: {
    width: 34,
    height: 34,
    borderRadius: 10,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: alpha(palette.white, 0.08),
  },
  stats: {
    marginTop: 24,
    alignItems: 'center',
  },
  steps: {
    fontSize: 28,
    letterSpacing: tightTracking(28),
    fontFamily: fonts.display,
    color: palette.white,
  },
  energy: {
    marginTop: 4,
    fontSize: 14,
    fontWeight: '600',
    color: palette.mint,
  },
  mine: {
    marginTop: 4,
    fontSize: 12,
    color: palette.n300,
  },
  progressTrack: {
    marginTop: 16,
    height: 12,
    width: '100%',
    overflow: 'hidden',
    borderRadius: 6,
    backgroundColor: alpha(palette.white, 0.1),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.15),
  },
  progressFill: {
    height: '100%',
    borderRadius: 6,
    backgroundColor: palette.okDark,
  },
  goalReached: {
    marginTop: 8,
    textAlign: 'center',
    fontSize: 12,
    fontWeight: '600',
    color: palette.mint,
  },
  actions: {
    marginTop: 24,
    gap: 12,
  },
  note: {
    textAlign: 'center',
    fontSize: 14,
    lineHeight: 20,
    color: palette.n300,
  },
  hint: {
    textAlign: 'center',
    fontSize: 12,
    lineHeight: 17,
    color: palette.n300,
  },
  footnote: {
    textAlign: 'center',
    fontSize: 12,
    color: palette.n400,
  },
  button: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    paddingHorizontal: 24,
    paddingVertical: 12,
    borderRadius: 16,
  },
  primaryButton: {
    backgroundColor: palette.okDark,
  },
  secondaryButton: {
    backgroundColor: alpha(palette.white, 0.16),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.25),
  },
  buttonText: {
    fontSize: 14,
    fontWeight: '700',
    color: palette.white,
  },
  disabled: {
    opacity: 0.4,
  },
  pressed: {
    transform: [{ scale: 0.95 }],
    opacity: 0.85,
  },
  pressedSmall: {
    transform: [{ scale: 0.9 }],
  },
});
