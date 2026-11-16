/**
 * The school v2 rule strip (David's device test 2026-10-31: »naj navodila skos kaže«): one slim
 * glass strip, on screen the WHOLE session — while the deck loads, every cue, the break and while
 * saving — »Uboga → ♥ · Ne uboga → počakaj« (treat wording with `training.treat_token`).
 * Since David 2026-11-16 (»vse je čez glavo«) it sits at the BOTTOM of the dock, under the token,
 * short (≤ 40 characters, test) so it is one line on most phones; it may wrap, it is never cut
 * (no `numberOfLines`, so no ellipsis). A screen reader hears the full sentence (`ruleA11y`).
 * GameLine stays for momentary feedback only.
 *
 * Plain React Native (no game-layer import).
 */

import { StyleSheet, View } from 'react-native';

import { Text } from '@/components/ui/Text';
import type { PraiseToken } from '@/modules/training/schoolV2/schoolV2';
import { SCHOOL_V2_STRINGS } from '@/modules/training/training';
import { alpha, palette, radius } from '@/theme';

/** The visible rule is one short line on most phones (David 2026-11-16: nothing over the pet's head). */
export const RULE_MAX_CHARS = 40;

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
    maxWidth: '100%',
    paddingHorizontal: 14,
    paddingVertical: 6,
    borderRadius: radius.button,
    backgroundColor: alpha(palette.graphite, 0.72),
    borderWidth: 1,
    borderColor: alpha(palette.mint, 0.45),
  },
  text: { color: palette.white, fontSize: 15, lineHeight: 20, fontWeight: '700', textAlign: 'center' },
});
