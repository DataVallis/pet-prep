/**
 * Compact language switch (M1-18): one pill per supported language, each named in its
 * own language ("English", "Slovenščina"), so it can be read whatever is selected.
 * Light tone on the start screen and in the parent app, dark tone in the child HUD. The choice is saved on the
 * device (`setLanguage`); the app tree re-renders through `useTranslation`.
 */

import { Pressable, StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native';
import { useTranslation } from 'react-i18next';

import { Text } from '@/components/ui/Text';
import { LANGUAGE_NAMES, SUPPORTED_LANGUAGES, currentLanguage, setLanguage, t, type Language } from '@/i18n';
import { MIN_TOUCH, alpha, light, palette, radius } from '@/theme';

interface LanguageSwitchProps {
  style?: StyleProp<ViewStyle>;
  /** Short codes ("EN", "SL") instead of full names — for tight headers. */
  compact?: boolean;
  /** `dark` for the child HUD's dark glass (mint = selected); `light` elsewhere. */
  tone?: 'light' | 'dark';
  testID?: string;
}

export default function LanguageSwitch({ style, compact = false, tone = 'light', testID = 'language-switch' }: LanguageSwitchProps) {
  const dark = tone === 'dark';
  useTranslation(); // re-render when the language changes
  const active = currentLanguage();

  const choose = (language: Language) => {
    if (language !== active) void setLanguage(language);
  };

  return (
    <View style={[styles.row, style]} accessibilityRole="radiogroup" accessibilityLabel={t('common:language.label')} testID={testID}>
      {SUPPORTED_LANGUAGES.map((language) => {
        const selected = language === active;
        return (
          <Pressable
            key={language}
            onPress={() => choose(language)}
            hitSlop={6}
            style={({ pressed }) => [
              styles.pill,
              dark && styles.pillDark,
              selected && (dark ? styles.pillDarkActive : styles.pillActive),
              pressed && styles.pressed,
            ]}
            accessibilityRole="radio"
            accessibilityState={{ selected }}
            accessibilityLabel={LANGUAGE_NAMES[language]}
            accessibilityLanguage={language}
            testID={`${testID}-${language}`}
          >
            <Text style={[styles.text, dark && styles.textDark, selected && (dark ? styles.textDarkActive : styles.textActive)]}>
              {compact ? language.toUpperCase() : LANGUAGE_NAMES[language]}
            </Text>
          </Pressable>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', gap: 6, flexWrap: 'wrap' },
  pill: {
    minHeight: 32,
    paddingHorizontal: 12,
    borderRadius: radius.pill,
    borderWidth: 1,
    borderColor: light.border,
    backgroundColor: light.surface,
    alignItems: 'center',
    justifyContent: 'center',
  },
  pillActive: { backgroundColor: palette.graphite, borderColor: palette.graphite },
  pillDark: { minHeight: MIN_TOUCH, paddingHorizontal: 18, backgroundColor: alpha(palette.white, 0.08), borderColor: alpha(palette.white, 0.2) },
  pillDarkActive: { backgroundColor: palette.mint, borderColor: palette.mint },
  pressed: { opacity: 0.8 },
  text: { fontSize: 13, fontWeight: '600', color: light.ink },
  textActive: { color: palette.white },
  textDark: { fontSize: 15, color: palette.white },
  textDarkActive: { color: palette.graphite },
});
