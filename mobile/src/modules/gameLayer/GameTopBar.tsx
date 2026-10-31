/**
 * GameTopBar — the ONE row at the top of a game (GAMES_SPEC §3.1, §7.1): progress dots and
 * stars on the left, the game line in the middle, × on the right. Never under the thumb
 * (the bottom ~30 % of the screen belongs to the hand). Symbols instead of numbers (6–10 y.).
 */

import { StyleSheet, View, Pressable } from 'react-native';
import { Star, X } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
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
  /** Omitted → no × (a mess that must be cleaned keeps its game, as the old overlay did). */
  onClose?: () => void;
  testID?: string;
}

export default function GameTopBar({ dots, stars, line, closeLabel, progressLabel, onClose, testID = 'game-top-bar' }: GameTopBarProps) {
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
      {onClose !== undefined ? (
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={closeLabel}
          onPress={onClose}
          hitSlop={10}
          testID={`${testID}-close`}
          style={({ pressed }) => [styles.close, pressed && styles.pressed]}
        >
          <X color={palette.n200} size={20} />
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
    backgroundColor: alpha(palette.white, 0.08),
    alignItems: 'center',
    justifyContent: 'center',
  },
  closeSpacer: { width: 4 },
  pressed: { opacity: 0.75 },
});
