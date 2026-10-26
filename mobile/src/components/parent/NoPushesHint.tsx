/**
 * M5-T12 (David 2026-10-26): "Danes še ni potiskov …" — shown to the PARENT only (dashboard
 * child card, child page) when `noPushesHintDue` says so; the button leads to »Vpiši sprehod«.
 * Never shown on the child's HUD.
 */

import { Pressable, StyleSheet, View } from 'react-native';
import { Footprints } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { ADAPTED_PUSH_STRINGS } from '@/modules/steps/adaptedPushHint';
import { palette } from '@/theme';

interface NoPushesHintProps {
  onLogWalk: () => void;
  testID?: string;
}

export default function NoPushesHint({ onLogWalk, testID = 'no-pushes-hint' }: NoPushesHintProps) {
  const S = ADAPTED_PUSH_STRINGS.adapted.noPushesToday;
  return (
    <View style={styles.box} testID={testID}>
      <Text style={styles.text}>{S.text}</Text>
      <Pressable
        onPress={onLogWalk}
        accessibilityRole="button"
        style={({ pressed }) => [styles.button, pressed && styles.pressed]}
        testID={`${testID}-log-walk`}
      >
        <Footprints color={palette.white} size={15} />
        <Text style={styles.buttonText}>{S.button}</Text>
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  box: {
    gap: 8,
    padding: 12,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: C.accentBorder,
    backgroundColor: C.accentSoft,
  },
  text: { fontSize: 13, lineHeight: 18, color: palette.graphite },
  button: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    alignSelf: 'flex-start',
    minHeight: 44,
    paddingHorizontal: 14,
    borderRadius: 12,
    backgroundColor: palette.graphite,
  },
  buttonText: { fontSize: 14, fontWeight: '700', color: palette.white },
  pressed: { opacity: 0.85 },
});
