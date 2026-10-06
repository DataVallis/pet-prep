/**
 * Behaviour panel of the child HUD (M5-R02), just above the action dock — near the dog:
 * - the puppy's calm countdown ("Kuža bo moral ven čez ~1 h 20 min"; no red, no alarm);
 * - the scene of an open mess: the free-tier graphic (puddle / chewed slipper, drawn in
 *   `BehaviourGraphics`) unless the premium scene video is already on screen, a short kind
 *   caption, and for a chewed slipper the "Pospravi in daj igračo" button. An accident is
 *   cleaned with the existing cleaning game, so it has no button here.
 * Nothing renders for a legacy pet / older server (no clock, no events) unless a `footer`
 * (M5-R03 "Šola" chip) is passed — the chip then sits in the same column, nearest the dock.
 */

import type { ReactNode } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';
import { Text } from '@/components/ui/Text';
import { DoorOpen, ToyBrick } from 'lucide-react-native';

import { SceneGraphic } from '@/components/BehaviourGraphics';
import {
  BEHAVIOUR_STRINGS,
  hasOpenChewing,
  panelScene,
  type ChildBehaviour,
  type TakeOutCountdown,
} from '@/modules/behaviour/behaviour';
import type { VideoState } from '@/modules/petMedia/petMedia';
import { alpha, palette } from '@/theme';

export interface BehaviourPanelProps {
  behaviour: ChildBehaviour;
  /** From `takeOutCountdown`; null = no line. */
  countdown: TakeOutCountdown | null;
  /** The state video PetMediaView is playing (after fallbacks) — the scene video replaces the graphic. */
  videoState: VideoState | null;
  onResolveChewing: () => void;
  resolveBusy: boolean;
  /** `bottom` (HUD layout `aboveDock`). */
  bottom: number;
  /** Right edge kept free for the metric column. */
  right: number;
  /** Rendered last, closest to the dock (M5-R03 "Šola" chip); the panel shows when only this is set. */
  footer?: ReactNode;
}

export default function BehaviourPanel({
  behaviour,
  countdown,
  videoState,
  onResolveChewing,
  resolveBusy,
  bottom,
  right,
  footer = null,
}: BehaviourPanelProps) {
  const scene = panelScene(behaviour);
  const chewing = hasOpenChewing(behaviour) || behaviour.can_resolve_chewing;
  if (countdown === null && scene === null && !chewing && footer === null) return null;
  // A slipper can be tidied up whatever scene is shown (e.g. a newer accident on top).
  const cardScene = scene ?? (chewing ? 'chewing' : null);

  const resolveDisabled = !behaviour.can_resolve_chewing || resolveBusy;

  return (
    <View pointerEvents="box-none" style={[styles.slot, { bottom, right }]} testID="hud-behaviour">
      {cardScene !== null && (
        <View style={styles.sceneCard} testID={`hud-scene-${cardScene}`}>
          {videoState !== cardScene && (
            <View accessible accessibilityRole="image" accessibilityLabel={BEHAVIOUR_STRINGS.sceneA11y[cardScene]}>
              <SceneGraphic scene={cardScene} size={84} />
            </View>
          )}
          <Text style={styles.sceneText}>{BEHAVIOUR_STRINGS.scene[cardScene]}</Text>
          {chewing && (
            <Pressable
              testID="action-resolve-chewing"
              accessibilityRole="button"
              accessibilityState={{ disabled: resolveDisabled, busy: resolveBusy }}
              disabled={resolveDisabled}
              onPress={onResolveChewing}
              style={({ pressed }) => [
                styles.resolveButton,
                resolveDisabled && styles.resolveDisabled,
                pressed && !resolveDisabled && styles.pressed,
              ]}
            >
              <ToyBrick color={palette.graphite} size={16} />
              <Text style={styles.resolveText}>{BEHAVIOUR_STRINGS.resolveChewing}</Text>
            </Pressable>
          )}
        </View>
      )}
      {countdown !== null && (
        <View style={styles.countdownPill} testID="hud-take-out-countdown" accessible accessibilityLabel={countdown.line}>
          <DoorOpen color={countdown.due ? palette.mint : palette.n400} size={14} />
          <Text style={styles.countdownText}>{countdown.line}</Text>
        </View>
      )}
      {footer}
    </View>
  );
}

/** Dark glass, like the header / dock; mint accent (never the danger colour). */
const styles = StyleSheet.create({
  slot: {
    position: 'absolute',
    left: 16,
    zIndex: 15,
    alignItems: 'center',
    gap: 8,
  },
  sceneCard: {
    maxWidth: '100%',
    alignItems: 'center',
    gap: 8,
    paddingHorizontal: 16,
    paddingVertical: 12,
    borderRadius: 20,
    backgroundColor: alpha(palette.graphite, 0.82),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.15),
  },
  sceneText: {
    color: palette.n200,
    fontSize: 13,
    fontWeight: '600',
    textAlign: 'center',
  },
  resolveButton: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    minHeight: 44,
    paddingHorizontal: 18,
    borderRadius: 22,
    backgroundColor: palette.mint,
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.25),
  },
  resolveDisabled: {
    opacity: 0.5,
  },
  resolveText: {
    color: palette.graphite,
    fontSize: 14,
    fontWeight: '800',
  },
  countdownPill: {
    maxWidth: '100%',
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    paddingHorizontal: 12,
    paddingVertical: 6,
    borderRadius: 14,
    backgroundColor: alpha(palette.graphite, 0.82),
    borderWidth: 1,
    borderColor: alpha(palette.mint, 0.3),
  },
  countdownText: {
    flexShrink: 1,
    color: palette.mint,
    fontSize: 12,
    fontWeight: '600',
  },
  pressed: {
    transform: [{ scale: 0.96 }],
    opacity: 0.85,
  },
});
