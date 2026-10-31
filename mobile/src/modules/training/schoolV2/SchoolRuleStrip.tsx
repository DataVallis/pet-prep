/**
 * The school v2 rule strip (David's device test 2026-10-31: »naj navodila skos kaže«): one glass
 * strip under the top bar, on screen the WHOLE session — while the deck loads, every cue, the break
 * and while saving — »Ko kuža uboga → pritisni ♥. Če ne uboga → počakaj.« (treat wording with
 * `training.treat_token`). Up to two lines: the text wraps, it is never cut (no `numberOfLines`,
 * so no ellipsis). GameLine stays for momentary feedback only.
 *
 * Plain React Native (no game-layer import).
 */

import { StyleSheet, View } from 'react-native';

import { Text } from '@/components/ui/Text';
import type { PraiseToken } from '@/modules/training/schoolV2/schoolV2';
import { SCHOOL_V2_STRINGS } from '@/modules/training/training';
import { alpha, palette, radius } from '@/theme';

export function ruleText(token: PraiseToken): string {
  return token === 'treat' ? SCHOOL_V2_STRINGS.ruleTreat : SCHOOL_V2_STRINGS.rule;
}

export default function SchoolRuleStrip({ token, testID = 'school-rule' }: { token: PraiseToken; testID?: string }) {
  return (
    <View style={styles.strip} accessible accessibilityRole="text" accessibilityLabel={SCHOOL_V2_STRINGS.ruleA11y} testID={testID}>
      <Text style={styles.text} testID={`${testID}-text`}>
        {ruleText(token)}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  strip: {
    paddingHorizontal: 14,
    paddingVertical: 8,
    borderRadius: radius.button,
    backgroundColor: alpha(palette.graphite, 0.72),
    borderWidth: 1,
    borderColor: alpha(palette.mint, 0.45),
  },
  text: { color: palette.white, fontSize: 16, lineHeight: 21, fontWeight: '700', textAlign: 'center' },
});
