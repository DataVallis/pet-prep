/**
 * Behaviour panel = the child HUD's top stack (M5-R02; since David 2026-10-25 »pes mora biti
 * viden« directly under the header, never over the dog's centre / lower half — only the action
 * dock stays at the bottom). Order and bounding: `modules/hud/topStack` (most urgent first, at
 * most 2 cards, the rest behind a "+N" pill; the chip row is always visible under the cards):
 * - the puppy's deadline (M5-T01: "Kuža mora ven v ~1 h 20 min." + "Pritisni »Pelji ven«, preden
 *   se čas izteče."), green → orange → red in the last 30 min — calm words, never an alarm;
 * - the scene of an open mess: the free-tier graphic (puddle / chewed slipper, drawn in
 *   `BehaviourGraphics`) unless the premium scene video is already on screen, a short kind
 *   caption, and for a chewed slipper the "Pospravi in daj igračo" button. An accident is
 *   cleaned with the existing cleaning game, so it has no button here.
 * Nothing renders for a legacy pet / older server (no clock, no events) unless `cards` or
 * `chips` (M5-R03 "Šola" …) are passed.
 * M5-R04: `meals` ("Obroki danes") is the least urgent card, and only while no scene card is open.
 * M5-F06: `notice` ("Your pup is getting ready…" while the AI media is pending) is a card above
 * the meals row.
 * M5-R06-08b: the cat's scratched sofa gets "Na praskalnik" (`scratcher`) — it opens the
 * scratching mini-game; cleaning never resolves it.
 */

import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Animated, Pressable, StyleSheet, View } from 'react-native';
import { Text } from '@/components/ui/Text';
import { Cat, ChevronDown, ChevronUp, DoorOpen, ToyBrick } from 'lucide-react-native';

import { SceneGraphic } from '@/components/BehaviourGraphics';
import {
  BEHAVIOUR_STRINGS,
  hasOpenChewing,
  panelScene,
  type ChildBehaviour,
  type TakeOutCountdown,
  type TakeOutLevel,
} from '@/modules/behaviour/behaviour';
import type { VideoState } from '@/modules/petMedia/petMedia';
import { arrangeTopStack, STACK_PRIORITY, takeOutPriority, type StackEntry } from '@/modules/hud/topStack';
import { t } from '@/i18n';
import { alpha, palette } from '@/theme';

export interface BehaviourPanelProps {
  behaviour: ChildBehaviour;
  /** From `takeOutCountdown`; null = no line. */
  countdown: TakeOutCountdown | null;
  /** The state video PetMediaView is playing (after fallbacks) — the scene video replaces the graphic. */
  videoState: VideoState | null;
  onResolveChewing: () => void;
  resolveBusy: boolean;
  /** More cards of the HUD (notes, invitation, …), sorted into the stack by `priority`. */
  cards?: readonly StackCard[];
  /** The chip row ("Šola", "Igra", breed care …): always visible under the cards. */
  chips?: ReactNode;
  /** M5-R04 "Obroki danes" (least urgent card); hidden while a scene card is open. */
  meals?: ReactNode;
  /** M5-F06: the media "getting ready" notice (a card above the meals row). */
  notice?: ReactNode;
  /**
   * M5-R06-08b: "Na praskalnik" on the scratched-sofa card (a cat with an open scratching);
   * null = no button. `disabled` while the server refuses a carry (another child's game).
   */
  scratcher?: { label: string; a11y: string; disabled: boolean; onPress: () => void } | null;
  /** System reduce motion: the clock resets without the short pop animation. */
  reduceMotion?: boolean;
}

/** One card of the top stack. */
export interface StackCard extends StackEntry {
  node: ReactNode;
}

/** M5-T01 clock colours: green (mint) → orange (warn) → red (danger) — dark-theme tokens. */
const LEVEL_COLOUR: Record<TakeOutLevel, string> = {
  calm: palette.mint,
  soon: palette.warnDark,
  urgent: palette.dangerDark,
};

/**
 * The take-out deadline (M5-T01, spec §2/§4): the line ("Kuža mora ven v ~1 h 20 min."), the
 * how-to under it, coloured by urgency. A new clock (`resetKey` = `clock_started_at` changed,
 * e.g. after "Pelji ven") pops once so the reset is visible, not silent.
 */
function TakeOutClockPill({
  countdown,
  resetKey,
  reduceMotion,
}: {
  countdown: TakeOutCountdown;
  resetKey: string | null;
  reduceMotion: boolean;
}) {
  const pop = useRef(new Animated.Value(1)).current;
  const lastKey = useRef(resetKey);
  const [resetting, setResetting] = useState(false);
  useEffect(() => {
    if (lastKey.current === resetKey) return;
    const first = lastKey.current === null;
    lastKey.current = resetKey;
    if (first || reduceMotion) return;
    setResetting(true);
    pop.setValue(0.85);
    const anim = Animated.spring(pop, { toValue: 1, friction: 4, tension: 120, useNativeDriver: true });
    anim.start(() => setResetting(false));
    return () => anim.stop();
  }, [resetKey, reduceMotion, pop]);
  const colour = LEVEL_COLOUR[countdown.level];
  const label = countdown.howTo ? `${countdown.line} ${countdown.howTo}` : countdown.line;
  return (
    <Animated.View
      style={[styles.countdownPill, { borderColor: alpha(colour, 0.55), transform: [{ scale: pop }] }]}
      testID="hud-take-out-countdown"
      accessible
      accessibilityLabel={label}
    >
      <View style={styles.countdownRow} testID={`hud-take-out-level-${countdown.level}`}>
        <DoorOpen color={colour} size={14} />
        <Text style={[styles.countdownText, { color: colour }]}>{countdown.line}</Text>
      </View>
      {countdown.howTo !== null && (
        <Text style={styles.countdownHowTo} testID="hud-take-out-howto">
          {countdown.howTo}
        </Text>
      )}
      {resetting && <View testID="hud-take-out-reset" />}
    </Animated.View>
  );
}

export default function BehaviourPanel({
  behaviour,
  countdown,
  videoState,
  onResolveChewing,
  resolveBusy,
  cards = [],
  chips = null,
  meals = null,
  notice = null,
  scratcher = null,
  reduceMotion = false,
}: BehaviourPanelProps) {
  const [expanded, setExpanded] = useState(false);
  const scene = panelScene(behaviour);
  const chewing = hasOpenChewing(behaviour) || behaviour.can_resolve_chewing;
  // A slipper can be tidied up whatever scene is shown (e.g. a newer accident on top).
  const cardScene = scene ?? (chewing ? 'chewing' : scratcher !== null ? 'scratching' : null);
  const shownMeals = cardScene === null ? meals : null;
  const resolveDisabled = !behaviour.can_resolve_chewing || resolveBusy;

  const entries: StackCard[] = [];
  if (countdown !== null) {
    entries.push({
      key: 'take-out',
      priority: takeOutPriority(countdown.level),
      node: <TakeOutClockPill countdown={countdown} resetKey={behaviour.take_out?.clock_started_at ?? null} reduceMotion={reduceMotion} />,
    });
  }
  if (cardScene !== null) {
    entries.push({
      key: 'scene',
      priority: STACK_PRIORITY.scene,
      node: (
        <View style={styles.sceneCard} testID={`hud-scene-${cardScene}`}>
          {videoState !== cardScene && (
            <View accessible accessibilityRole="image" accessibilityLabel={BEHAVIOUR_STRINGS.sceneA11y[cardScene]}>
              <SceneGraphic scene={cardScene} size={44} />
            </View>
          )}
          <View style={styles.sceneBody}>
            <Text style={styles.sceneText} numberOfLines={2} maxFontSizeMultiplier={1.4}>
              {BEHAVIOUR_STRINGS.scene[cardScene]}
            </Text>
            {chewing && (
              <Pressable
                testID="action-resolve-chewing"
                accessibilityRole="button"
                accessibilityState={{ disabled: resolveDisabled, busy: resolveBusy }}
                disabled={resolveDisabled}
                onPress={onResolveChewing}
                hitSlop={6}
                style={({ pressed }) => [
                  styles.resolveButton,
                  resolveDisabled && styles.resolveDisabled,
                  pressed && !resolveDisabled && styles.pressed,
                ]}
              >
                <ToyBrick color={palette.graphite} size={14} />
                <Text style={styles.resolveText} numberOfLines={1} maxFontSizeMultiplier={1.3}>
                  {BEHAVIOUR_STRINGS.resolveChewing}
                </Text>
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
                hitSlop={6}
                style={({ pressed }) => [
                  styles.resolveButton,
                  scratcher.disabled && styles.resolveDisabled,
                  pressed && !scratcher.disabled && styles.pressed,
                ]}
              >
                <Cat color={palette.graphite} size={14} />
                <Text style={styles.resolveText} numberOfLines={1} maxFontSizeMultiplier={1.3}>
                  {scratcher.label}
                </Text>
              </Pressable>
            )}
          </View>
        </View>
      ),
    });
  }
  if (notice !== null) entries.push({ key: 'notice', priority: STACK_PRIORITY.notice, node: notice });
  entries.push(...cards);
  if (shownMeals !== null) entries.push({ key: 'meals', priority: STACK_PRIORITY.meals, node: shownMeals });

  const stack = arrangeTopStack(entries, expanded);
  // Nothing more to expand → the next overflow starts collapsed again.
  useEffect(() => {
    if (!stack.collapsible && expanded) setExpanded(false);
  }, [stack.collapsible, expanded]);

  if (entries.length === 0 && chips === null) return null;

  return (
    <View pointerEvents="box-none" style={styles.stack} testID="hud-behaviour">
      {stack.visible.map((card) => (
        <View key={card.key} pointerEvents="box-none" style={styles.cardSlot}>
          {card.node}
        </View>
      ))}
      {stack.collapsible && (
        <Pressable
          testID={expanded ? 'hud-top-stack-less' : 'hud-top-stack-more'}
          accessibilityRole="button"
          accessibilityLabel={
            expanded ? t('child:hud.topStack.lessA11y') : t('child:hud.topStack.moreA11y', { count: stack.hiddenCount })
          }
          accessibilityState={{ expanded }}
          hitSlop={10}
          onPress={() => setExpanded((v) => !v)}
          style={({ pressed }) => [styles.morePill, pressed && styles.pressed]}
        >
          {expanded ? (
            <ChevronUp color={palette.n200} size={12} />
          ) : (
            <ChevronDown color={palette.n200} size={12} />
          )}
          <Text style={styles.moreText} maxFontSizeMultiplier={1.3}>
            {expanded ? t('child:hud.topStack.less') : t('child:hud.topStack.more', { count: stack.hiddenCount })}
          </Text>
        </Pressable>
      )}
      {chips}
    </View>
  );
}

/** Dark glass, like the header / dock; mint accent (danger only on the last-30-min clock, M5-T01). */
const styles = StyleSheet.create({
  /** Laid out by the HUD's top-stack slot (under the header, left of the metric column). */
  stack: {
    alignSelf: 'stretch',
    alignItems: 'flex-start',
    gap: 6,
  },
  cardSlot: {
    maxWidth: '100%',
  },
  sceneCard: {
    maxWidth: '100%',
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    paddingHorizontal: 10,
    paddingVertical: 8,
    borderRadius: 16,
    backgroundColor: alpha(palette.graphite, 0.82),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.15),
  },
  sceneBody: {
    flexShrink: 1,
    alignItems: 'flex-start',
    gap: 6,
  },
  sceneText: {
    color: palette.n200,
    fontSize: 12,
    fontWeight: '600',
  },
  /** Compact CTA (32 pt + 6 pt hitSlop each side = 44 pt target). */
  resolveButton: {
    maxWidth: '100%',
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    minHeight: 32,
    paddingHorizontal: 12,
    borderRadius: 16,
    backgroundColor: palette.mint,
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.25),
  },
  resolveDisabled: {
    opacity: 0.5,
  },
  resolveText: {
    flexShrink: 1,
    color: palette.graphite,
    fontSize: 13,
    fontWeight: '800',
  },
  countdownPill: {
    maxWidth: '100%',
    alignItems: 'flex-start',
    gap: 1,
    paddingHorizontal: 10,
    paddingVertical: 5,
    borderRadius: 12,
    backgroundColor: alpha(palette.graphite, 0.82),
    borderWidth: 1,
    borderColor: alpha(palette.mint, 0.3),
  },
  countdownRow: {
    maxWidth: '100%',
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
  },
  countdownText: {
    flexShrink: 1,
    color: palette.mint,
    fontSize: 12,
    fontWeight: '700',
  },
  countdownHowTo: {
    color: palette.n300,
    fontSize: 11,
    fontWeight: '500',
  },
  morePill: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    minHeight: 24,
    paddingHorizontal: 10,
    paddingVertical: 3,
    borderRadius: 12,
    backgroundColor: alpha(palette.graphite, 0.82),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.15),
  },
  moreText: {
    color: palette.n200,
    fontSize: 11,
    fontWeight: '700',
  },
  pressed: {
    transform: [{ scale: 0.96 }],
    opacity: 0.85,
  },
});
