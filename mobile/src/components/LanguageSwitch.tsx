/**
 * Compact language switch (M1-18): one pill per supported language, each named in its
 * own language ("English", "Slovenščina"), so it can be read whatever is selected.
 * Light tone on the start screen and in the parent app. The choice is saved on the
 * device (`setLanguage`); the app tree re-renders through `useTranslation`.
 */

import { Pressable, StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native';
import { useTranslation } from 'react-i18next';

import { Text } from '@/components/ui/Text';
import { LANGUAGE_NAMES, SUPPORTED_LANGUAGES, currentLanguage, setLanguage, t, type Language } from '@/i18n';
import { light, palette, radius } from '@/theme';

interface LanguageSwitchProps {
  style?: StyleProp<ViewStyle>;
  /** Short codes ("EN", "SL") instead of full names — for tight headers. */
  compact?: boolean;
  testID?: string;
}

export default function LanguageSwitch({ style, compact = false, testID = 'language-switch' }: LanguageSwitchProps) {
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
            style={({ pressed }) => [styles.pill, selected && styles.pillActive, pressed && styles.pressed]}
            accessibilityRole="radio"
            accessibilityState={{ selected }}
            accessibilityLabel={LANGUAGE_NAMES[language]}
            accessibilityLanguage={language}
            testID={`${testID}-${language}`}
          >
            <Text style={[styles.text, selected && styles.textActive]}>
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
  pressed: { opacity: 0.8 },
  text: { fontSize: 13, fontWeight: '600', color: light.ink },
  textActive: { color: palette.white },
});
