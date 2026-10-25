/**
 * Shared required-field marker (tester feedback, David 2026-10-25: "označi obvezna polja
 * povsod"). Every form label or section title of a required field renders through
 * {@link FieldLabel} with `required`: the label, then a red asterisk; screen readers hear
 * "<label>, obvezno" / "<label>, required" (the asterisk itself is never read). A form with
 * required fields shows {@link RequiredLegend} ("* obvezno polje") once, near its top.
 * Inputs labelled by a required label pass {@link requiredA11yLabel} as their
 * `accessibilityLabel`. Light parent forms by default; `tone="dark"` for the child theme.
 */

import { StyleSheet, View, type StyleProp, type TextStyle, type ViewStyle } from 'react-native';

import { Text } from '@/components/ui/Text';
import { t } from '@/i18n';
import { dark, light } from '@/theme';

/** The visible marker after a required label. */
export const REQUIRED_MARK = '*';

/** The spoken label of a field: "<label>, obvezno" when required, else the label itself. */
export function requiredA11yLabel(label: string, required = true): string {
  return required ? t('common:required.a11y', { label }) : label;
}

type Tone = 'light' | 'dark';

interface FieldLabelProps {
  children: string;
  required?: boolean;
  /** Text style of the label (the section title / field label style of the form). */
  style?: StyleProp<TextStyle>;
  /** Layout of the row (margins), e.g. a section title's `marginTop`. */
  containerStyle?: StyleProp<ViewStyle>;
  /** Section titles keep `header`, so screen-reader heading navigation still works. */
  accessibilityRole?: 'header' | 'text';
  tone?: Tone;
  nativeID?: string;
  testID?: string;
}

export function FieldLabel({
  children,
  required = false,
  style,
  containerStyle,
  accessibilityRole = 'text',
  tone = 'light',
  nativeID,
  testID,
}: FieldLabelProps) {
  return (
    <View
      style={[styles.row, containerStyle]}
      accessible
      accessibilityRole={accessibilityRole}
      accessibilityLabel={requiredA11yLabel(children, required)}
      testID={testID}
    >
      <Text style={style} nativeID={nativeID}>
        {children}
      </Text>
      {required && <RequiredMark tone={tone} />}
    </View>
  );
}

/** The asterisk alone (hidden from screen readers — the label says "obvezno"). */
export function RequiredMark({ tone = 'light' }: { tone?: Tone }) {
  return (
    <Text
      style={[styles.mark, { color: tone === 'dark' ? dark.danger : light.danger }]}
      accessible={false}
      importantForAccessibility="no"
      aria-hidden
      testID="required-mark"
    >
      {REQUIRED_MARK}
    </Text>
  );
}

/** "* obvezno polje" — once per form that has required fields. */
export function RequiredLegend({ tone = 'light', style, testID = 'required-legend' }: { tone?: Tone; style?: StyleProp<TextStyle>; testID?: string }) {
  return (
    <Text
      style={[styles.legend, { color: tone === 'dark' ? dark.inkMuted : light.inkMuted }, style]}
      accessibilityLabel={t('common:required.legendA11y')}
      testID={testID}
    >
      {t('common:required.legend')}
    </Text>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'flex-start', flexWrap: 'wrap', gap: 3 },
  mark: { fontSize: 14, lineHeight: 18, fontWeight: '700' },
  legend: { fontSize: 12, lineHeight: 17 },
});
