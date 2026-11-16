/**
 * One alarm-clock wheel (David 2026-11-16: »da se čas nastavi nek dropdown al nekaj,
 * interaktivno« → wheels, one finger, no typing). Plain `ScrollView` with snapping — no
 * native module, ships by OTA.
 *
 * - Scroll and let go: the row in the middle band is chosen (`onMomentumScrollEnd` /
 *   `onScrollEndDrag` → nearest row). Tap a row: it is chosen and scrolls to the middle.
 * - Screen readers (VoiceOver / TalkBack): the column is ONE `adjustable` element — swipe
 *   up / down = `increment` / `decrement` (no wrap), value read as the row's label; the
 *   rows themselves are hidden from accessibility. Works with »Lažje kretnje« (taps only).
 * - Small phones: 3 visible rows (`compact`), 5 otherwise; rows ≥ 44 pt (MIN_TOUCH).
 * Pure index math lives in `modules/time/wheel.ts` (Jest).
 */

import { useEffect, useRef } from 'react';
import {
  Pressable,
  ScrollView,
  StyleSheet,
  View,
  type AccessibilityActionEvent,
  type NativeScrollEvent,
  type NativeSyntheticEvent,
} from 'react-native';

import { Text } from '@/components/ui/Text';
import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { MIN_TOUCH, radius } from '@/theme';
import { indexAtOffset, stepIndex, type WheelItem } from '@/modules/time/wheel';

export const WHEEL_ROW_HEIGHT = MIN_TOUCH;

interface WheelColumnProps<T extends string | number> {
  items: readonly WheelItem<T>[];
  value: T;
  onChange: (value: T) => void;
  /** Screen-reader name of the column ("Ura", "Minute", "AM / PM"). */
  accessibilityLabel: string;
  /** 3 visible rows instead of 5 (small phones). */
  compact?: boolean;
  testID: string;
}

export default function WheelColumn<T extends string | number>({
  items,
  value,
  onChange,
  accessibilityLabel,
  compact = false,
  testID,
}: WheelColumnProps<T>) {
  const visible = compact ? 3 : 5;
  const pad = ((visible - 1) / 2) * WHEEL_ROW_HEIGHT;
  const scroll = useRef<ScrollView>(null);
  const found = items.findIndex((item) => item.value === value);
  const index = found < 0 ? 0 : found;

  // Keep the chosen row in the middle when the value changes from outside (AM/PM flip, a11y).
  useEffect(() => {
    scroll.current?.scrollTo({ y: index * WHEEL_ROW_HEIGHT, animated: false });
  }, [index]);

  const choose = (next: number) => {
    const item = items[next];
    if (item && item.value !== value) onChange(item.value);
  };

  const settle = (event: NativeSyntheticEvent<NativeScrollEvent>) => {
    choose(indexAtOffset(event.nativeEvent.contentOffset.y, WHEEL_ROW_HEIGHT, items.length));
  };

  const onAction = (event: AccessibilityActionEvent) => {
    if (event.nativeEvent.actionName === 'increment') choose(stepIndex(index, +1, items.length));
    if (event.nativeEvent.actionName === 'decrement') choose(stepIndex(index, -1, items.length));
  };

  return (
    <View
      style={[styles.column, { height: visible * WHEEL_ROW_HEIGHT }]}
      accessible
      accessibilityRole="adjustable"
      accessibilityLabel={accessibilityLabel}
      accessibilityValue={{ text: items[index]?.label ?? '' }}
      accessibilityActions={[{ name: 'increment' }, { name: 'decrement' }]}
      onAccessibilityAction={onAction}
      testID={testID}
    >
      <View pointerEvents="none" style={[styles.band, { top: pad }]} />
      <ScrollView
        ref={scroll}
        showsVerticalScrollIndicator={false}
        snapToInterval={WHEEL_ROW_HEIGHT}
        decelerationRate="fast"
        contentOffset={{ x: 0, y: index * WHEEL_ROW_HEIGHT }}
        contentContainerStyle={{ paddingVertical: pad }}
        onMomentumScrollEnd={settle}
        onScrollEndDrag={(event) => {
          // No momentum (a slow drag): settle now; a fling settles in onMomentumScrollEnd.
          if (Math.abs(event.nativeEvent.velocity?.y ?? 0) < 0.05) settle(event);
        }}
        importantForAccessibility="no-hide-descendants"
        accessibilityElementsHidden
        testID={`${testID}-scroll`}
      >
        {items.map((item, i) => {
          const selected = i === index;
          return (
            <Pressable
              key={String(item.value)}
              style={styles.row}
              onPress={() => {
                choose(i);
                scroll.current?.scrollTo({ y: i * WHEEL_ROW_HEIGHT, animated: true });
              }}
              testID={`${testID}-item-${item.value}`}
            >
              <Text style={[styles.text, selected && styles.textSelected]}>{item.label}</Text>
            </Pressable>
          );
        })}
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  column: { flex: 1, minWidth: 64, overflow: 'hidden' },
  band: {
    position: 'absolute',
    left: 4,
    right: 4,
    height: WHEEL_ROW_HEIGHT,
    borderRadius: radius.input,
    backgroundColor: C.accentSoft,
    borderWidth: 1,
    borderColor: C.accentBorder,
  },
  row: { height: WHEEL_ROW_HEIGHT, alignItems: 'center', justifyContent: 'center' },
  text: { fontSize: 18, color: C.muted },
  textSelected: { fontSize: 20, fontWeight: '700', color: C.text },
});
