/**
 * GameScreen — the full-screen layout every game of the layer uses (GAMES_SPEC §0.1, §3.1,
 * §7.1): the pet video edge to edge (`stage` = ClipDeck), the physical objects over it
 * (`field` = PlayField), the FeedbackLayer in the middle, ONE glass row at the top
 * (`topBar` = GameTopBar, under the status bar / notch) and the hand's dock at the bottom
 * (`dock`: the big button / token, above the home indicator). Dark glass HUD (ADR-007).
 *
 * Wrapped in its own `GestureHandlerRootView` (the game is the only place with RNGH gestures).
 * Safe areas from the context (renders without a provider in tests).
 */

import { useContext, type ReactNode } from 'react';
import { StyleSheet, View } from 'react-native';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';

import { alpha, palette } from '@/theme';

export interface GameScreenProps {
  stage: ReactNode;
  field?: ReactNode;
  feedback?: ReactNode;
  topBar: ReactNode;
  dock?: ReactNode;
  /** Anything over the whole screen (the result card). */
  overlay?: ReactNode;
  testID?: string;
}

const EDGE = 12;

export default function GameScreen({ stage, field, feedback, topBar, dock, overlay, testID = 'game-screen' }: GameScreenProps) {
  const insets = useContext(SafeAreaInsetsContext) ?? { top: 0, bottom: 0, left: 0, right: 0 };
  return (
    <GestureHandlerRootView style={styles.root} testID={testID}>
      <View style={styles.root} accessibilityViewIsModal>
        <View style={StyleSheet.absoluteFill}>{stage}</View>
        {/* A soft shade at the top and bottom keeps the white text / buttons readable on any video. */}
        <View pointerEvents="none" style={[styles.shade, styles.shadeTop]} />
        <View pointerEvents="none" style={[styles.shade, styles.shadeBottom]} />
        {field}
        {feedback}
        <View style={[styles.top, { top: insets.top + 8, left: insets.left + EDGE, right: insets.right + EDGE }]}>{topBar}</View>
        {dock !== undefined && dock !== null && (
          <View style={[styles.dock, { bottom: insets.bottom + 20, left: insets.left + EDGE, right: insets.right + EDGE }]} pointerEvents="box-none">
            {dock}
          </View>
        )}
        {overlay}
      </View>
    </GestureHandlerRootView>
  );
}

const styles = StyleSheet.create({
  root: { ...StyleSheet.absoluteFill, zIndex: 50, backgroundColor: palette.graphite },
  shade: { position: 'absolute', left: 0, right: 0, height: 140 },
  shadeTop: { top: 0, backgroundColor: alpha(palette.graphite, 0.28) },
  shadeBottom: { bottom: 0, backgroundColor: alpha(palette.graphite, 0.22) },
  top: { position: 'absolute' },
  dock: { position: 'absolute', alignItems: 'center', gap: 10 },
});
