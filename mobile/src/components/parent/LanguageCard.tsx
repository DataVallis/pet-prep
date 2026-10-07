/**
 * "Jezik / Language" section of the Nadzor tab (M1-18): the app language on this
 * device. Server texts (push notifications) follow it through `Accept-Language`.
 */

import { StyleSheet } from 'react-native';

import LanguageSwitch from '@/components/LanguageSwitch';
import { Card, SectionTitle, parentStyles } from '@/components/parent/ParentUi';
import { Text } from '@/components/ui/Text';
import { strings } from '@/i18n/strings';

export const LANGUAGE_STRINGS = strings('common', 'language');

export default function LanguageCard() {
  const S = LANGUAGE_STRINGS;
  return (
    <Card testID="language-card">
      <SectionTitle>{S.title}</SectionTitle>
      <LanguageSwitch style={styles.switch} testID="parent-language" />
      <Text style={parentStyles.muted}>{S.hint}</Text>
    </Card>
  );
}

const styles = StyleSheet.create({
  switch: { marginVertical: 8 },
});
