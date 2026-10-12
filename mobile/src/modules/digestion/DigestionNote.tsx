/**
 * M5-R16 "Počakaj s sprehodom — kuža prebavlja." A calm note (not a refusal, not a
 * warning colour): the walk stays possible and the steps still count.
 */

import { StyleSheet, View } from 'react-native';
import { Hourglass } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { alpha, palette } from '@/theme';
import type { DigestionNoteText } from './digestion';

export default function DigestionNote({ note, testID = 'hud-digesting' }: { note: DigestionNoteText; testID?: string }) {
  return (
    <View style={styles.box} testID={testID} accessible accessibilityRole="text" accessibilityLabel={note.a11y}>
      <Hourglass color={palette.mint} size={14} />
      <Text style={styles.text}>
        <Text style={styles.title}>{note.title}. </Text>
        {note.body}
        {note.until !== null ? ` ${note.until}` : ''}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  box: {
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
  text: { flexShrink: 1, color: palette.n200, fontSize: 12, lineHeight: 17 },
  title: { color: palette.mint, fontWeight: '800' },
});
