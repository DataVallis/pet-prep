import { Platform, Pressable, StyleSheet, View } from 'react-native';
import { Text } from '@/components/ui/Text';
import { Footprints, HeartPulse, RefreshCw, X } from 'lucide-react-native';

import type { ChildPetView } from '@/modules/childPet/childPetView';
import { formatSteps } from '@/modules/steps/stepCounter';
import type { StepHealth, StepSync } from '@/modules/steps/useStepSync';
import { alpha, fonts, palette, tightTracking } from '@/theme';
import { t } from '@/i18n';
import { strings } from '@/i18n/strings';

/** User-visible strings (`child:walk`, M1-18). */
export const WALK_STRINGS = strings('child', 'walk', {
  steps: (steps: string, goal: string) => t('child:walk.steps', { steps, goal }),
  energy: (pct: number) => t('child:walk.energy', { pct }),
  mine: (steps: string) => t('child:walk.mine', { steps }),
});

export interface WalkTrackerOverlayProps {
  view: ChildPetView;
  stepSync: StepSync;
  onClose: () => void;
}

/** Platform wording of the health store (Apple Health / Health Connect). */
function platformKey(health: StepHealth): 'ios' | 'android' {
  return health.source === 'healthkit' ? 'ios' : 'android';
}

/**
 * Apple Health / Health Connect card (M3-04 / M3-05): a short, kind pre-permission
 * explanation before the system sheet, the connected state, and a way back after a
 * refusal (Health Connect) or when Health Connect must be installed / updated first.
 */
function HealthCard({ health }: { health: StepHealth }) {
  const key = platformKey(health);
  if (health.status === 'connected') {
    return (
      <View style={styles.healthCard} testID="walk-health-connected">
        <View style={styles.healthTitleRow}>
          <HeartPulse color={palette.mint} size={16} />
          <Text style={styles.healthConnected}>{WALK_STRINGS.health.connected[key]}</Text>
        </View>
        {key === 'ios' && <Text style={styles.healthBody}>{WALK_STRINGS.health.iosHelp}</Text>}
      </View>
    );
  }
  if (health.status === 'undetermined') {
    return (
      <View style={styles.healthCard} testID="walk-health-card">
        <View style={styles.healthTitleRow}>
          <HeartPulse color={palette.mint} size={18} />
          <Text style={styles.healthTitle}>{WALK_STRINGS.health.title[key]}</Text>
        </View>
        <Text style={styles.healthBody}>{WALK_STRINGS.health.body}</Text>
        <Pressable
          onPress={() => {
            void health.connect();
          }}
          accessibilityRole="button"
          testID="walk-health-connect"
          style={({ pressed }) => [styles.button, styles.primaryButton, pressed && styles.pressed]}
        >
          <Text style={styles.buttonText}>{WALK_STRINGS.health.connect}</Text>
        </Pressable>
      </View>
    );
  }
  if (health.status === 'denied' || health.status === 'needs_update') {
    const denied = health.status === 'denied';
    return (
      <View style={styles.healthCard} testID={denied ? 'walk-health-denied' : 'walk-health-update'}>
        <Text style={styles.healthBody}>{denied ? WALK_STRINGS.health.denied : WALK_STRINGS.health.needsUpdate}</Text>
        <Pressable
          onPress={() => {
            void (denied ? health.openSettings() : health.openStore());
          }}
          accessibilityRole="button"
          testID={denied ? 'walk-health-settings' : 'walk-health-store'}
          style={({ pressed }) => [styles.button, styles.secondaryButton, pressed && styles.pressed]}
        >
          <Text style={styles.buttonText}>{denied ? WALK_STRINGS.health.openSettings : WALK_STRINGS.health.openStore}</Text>
        </Pressable>
      </View>
    );
  }
  return null;
}

/**
 * Daily walk (M1-14): today's steps and energy from the server (`state.steps`); the
 * phone's count is synced by `useStepSync` (foreground + every 5 min, or "Osveži") from
 * Apple Health / Health Connect when connected, else from the motion sensor.
 */
export default function WalkTrackerOverlay({ view, stepSync, onClose }: WalkTrackerOverlayProps) {
  const { steps_today: steps, goal, energy_level: energy, my_steps_today: mine } = view.steps;
  const progressPct = goal > 0 ? Math.min(100, Math.round((steps / goal) * 100)) : 0;
  const shared = view.pet.caretakers_count > 1;
  const { permission, health } = stepSync;
  // The health card already offers a way to count steps → no "can't count" dead end.
  const healthOffered = health.status === 'undetermined' || health.status === 'denied' || health.status === 'needs_update';

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
          <HealthCard health={health} />
          {permission === 'undetermined' && (
            // Always offered while undetermined — also when Health is connected: a denied iOS
            // Health read or an empty Health Connect gives 0, the sensor is the fallback.
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
          )}
          {stepSync.canSync ? (
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
              {Platform.OS === 'android' && health.status !== 'connected' && (
                <Text style={styles.footnote}>{WALK_STRINGS.androidNote}</Text>
              )}
            </>
          ) : permission === 'unavailable' ? (
            healthOffered ? null : <Text style={styles.note}>{WALK_STRINGS.unavailable}</Text>
          ) : permission === 'denied' ? (
            <Text style={styles.note}>{WALK_STRINGS.denied}</Text>
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
  healthCard: {
    gap: 8,
    padding: 16,
    borderRadius: 16,
    backgroundColor: alpha(palette.white, 0.06),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.12),
  },
  healthTitleRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
  },
  healthTitle: {
    flexShrink: 1,
    fontSize: 14,
    fontWeight: '700',
    color: palette.white,
  },
  healthConnected: {
    flexShrink: 1,
    fontSize: 13,
    fontWeight: '600',
    color: palette.mint,
  },
  healthBody: {
    fontSize: 12,
    lineHeight: 17,
    color: palette.n300,
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
