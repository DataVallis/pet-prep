/**
 * GameLine — the one line of game text at the top (GAMES_SPEC §3.1, §7.1). Rules in
 * `gameLine.ts` (≤ 32 chars, ≥ 2 s, newest waiting line wins); this file runs its timer,
 * renders it and announces it (Android live region, iOS `announceForAccessibility`).
 */

import { useCallback, useEffect, useRef, useState } from 'react';
import { AccessibilityInfo, Platform, StyleSheet } from 'react-native';

import { Text } from '@/components/ui/Text';
import { EMPTY_GAME_LINE, nextLineChangeAt, sayLine, tickLine, type GameLineState } from '@/modules/gameLayer/gameLine';
import { palette } from '@/theme';

export interface GameLineApi {
  text: string | null;
  /** Ask for a line (`null` clears it after the shown one had its 2 s). */
  say: (text: string | null) => void;
}

export function useGameLine(now: () => number = Date.now): GameLineApi {
  const [state, setState] = useState<GameLineState>(EMPTY_GAME_LINE);
  const ref = useRef(state);
  const nowRef = useRef(now);
  nowRef.current = now;

  const say = useCallback((text: string | null) => {
    const next = sayLine(ref.current, text, nowRef.current());
    if (next === ref.current) return;
    ref.current = next;
    setState(next);
  }, []);

  const changeAt = nextLineChangeAt(state);
  useEffect(() => {
    if (changeAt === null) return;
    const timer = setTimeout(() => {
      const next = tickLine(ref.current, nowRef.current());
      if (next === ref.current) return;
      ref.current = next;
      setState(next);
    }, Math.max(0, changeAt - nowRef.current()));
    return () => clearTimeout(timer);
  }, [changeAt]);

  return { text: state.shown, say };
}

export default function GameLine({ text, testID = 'game-line' }: { text: string | null; testID?: string }) {
  useEffect(() => {
    if (text !== null && Platform.OS === 'ios') AccessibilityInfo.announceForAccessibility(text);
  }, [text]);
  return (
    <Text style={styles.line} numberOfLines={1} accessibilityLiveRegion="polite" testID={testID}>
      {text ?? ''}
    </Text>
  );
}

const styles = StyleSheet.create({
  line: { flex: 1, color: palette.white, fontSize: 17, fontWeight: '700', textAlign: 'center' },
});
