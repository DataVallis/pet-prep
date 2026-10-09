/**
 * Behaviour panel of the child HUD (M5-R02), just above the action dock — near the dog:
 * - the puppy's calm countdown ("Kuža bo moral ven čez ~1 h 20 min"; no red, no alarm);
 * - the scene of an open mess: the free-tier graphic (puddle / chewed slipper, drawn in
 *   `BehaviourGraphics`) unless the premium scene video is already on screen, a short kind
 *   caption, and for a chewed slipper the "Pospravi in daj igračo" button. An accident is
 *   cleaned with the existing cleaning game, so it has no button here.
 * Nothing renders for a legacy pet / older server (no clock, no events) unless a `footer`
 * (M5-R03 "Šola" chip) is passed — the chip then sits in the same column, nearest the dock.
 * M5-R04: `meals` ("Obroki danes") sits last, but only while no scene card is open — on a
 * small phone the scene card + meals + chip would crowd the dog.
 * M5-F06: `notice` ("Your pup is getting ready…" while the AI media is pending) sits on top
 * of the column, so it is stacked above the meals row and the dock instead of under them.
 * M5-R06-08b: the cat's scratched sofa gets "Na praskalnik" (`scratcher`) — it opens the
 * scratching mini-game; cleaning never resolves it.
 */

import type { ReactNode } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';
import { Text } from '@/components/ui/Text';
import { Cat, DoorOpen, ToyBrick } from 'lucide-react-native';

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
  /** Rendered after the countdown (M5-R03 "Šola" chip); the panel shows when only this is set. */
  footer?: ReactNode;
  /** M5-R04 "Obroki danes", closest to the dock; hidden while a scene card is open. */
  meals?: ReactNode;
  /** M5-F06: a short notice at the top of the column (media "getting ready"); always shown. */
  notice?: ReactNode;
  /**
   * M5-R06-08b: "Na praskalnik" on the scratched-sofa card (a cat with an open scratching);
   * null = no button. `disabled` while the server refuses a carry (another child's game).
   */
  scratcher?: { label: string; a11y: string; disabled: boolean; onPress: () => void } | null;
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
  meals = null,
  notice = null,
  scratcher = null,
}: BehaviourPanelProps) {
  const scene = panelScene(behaviour);
  const chewing = hasOpenChewing(behaviour) || behaviour.can_resolve_chewing;
  // A slipper can be tidied up whatever scene is shown (e.g. a newer accident on top).
  const cardScene = scene ?? (chewing ? 'chewing' : scratcher !== null ? 'scratching' : null);
  const shownMeals = cardScene === null ? meals : null;
  if (notice === null && countdown === null && cardScene === null && footer === null && shownMeals === null) return null;

  const resolveDisabled = !behaviour.can_resolve_chewing || resolveBusy;

  return (
    <View pointerEvents="box-none" style={[styles.slot, { bottom, right }]} testID="hud-behaviour">
      {notice}
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
          {scratcher !== null && (
            <Pressable
              testID="action-scratcher"
              accessibilityRole="button"
              accessibilityLabel={scratcher.a11y}
              accessibilityState={{ disabled: scratcher.disabled }}
              disabled={scratcher.disabled}
              onPress={scratcher.onPress}
              style={({ pressed }) => [
                styles.resolveButton,
                scratcher.disabled && styles.resolveDisabled,
                pressed && !scratcher.disabled && styles.pressed,
              ]}
            >
              <Cat color={palette.graphite} size={16} />
              <Text style={styles.resolveText}>{scratcher.label}</Text>
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
      {shownMeals}
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
