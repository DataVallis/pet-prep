/**
 * "Obroki danes" (M5-R04) — today's feed windows as a calm glass chip row just above the
 * action dock (the feed button's neighbourhood): ✓ for a proven meal, the current window
 * highlighted in mint, quiet-hours windows labelled "nahrani starš", ended windows dimmed.
 * No red, no "missed" — the child only sees when the dog eats and who feeds it.
 * Model and rules: `modules/childPet/mealWindows.ts`. Nothing renders without windows.
 * `compact` (smaller phones, HUD metric variant ≠ regular): one line, no title, smaller
 * chips; the screen reader still hears the full sentence with the title.
 */

import { StyleSheet, View } from 'react-native';
import { Text } from '@/components/ui/Text';
import { Check } from 'lucide-react-native';

import { MEAL_STRINGS, mealWindowsA11y, type MealWindowItem } from '@/modules/childPet/mealWindows';
import { alpha, palette } from '@/theme';

export interface MealWindowsRowProps {
  items: readonly MealWindowItem[];
  /** One line without the title (small screens). */
  compact?: boolean;
}

export default function MealWindowsRow({ items, compact = false }: MealWindowsRowProps) {
  if (items.length === 0) return null;
  return (
    <View
      style={[styles.card, compact && styles.cardCompact]}
      testID={compact ? 'hud-meals-compact' : 'hud-meals'}
      accessible
      accessibilityLabel={mealWindowsA11y(items)}
    >
      {!compact && <Text style={styles.title}>{MEAL_STRINGS.title}</Text>}
      <View style={[styles.row, compact && styles.rowCompact]}>
        {items.map((item) => (
          <View
            key={item.key}
            testID={`hud-meal-${item.key}`}
            style={[styles.chip, compact && styles.chipCompact, item.current && styles.chipCurrent, item.past && styles.chipPast]}
          >
            <Text style={[styles.time, item.current && styles.timeCurrent]}>{item.label}</Text>
            {item.done && (
              <View style={styles.done} testID={`hud-meal-${item.key}-done`}>
                <Check color={palette.white} size={9} />
              </View>
            )}
            {item.byParent && (
              <Text style={styles.byParent} testID={`hud-meal-${item.key}-parent`}>
                {MEAL_STRINGS.byParent}
              </Text>
            )}
          </View>
        ))}
      </View>
    </View>
  );
}

/** Dark glass like the take-out countdown pill; mint = now, ok-green ✓. */
const styles = StyleSheet.create({
  card: {
    maxWidth: '100%',
    alignItems: 'center',
    gap: 6,
    paddingHorizontal: 10,
    paddingVertical: 7,
    borderRadius: 16,
    backgroundColor: alpha(palette.graphite, 0.82),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.12),
  },
  cardCompact: { paddingHorizontal: 6, paddingVertical: 4, borderRadius: 12 },
  title: { color: palette.n400, fontSize: 11, fontWeight: '700' },
  row: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'center', gap: 6 },
  rowCompact: { flexWrap: 'nowrap', gap: 4 },
  chip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    paddingHorizontal: 8,
    paddingVertical: 3,
    borderRadius: 10,
    backgroundColor: alpha(palette.white, 0.06),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.1),
  },
  chipCompact: { paddingHorizontal: 5, paddingVertical: 2, gap: 3 },
  chipCurrent: { backgroundColor: alpha(palette.mint, 0.16), borderColor: alpha(palette.mint, 0.6) },
  chipPast: { opacity: 0.5 },
  time: { color: palette.n200, fontSize: 12, fontWeight: '700' },
  timeCurrent: { color: palette.mint },
  done: {
    width: 14,
    height: 14,
    borderRadius: 7,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: palette.okDark,
  },
  byParent: { color: palette.n300, fontSize: 11, fontWeight: '600' },
});
