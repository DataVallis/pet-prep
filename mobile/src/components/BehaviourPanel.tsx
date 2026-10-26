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
 * David 2026-10-26: the whole stack collapses (round "×" in line with the first card, or a swipe
 * up) into one slim row — a pill with the most urgent card's icon + short text, "+N" and ▾, then
 * the chips as icon-only buttons; the pill (or a swipe down) expands it. Remembered per device
 * (`modules/hud/topStackPrefs`); a new urgent item expands it once (`topStack#reconcileAlerts`).
 * M5-R06-08b: the cat's scratched sofa gets "Na praskalnik" (`scratcher`) — it opens the
 * scratching mini-game; cleaning never resolves it.
 */

import { useCallback, useEffect, useRef, useState, type ComponentType, type ReactNode } from 'react';
import { Animated, LayoutAnimation, PanResponder, Pressable, StyleSheet, View, type GestureResponderHandlers } from 'react-native';
import { Text } from '@/components/ui/Text';
import { Cat, ChevronDown, ChevronUp, DoorOpen, Droplets, ToyBrick, X } from 'lucide-react-native';

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
import {
  alertTokens,
  arrangeTopStack,
  collapsedSummary,
  completesStackSwipe,
  reconcileAlerts,
  STACK_PRIORITY,
  startsStackSwipe,
  takeOutPriority,
  type StackEntry,
} from '@/modules/hud/topStack';
import { useTopStackPrefs } from '@/modules/hud/topStackPrefs';
import { t } from '@/i18n';
import { MIN_TOUCH, alpha, palette } from '@/theme';

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
  /**
   * The chips ("Šola", "Igra", breed care …), always visible under the cards; null = no chip row.
   * `compact` = the collapsed stack's icon-only round buttons (same actions, full a11y labels).
   */
  renderChips?: ((compact: boolean) => ReactNode) | null;
  /** M5-R04 "Obroki danes" (least urgent card); hidden while a scene card is open. */
  meals?: StackCardBody | null;
  /** M5-F06: the media "getting ready" notice (a card above the meals row). */
  notice?: StackCardBody | null;
  /**
   * M5-R06-08b: "Na praskalnik" on the scratched-sofa card (a cat with an open scratching);
   * null = no button. `disabled` while the server refuses a carry (another child's game).
   */
  scratcher?: { label: string; a11y: string; disabled: boolean; onPress: () => void } | null;
  /** System reduce motion: the clock resets without the short pop animation. */
  reduceMotion?: boolean;
}

/** A lucide icon (or any component with the same two props). */
export type StackIcon = ComponentType<{ color?: string; size?: number }>;

/** What a card shows: the full card, plus the collapsed pill's icon and very short text. */
export interface StackCardBody {
  node: ReactNode;
  /** Collapsed pill text (David 2026-10-26), e.g. "Ven čez ~2 h 25 min" — fallback: the card's title. */
  short: string;
  icon: StackIcon;
  /** The pill icon's colour when not urgent (default mint). */
  iconColour?: string;
}

/** One card of the top stack. */
export interface StackCard extends StackEntry, StackCardBody {}

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
  renderChips = null,
  meals = null,
  notice = null,
  scratcher = null,
  reduceMotion = false,
}: BehaviourPanelProps) {
  const [showAll, setShowAll] = useState(false);
  const collapsed = useTopStackPrefs((s) => s.collapsed);
  const hydrated = useTopStackPrefs((s) => s.hydrated);
  const setCollapsed = useTopStackPrefs((s) => s.setCollapsed);
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
      short: countdown.short,
      icon: DoorOpen,
      iconColour: LEVEL_COLOUR[countdown.level],
      node: <TakeOutClockPill countdown={countdown} resetKey={behaviour.take_out?.clock_started_at ?? null} reduceMotion={reduceMotion} />,
    });
  }
  if (cardScene !== null) {
    const newestEvent = behaviour.active_events[behaviour.active_events.length - 1];
    entries.push({
      key: 'scene',
      priority: STACK_PRIORITY.scene,
      // A new mess (new event id) is a new alert for the collapsed stack's auto-expand.
      alertId: `scene-${cardScene}-${newestEvent?.id ?? 0}`,
      short: BEHAVIOUR_STRINGS.sceneA11y[cardScene],
      icon: SCENE_ICON[cardScene],
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
  if (notice !== null) entries.push({ key: 'notice', priority: STACK_PRIORITY.notice, ...notice });
  entries.push(...cards);
  if (shownMeals !== null) entries.push({ key: 'meals', priority: STACK_PRIORITY.meals, ...shownMeals });

  const stack = arrangeTopStack(entries, showAll);
  // Nothing more to expand → the next overflow starts collapsed again.
  useEffect(() => {
    if (!stack.collapsible && showAll) setShowAll(false);
  }, [stack.collapsible, showAll]);

  useEffect(() => {
    void useTopStackPrefs.getState().hydrate();
  }, []);

  const toggle = useCallback(
    (next: boolean) => {
      if (!reduceMotion) LayoutAnimation.configureNext(LayoutAnimation.Presets.easeInEaseOut);
      setCollapsed(next);
    },
    [reduceMotion, setCollapsed],
  );
  const collapse = useCallback(() => toggle(true), [toggle]);
  const expand = useCallback(() => toggle(false), [toggle]);

  // A NEW urgent item (new mess, clock turning orange / red, first aid) expands a collapsed stack
  // once; the items already seen never do it again (topStack.ts#reconcileAlerts).
  const tokens = alertTokens(entries);
  const tokenKey = tokens.join('|');
  useEffect(() => {
    if (!hydrated) return;
    const prefs = useTopStackPrefs.getState();
    const { fresh, seen } = reconcileAlerts(prefs.seen, tokenKey === '' ? [] : tokenKey.split('|'));
    prefs.setSeen(seen);
    if (fresh && prefs.collapsed) expand();
  }, [tokenKey, hydrated, expand]);

  const swipeUp = useStackSwipe('up', collapse);
  const swipeDown = useStackSwipe('down', expand);

  if (entries.length === 0 && renderChips === null) return null;

  const closeButton = (
    <Pressable
      testID="hud-top-stack-collapse"
      accessibilityRole="button"
      accessibilityLabel={t('child:hud.topStack.collapseA11y')}
      accessibilityState={{ expanded: true }}
      hitSlop={CLOSE_HIT_SLOP}
      onPress={collapse}
      style={({ pressed }) => [styles.closeButton, pressed && styles.pressed]}
    >
      <X color={palette.n200} size={14} strokeWidth={2.5} />
    </Pressable>
  );

  if (collapsed) {
    const summary = collapsedSummary(entries);
    const top = summary.top;
    const TopIcon = top?.icon ?? null;
    return (
      <View
        pointerEvents="box-none"
        style={styles.stack}
        testID="hud-behaviour"
        {...swipeDown.panHandlers}
      >
        <View pointerEvents="box-none" style={styles.collapsedRow} testID="hud-top-stack-collapsed">
          {top !== null && TopIcon !== null ? (
            <Pressable
              testID="hud-top-stack-pill"
              accessibilityRole="button"
              accessibilityLabel={`${top.short}. ${t('child:hud.topStack.expandA11y', { count: entries.length })}`}
              accessibilityState={{ expanded: false }}
              hitSlop={PILL_HIT_SLOP}
              onPress={expand}
              style={({ pressed }) => [styles.pill, summary.urgent && styles.pillUrgent, pressed && styles.pressed]}
            >
              <TopIcon color={summary.urgent ? palette.dangerDark : (top.iconColour ?? palette.mint)} size={14} />
              <Text
                style={[styles.pillText, summary.urgent && styles.pillTextUrgent]}
                numberOfLines={1}
                maxFontSizeMultiplier={1.3}
                testID="hud-top-stack-pill-text"
              >
                {top.short}
              </Text>
              {summary.others > 0 && (
                <View style={styles.pillBadge} testID="hud-top-stack-pill-count">
                  <Text style={styles.pillBadgeText} maxFontSizeMultiplier={1.2}>{`+${summary.others}`}</Text>
                </View>
              )}
              <ChevronDown color={summary.urgent ? palette.dangerDark : palette.n200} size={14} />
            </Pressable>
          ) : (
            <Pressable
              testID="hud-top-stack-expand"
              accessibilityRole="button"
              accessibilityLabel={t('child:hud.topStack.expandEmptyA11y')}
              accessibilityState={{ expanded: false }}
              hitSlop={CLOSE_HIT_SLOP}
              onPress={expand}
              style={({ pressed }) => [styles.closeButton, pressed && styles.pressed]}
            >
              <ChevronDown color={palette.n200} size={14} />
            </Pressable>
          )}
          {renderChips !== null && (
            <View pointerEvents="box-none" style={styles.chipRow} testID="hud-chip-row">
              {renderChips(true)}
            </View>
          )}
        </View>
      </View>
    );
  }

  const [first, ...rest] = stack.visible;
  return (
    <View pointerEvents="box-none" style={styles.stack} testID="hud-behaviour" {...swipeUp.panHandlers}>
      {first !== undefined && (
        <View key={first.key} pointerEvents="box-none" style={styles.firstRow}>
          <View pointerEvents="box-none" style={styles.firstCard}>
            {first.node}
          </View>
          {closeButton}
        </View>
      )}
      {rest.map((card) => (
        <View key={card.key} pointerEvents="box-none" style={styles.cardSlot}>
          {card.node}
        </View>
      ))}
      {stack.collapsible && (
        <Pressable
          testID={showAll ? 'hud-top-stack-less' : 'hud-top-stack-more'}
          accessibilityRole="button"
          accessibilityLabel={
            showAll ? t('child:hud.topStack.lessA11y') : t('child:hud.topStack.moreA11y', { count: stack.hiddenCount })
          }
          accessibilityState={{ expanded: showAll }}
          hitSlop={10}
          onPress={() => setShowAll((v) => !v)}
          style={({ pressed }) => [styles.morePill, pressed && styles.pressed]}
        >
          {showAll ? (
            <ChevronUp color={palette.n200} size={12} />
          ) : (
            <ChevronDown color={palette.n200} size={12} />
          )}
          <Text style={styles.moreText} maxFontSizeMultiplier={1.3}>
            {showAll ? t('child:hud.topStack.less') : t('child:hud.topStack.more', { count: stack.hiddenCount })}
          </Text>
        </Pressable>
      )}
      {renderChips !== null && (
        <View pointerEvents="box-none" style={styles.chipRow} testID="hud-chip-row">
          {renderChips(false)}
          {first === undefined && closeButton}
        </View>
      )}
    </View>
  );
}

/**
 * Swipe up (collapse) / down (expand) on the stack. Capture phase, so a vertical drag that starts
 * on a card's button still moves the stack; a tap never travels {@link TOP_STACK_SWIPE_START}.
 */
function useStackSwipe(direction: 'up' | 'down', onSwipe: () => void): { panHandlers: GestureResponderHandlers } {
  const swipeRef = useRef(onSwipe);
  swipeRef.current = onSwipe;
  const pan = useRef(
    PanResponder.create({
      onMoveShouldSetPanResponderCapture: (_e, g) => startsStackSwipe(direction, g.dx, g.dy),
      onPanResponderRelease: (_e, g) => {
        if (completesStackSwipe(direction, g.dy, g.vy)) swipeRef.current();
      },
      onPanResponderTerminationRequest: () => false,
    }),
  ).current;
  return { panHandlers: pan.panHandlers };
}

/** 28 pt round "×" / "▾" + 8 pt each side = 44 pt touch target. */
const CLOSE_SIZE = 28;
const CLOSE_HIT_SLOP = (MIN_TOUCH - CLOSE_SIZE) / 2;
/** The pill is 30 pt high: + 7 pt above and below = 44 pt. */
const PILL_HEIGHT = 30;
const PILL_HIT_SLOP = { top: (MIN_TOUCH - PILL_HEIGHT) / 2, bottom: (MIN_TOUCH - PILL_HEIGHT) / 2, left: 4, right: 4 };

/** Icon of each mess in the collapsed pill. */
const SCENE_ICON: Record<'accident' | 'chewing' | 'scratching', StackIcon> = {
  accident: Droplets,
  chewing: ToyBrick,
  scratching: Cat,
};

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
  /** The first card and the round "×" side by side — the × takes no extra row. */
  firstRow: {
    alignSelf: 'stretch',
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: 6,
  },
  firstCard: {
    flexShrink: 1,
    alignItems: 'flex-start',
  },
  closeButton: {
    width: CLOSE_SIZE,
    height: CLOSE_SIZE,
    borderRadius: CLOSE_SIZE / 2,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: alpha(palette.graphite, 0.82),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.2),
  },
  /** "Šola" + "Igra" + breed care side by side under the cards (M5-R05, David 2026-10-25). */
  chipRow: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    justifyContent: 'flex-start',
    alignItems: 'center',
    gap: 6,
  },
  /** Collapsed (David 2026-10-26): one slim row — the pill, then the icon-only chips. */
  collapsedRow: {
    alignSelf: 'stretch',
    flexDirection: 'row',
    flexWrap: 'wrap',
    alignItems: 'center',
    gap: 6,
  },
  pill: {
    flexShrink: 1,
    maxWidth: '100%',
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    minHeight: PILL_HEIGHT,
    paddingHorizontal: 10,
    borderRadius: PILL_HEIGHT / 2,
    backgroundColor: alpha(palette.graphite, 0.85),
    borderWidth: 1,
    borderColor: alpha(palette.mint, 0.35),
  },
  pillUrgent: {
    backgroundColor: alpha(palette.graphite, 0.9),
    borderColor: palette.dangerDark,
    borderWidth: 1.5,
  },
  pillText: {
    flexShrink: 1,
    color: palette.n200,
    fontSize: 12,
    fontWeight: '700',
  },
  pillTextUrgent: {
    color: palette.white,
  },
  pillBadge: {
    minWidth: 22,
    paddingHorizontal: 5,
    paddingVertical: 1,
    borderRadius: 10,
    alignItems: 'center',
    backgroundColor: alpha(palette.white, 0.15),
  },
  pillBadgeText: {
    color: palette.white,
    fontSize: 11,
    fontWeight: '800',
  },
});
