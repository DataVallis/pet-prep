/**
 * Cat chips above the HUD dock (M5-R06-08b): "Počeši" (Maine Coon, while this week's brushing
 * is open) and "Menjava peska" (the weekly full litter change, until it is done). Glass chips
 * like "Šola" — the dock already holds five buttons. A small amber dot = it can be done now;
 * a matted coat / smelly tray adds one calm line under the chips (never red, never shaming).
 * Tapping opens the mini-game (`openCatGame`), which explains a block with its time.
 * Also the "first aid" note [C24] while the cat is hungry (educational only).
 */

import { Pressable, StyleSheet, View } from 'react-native';
import { Text } from '@/components/ui/Text';
import { Brush, Info, Recycle } from 'lucide-react-native';

import { CAT_HUD_STRINGS, type CatChip } from '@/modules/catCare/catHud';
import { MIN_TOUCH, alpha, palette } from '@/theme';

export interface CatHudChipProps {
  chip: CatChip;
  onPress: () => void;
}

export function CatHudChip({ chip, onPress }: CatHudChipProps) {
  const Icon = chip.kind === 'grooming' ? Brush : Recycle;
  return (
    <Pressable
      testID={`hud-cat-chip-${chip.kind}`}
      accessibilityRole="button"
      accessibilityLabel={chip.a11y}
      onPress={onPress}
      hitSlop={6}
      style={({ pressed }) => [styles.chip, pressed && styles.pressed]}
    >
      <Icon color={palette.mint} size={16} />
      <Text style={styles.text}>{chip.label}</Text>
      {chip.pending && <View style={styles.badge} testID={`hud-cat-chip-${chip.kind}-badge`} />}
    </Pressable>
  );
}

/** One calm line under the chips ("V dlaki ima vozel — počeši jo."). */
export function CatHudNote({ text, testID }: { text: string; testID?: string }) {
  return (
    <Text style={styles.note} testID={testID}>
      {text}
    </Text>
  );
}

/** "Dobro je vedeti: …" — educational, shown while the cat is hungry (C24). */
export function FirstAidNote() {
  const s = CAT_HUD_STRINGS.firstAid;
  return (
    <View style={styles.firstAid} testID="hud-cat-first-aid" accessible accessibilityLabel={`${s.title}. ${s.body}`}>
      <Info color={palette.mint} size={14} />
      <Text style={styles.firstAidText}>
        <Text style={styles.firstAidTitle}>{s.title}: </Text>
        {s.body}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  chip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    minHeight: MIN_TOUCH,
    paddingHorizontal: 14,
    borderRadius: 20,
    backgroundColor: alpha(palette.graphite, 0.85),
    borderWidth: 1,
    borderColor: alpha(palette.mint, 0.35),
  },
  text: { color: palette.white, fontSize: 14, fontWeight: '800' },
  badge: { width: 9, height: 9, borderRadius: 5, backgroundColor: palette.warnDark },
  note: {
    maxWidth: '100%',
    color: palette.n300,
    fontSize: 12,
    fontWeight: '600',
    textAlign: 'center',
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: 12,
    overflow: 'hidden',
    backgroundColor: alpha(palette.graphite, 0.82),
  },
  firstAid: {
    maxWidth: '100%',
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: 6,
    paddingHorizontal: 12,
    paddingVertical: 8,
    borderRadius: 14,
    backgroundColor: alpha(palette.graphite, 0.85),
    borderWidth: 1,
    borderColor: alpha(palette.mint, 0.3),
  },
  firstAidText: { flexShrink: 1, color: palette.n200, fontSize: 12, lineHeight: 17 },
  firstAidTitle: { color: palette.mint, fontWeight: '800' },
  pressed: { opacity: 0.75 },
});
