/**
 * GameScreen — the full-screen layout every game of the layer uses (GAMES_SPEC §0.1, §3.1,
 * §7.1): the pet video edge to edge (`stage` = ClipDeck), the physical objects over it
 * (`field` = PlayField), the FeedbackLayer in the middle, ONE glass row at the top
 * (`topBar` = GameTopBar, under the status bar / notch) and the hand's dock at the bottom
 * (`dock`: the big button / token, above the home indicator). Dark glass HUD (ADR-007).
 *
 * Z-order (device bug 2026-10-31): every layer is wrapped in its OWN stacking context with an
 * explicit zIndex (`GAME_Z`). Fabric hoists the children of a view that isn't a stacking context
 * into the nearest one and sorts them by zIndex — before this, `ClipDeck`'s front player
 * (`zIndex: 2`) was sorted over the top bar, the ball, the buttons and the feedback (only the
 * video was visible, no way out). Never render a layer outside these wrappers.
 *
 * Exit (mandatory): Android back closes the game (like the top bar's ×, or does nothing while
 * the × is locked); a video stage not ready after `GAME_STAGE_WATCHDOG_MS` or a layer that threw
 * while rendering opens the escape card (× + »Nazaj«) on top of everything; a locked × comes
 * back after `GAME_ESCAPE_MS`. The exit is `onExit`, else the host's `GameExitContext`.
 *
 * Wrapped in its own `GestureHandlerRootView` (the game is the only place with RNGH gestures).
 * Safe areas from the context (renders without a provider in tests).
 */

import { Component, useCallback, useContext, useEffect, useMemo, useRef, useState, type ErrorInfo, type ReactNode } from 'react';
import { BackHandler, Pressable, StyleSheet, View } from 'react-native';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';
import { X } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { strings } from '@/i18n/strings';
import {
  GAME_ESCAPE_MS,
  GAME_STAGE_WATCHDOG_MS,
  GAME_Z,
  GameExitContext,
  GameScreenContext,
  gameEscape,
  type GameExit,
  type GameScreenLink,
  type StageStatus,
} from '@/modules/gameLayer/gameExit';
import { MIN_TOUCH, alpha, fonts, palette, radius, tightTracking } from '@/theme';
import { logRenderError } from '@/utils/logRenderError';

export const GAME_SCREEN_STRINGS = strings('common', 'game');

export interface GameScreenProps {
  stage: ReactNode;
  field?: ReactNode;
  feedback?: ReactNode;
  topBar: ReactNode;
  dock?: ReactNode;
  /** Anything over the whole screen (the result card). */
  overlay?: ReactNode;
  /** The way out (Android back, the escape card, the top bar's × fallback); else `GameExitContext`. */
  onExit?: GameExit;
  testID?: string;
}

const EDGE = 12;

/** One layer that threw keeps the rest of the game (and the escape card) alive. */
class LayerBoundary extends Component<{ onError: () => void; children?: ReactNode }, { failed: boolean }> {
  state = { failed: false };
  static getDerivedStateFromError(): { failed: boolean } {
    return { failed: true };
  }
  componentDidCatch(error: Error, info: ErrorInfo): void {
    // The game is part of the child HUD (a new scope would need the backend `AppErrorScope` enum).
    logRenderError('child-hud', error, info);
    this.props.onError();
  }
  render(): ReactNode {
    return this.state.failed ? null : this.props.children;
  }
}

export default function GameScreen({ stage, field, feedback, topBar, dock, overlay, onExit, testID = 'game-screen' }: GameScreenProps) {
  const insets = useContext(SafeAreaInsetsContext) ?? { top: 0, bottom: 0, left: 0, right: 0 };
  const hostExit = useContext(GameExitContext);
  const exit = onExit ?? hostExit;
  const S = GAME_SCREEN_STRINGS;

  const [stageStatus, setStageStatus] = useState<StageStatus>('none');
  const [stalled, setStalled] = useState(false);
  const [crashed, setCrashed] = useState(false);
  const [close, setClose] = useState<{ action: GameExit | null; locked: boolean } | null>(null);
  const [lockExpired, setLockExpired] = useState(false);
  const closeLocked = close?.locked === true;

  useEffect(() => {
    setStalled(false);
    if (stageStatus !== 'loading') return;
    const timer = setTimeout(() => setStalled(true), GAME_STAGE_WATCHDOG_MS);
    return () => clearTimeout(timer);
  }, [stageStatus]);
  useEffect(() => {
    setLockExpired(false);
    if (!closeLocked) return;
    const timer = setTimeout(() => setLockExpired(true), GAME_ESCAPE_MS);
    return () => clearTimeout(timer);
  }, [closeLocked]);

  const escape = gameEscape({ stage: stageStatus, stalled, crashed, closeLocked, lockExpired });

  // Android back = the ×: its own action (e.g. wand »stop«), else the exit; nothing while locked.
  const backRef = useRef<() => boolean>(() => false);
  backRef.current = () => {
    const action = (escape.card ? null : close?.action) ?? exit;
    if (action === null) return false;
    if (escape.exitOpen) action();
    return true;
  };
  useEffect(() => {
    const sub = BackHandler.addEventListener('hardwareBackPress', () => backRef.current());
    return () => sub.remove();
  }, []);

  const reportClose = useCallback((action: GameExit | null, locked: boolean) => {
    setClose((c) => (c !== null && c.action === action && c.locked === locked ? c : { action, locked }));
  }, []);
  const stageLoading = useCallback(() => setStageStatus('loading'), []);
  const stageReady = useCallback(() => setStageStatus('ready'), []);
  const onLayerError = useCallback(() => setCrashed(true), []);
  const link = useMemo<GameScreenLink>(
    () => ({ exit, exitOpen: escape.exitOpen, reportClose, stageLoading, stageReady }),
    [exit, escape.exitOpen, reportClose, stageLoading, stageReady],
  );

  return (
    <GameScreenContext.Provider value={link}>
      <GestureHandlerRootView style={styles.root} testID={testID}>
        <View style={styles.root} accessibilityViewIsModal>
          <View style={[StyleSheet.absoluteFill, { zIndex: GAME_Z.stage }]} collapsable={false} testID={`${testID}-stage-layer`}>
            {stage}
          </View>
          {/* A soft shade at the top and bottom keeps the white text / buttons readable on any video. */}
          <View pointerEvents="none" style={[styles.shade, styles.shadeTop]} />
          <View pointerEvents="none" style={[styles.shade, styles.shadeBottom]} />
          <View style={[StyleSheet.absoluteFill, { zIndex: GAME_Z.field }]} pointerEvents="box-none" collapsable={false} testID={`${testID}-field-layer`}>
            <LayerBoundary onError={onLayerError}>{field}</LayerBoundary>
          </View>
          <View style={[StyleSheet.absoluteFill, { zIndex: GAME_Z.feedback }]} pointerEvents="box-none" collapsable={false} testID={`${testID}-feedback-layer`}>
            <LayerBoundary onError={onLayerError}>{feedback}</LayerBoundary>
          </View>
          <View
            style={[styles.top, { top: insets.top + 8, left: insets.left + EDGE, right: insets.right + EDGE }]}
            pointerEvents="box-none"
            collapsable={false}
            testID={`${testID}-top-layer`}
          >
            <LayerBoundary onError={onLayerError}>{topBar}</LayerBoundary>
          </View>
          {dock !== undefined && dock !== null && (
            <View
              style={[styles.dock, { bottom: insets.bottom + 20, left: insets.left + EDGE, right: insets.right + EDGE }]}
              pointerEvents="box-none"
              collapsable={false}
              testID={`${testID}-dock-layer`}
            >
              <LayerBoundary onError={onLayerError}>{dock}</LayerBoundary>
            </View>
          )}
          {overlay !== undefined && overlay !== null && (
            <View style={[StyleSheet.absoluteFill, { zIndex: GAME_Z.overlay }]} pointerEvents="box-none" collapsable={false} testID={`${testID}-overlay-layer`}>
              <LayerBoundary onError={onLayerError}>{overlay}</LayerBoundary>
            </View>
          )}
          {escape.card && exit !== null && (
            <View style={[StyleSheet.absoluteFill, styles.escape]} collapsable={false} testID={`${testID}-escape`}>
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={S.close}
                onPress={exit}
                hitSlop={16}
                testID={`${testID}-escape-close`}
                style={({ pressed }) => [styles.escapeClose, { top: insets.top + 8, right: insets.right + EDGE }, pressed && styles.pressed]}
              >
                <X color={palette.white} size={24} />
              </Pressable>
              <View style={styles.escapeCard}>
                <Text style={styles.escapeText} accessibilityLiveRegion="polite">
                  {S.stuck}
                </Text>
                <Pressable
                  accessibilityRole="button"
                  onPress={exit}
                  testID={`${testID}-escape-back`}
                  style={({ pressed }) => [styles.escapeBack, pressed && styles.pressed]}
                >
                  <Text style={styles.escapeBackText}>{S.back}</Text>
                </Pressable>
              </View>
            </View>
          )}
        </View>
      </GestureHandlerRootView>
    </GameScreenContext.Provider>
  );
}

const styles = StyleSheet.create({
  root: { ...StyleSheet.absoluteFill, zIndex: 50, backgroundColor: palette.graphite },
  shade: { position: 'absolute', left: 0, right: 0, height: 140, zIndex: GAME_Z.shade },
  shadeTop: { top: 0, backgroundColor: alpha(palette.graphite, 0.28) },
  shadeBottom: { bottom: 0, backgroundColor: alpha(palette.graphite, 0.22) },
  top: { position: 'absolute', zIndex: GAME_Z.top },
  dock: { position: 'absolute', alignItems: 'center', gap: 10, zIndex: GAME_Z.dock },
  escape: { zIndex: GAME_Z.escape, backgroundColor: alpha(palette.graphite, 0.72), alignItems: 'center', justifyContent: 'center', padding: 24 },
  escapeClose: {
    position: 'absolute',
    width: MIN_TOUCH + 12,
    height: MIN_TOUCH + 12,
    borderRadius: 16,
    backgroundColor: alpha(palette.white, 0.16),
    alignItems: 'center',
    justifyContent: 'center',
  },
  escapeCard: { alignSelf: 'stretch', alignItems: 'center', gap: 16, paddingVertical: 24, paddingHorizontal: 20, borderRadius: 28, backgroundColor: alpha(palette.graphite, 0.9) },
  escapeText: { color: palette.white, fontSize: 20, letterSpacing: tightTracking(20), fontFamily: fonts.displayBold, textAlign: 'center' },
  escapeBack: { alignSelf: 'stretch', minHeight: 60, borderRadius: radius.button, backgroundColor: palette.mint, alignItems: 'center', justifyContent: 'center' },
  escapeBackText: { color: palette.graphite, fontSize: 20, letterSpacing: tightTracking(20), fontFamily: fonts.displayBold },
  pressed: { opacity: 0.8 },
});
