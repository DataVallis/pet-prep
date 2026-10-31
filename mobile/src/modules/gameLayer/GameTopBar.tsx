/**
 * GameTopBar — the ONE row at the top of a game (GAMES_SPEC §3.1, §7.1): progress dots and
 * stars on the left, the game line in the middle, × on the right. Never under the thumb
 * (the bottom ~30 % of the screen belongs to the hand). Symbols instead of numbers (6–10 y.).
 *
 * The × is ALWAYS there (David 2026-10-31, ball v2 device bug): its action is `onClose`, else the
 * screen's exit (`GameScreen onExit` / the host's `GameExitContext`). `closeLocked` hides it for a
 * while (a mess that must be cleaned, a request in flight); after `GAME_ESCAPE_MS` it comes back.
 * The bar reports its × to `GameScreen`, so Android back does the same thing.
 */

import { useContext, useEffect } from 'react';
import { StyleSheet, View, Pressable } from 'react-native';
import { Star, X } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { closeActionOf, GameScreenContext } from '@/modules/gameLayer/gameExit';
import GameLine from '@/modules/gameLayer/GameLine';
import { MIN_TOUCH, alpha, palette, radius } from '@/theme';

export interface GameTopBarProps {
  /** One dot per step of the round (true = done). */
  dots: readonly boolean[];
  /** Stars earned this round (display only). */
  stars: number;
  line: string | null;
  closeLabel: string;
  /** Screen-reader summary of the dots / stars ("Met 2 od 3, 1 zvezdica"). */
  progressLabel: string;
  /** The ×'s action (e.g. wand »stop«); omitted → the screen's exit. */
  onClose?: () => void;
  /** Hide the × for now (a mess that must be cleaned, a request in flight); back after GAME_ESCAPE_MS. */
  closeLocked?: boolean;
  testID?: string;
}

export default function GameTopBar({ dots, stars, line, closeLabel, progressLabel, onClose, closeLocked = false, testID = 'game-top-bar' }: GameTopBarProps) {
  const screen = useContext(GameScreenContext);
  const action = closeActionOf(onClose, screen.exit);
  const { reportClose } = screen;
  useEffect(() => {
    reportClose(action, closeLocked);
  }, [reportClose, action, closeLocked]);
  useEffect(() => () => reportClose(null, false), [reportClose]);
  const showClose = action !== null && (!closeLocked || screen.exitOpen);
  return (
    <View style={styles.bar} testID={testID}>
      <View style={styles.progress} accessible accessibilityLabel={progressLabel} testID={`${testID}-progress`}>
        {dots.map((done, i) => (
          <View key={i} style={[styles.dot, done && styles.dotDone]} testID={`${testID}-dot-${done ? 'done' : 'open'}`} />
        ))}
        {stars > 0 && (
          <View style={styles.stars} testID={`${testID}-stars`}>
            <Star color={palette.warnDark} fill={palette.warnDark} size={16} />
            <Text style={styles.starCount}>{stars}</Text>
          </View>
        )}
      </View>
      <GameLine text={line} testID={`${testID}-line`} />
      {showClose ? (
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={closeLabel}
          onPress={() => action?.()}
          hitSlop={16}
          testID={`${testID}-close`}
          style={({ pressed }) => [styles.close, pressed && styles.pressed]}
        >
          <X color={palette.white} size={22} />
        </Pressable>
      ) : (
        <View style={styles.closeSpacer} />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  bar: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    minHeight: MIN_TOUCH + 8,
    paddingLeft: 14,
    paddingRight: 4,
    borderRadius: radius.button,
    backgroundColor: alpha(palette.graphite, 0.6),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.12),
  },
  progress: { flexDirection: 'row', alignItems: 'center', gap: 6, minWidth: 64 },
  dot: { width: 10, height: 10, borderRadius: 5, borderWidth: 2, borderColor: alpha(palette.white, 0.6) },
  dotDone: { backgroundColor: palette.mint, borderColor: palette.mint },
  stars: { flexDirection: 'row', alignItems: 'center', gap: 2, marginLeft: 4 },
  starCount: { color: palette.white, fontSize: 14, fontWeight: '700' },
  close: {
    width: MIN_TOUCH,
    height: MIN_TOUCH,
    borderRadius: 12,
    backgroundColor: alpha(palette.white, 0.16),
    alignItems: 'center',
    justifyContent: 'center',
  },
  closeSpacer: { width: 4 },
  pressed: { opacity: 0.75 },
});
