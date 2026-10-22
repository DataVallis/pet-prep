/**
 * Terms of use + privacy policy consent (M2-10a): checkbox and the sentence with both links
 * (petprep.si). Shared by the e-mail sign-up and the Google / Apple sign-up sheet (M2-10c),
 * so both ask for exactly the same consent. Texts from `auth:signup`.
 */

import { Linking, Pressable, StyleSheet, View } from 'react-native';
import { Check } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { privacyUrl, SIGNUP_STRINGS as S, termsUrl } from '@/modules/auth/signup';
import { light, palette } from '@/theme';

interface TermsCheckboxProps {
  checked: boolean;
  onToggle: () => void;
  disabled?: boolean;
  /** Red border (the parent tried to continue without it). */
  invalid?: boolean;
  testID: string;
}

function openLink(url: string): void {
  void Linking.openURL(url).catch(() => undefined);
}

export default function TermsCheckbox({ checked, onToggle, disabled = false, invalid = false, testID }: TermsCheckboxProps) {
  return (
    <View style={styles.row}>
      <Pressable
        onPress={onToggle}
        hitSlop={8}
        accessibilityRole="checkbox"
        accessibilityState={{ checked, disabled }}
        accessibilityLabel={`${S.termsPrefix}${S.termsLink}${S.termsMiddle}${S.privacyLink}`}
        style={[styles.checkbox, checked && styles.checkboxChecked, invalid && styles.invalid]}
        disabled={disabled}
        testID={testID}
      >
        {checked && <Check color={palette.white} size={16} />}
      </Pressable>
      <Text style={styles.text}>
        {S.termsPrefix}
        <Text style={styles.link} onPress={() => openLink(termsUrl())} accessibilityRole="link">
          {S.termsLink}
        </Text>
        {S.termsMiddle}
        <Text style={styles.link} onPress={() => openLink(privacyUrl())} accessibilityRole="link">
          {S.privacyLink}
        </Text>
        {S.termsSuffix}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'flex-start', gap: 10, marginTop: 16 },
  checkbox: {
    width: 24,
    height: 24,
    borderRadius: 7,
    borderWidth: 1.5,
    borderColor: palette.n400,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 1,
  },
  checkboxChecked: { backgroundColor: light.action, borderColor: light.action },
  invalid: { borderColor: light.danger },
  text: { flex: 1, fontSize: 13, lineHeight: 19, color: light.inkMuted },
  link: { color: light.mintText, textDecorationLine: 'underline' },
});
