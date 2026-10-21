/**
 * M5-T13 — the birthday card (APP_REFINEMENT_SPEC §9): »Vse najboljše, Luna!«, the age line,
 * the SAFE "cake" (a dog-/cat-safe treat — never chocolate) and one sourced tip. Dark glass
 * bottom sheet like `CareGuideSheet`; each block is one screen-reader element; close ≥ 44 pt.
 */

import { useContext } from 'react';
import { Modal, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';
import { useTranslation } from 'react-i18next';
import { CakeSlice, Lightbulb, X } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { t } from '@/i18n';
import { MIN_TOUCH, alpha, dark, fonts, palette, radius, tightTracking } from '@/theme';
import { birthdayAgeLine, birthdayCakeLine, birthdayTip, birthdayTitle } from './birthday';

interface Props {
  visible: boolean;
  petName: string | null;
  species: string | null;
  onClose: () => void;
  testID?: string;
}

export default function BirthdayCard({ visible, petName, species, onClose, testID = 'birthday-card' }: Props) {
  // Re-render on a language switch (the texts are read at render time).
  useTranslation();
  const bottomInset = useContext(SafeAreaInsetsContext)?.bottom ?? 0;
  const title = birthdayTitle(petName, species);
  const age = birthdayAgeLine(petName, species);
  const cake = birthdayCakeLine(species);
  const tip = birthdayTip(species);
  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose} statusBarTranslucent>
      <View style={styles.backdrop}>
        <Pressable style={StyleSheet.absoluteFill} onPress={onClose} accessible={false} importantForAccessibility="no" testID={`${testID}-backdrop`} />
        <View style={[styles.sheet, { paddingBottom: 20 + bottomInset }]} testID={testID} accessibilityViewIsModal>
          <View style={styles.headerRow}>
            <Text style={styles.title} accessibilityRole="header" testID={`${testID}-title`}>
              {title}
            </Text>
            <Pressable
              onPress={onClose}
              accessibilityRole="button"
              accessibilityLabel={t('birthday:close')}
              style={({ pressed }) => [styles.close, pressed && styles.pressed]}
              testID={`${testID}-close`}
            >
              <X color={palette.white} size={20} />
            </Pressable>
          </View>
          <ScrollView style={styles.list} contentContainerStyle={styles.listContent}>
            <Text style={styles.age} testID={`${testID}-age`}>
              {age}
            </Text>
            <View style={styles.row} accessible accessibilityLabel={`${t('birthday:cakeLabel')}. ${cake}`} testID={`${testID}-cake`}>
              <CakeSlice color={palette.mint} size={22} />
              <View style={styles.rowText}>
                <Text style={styles.label}>{t('birthday:cakeLabel')}</Text>
                <Text style={styles.value}>{cake}</Text>
              </View>
            </View>
            <View style={[styles.row, styles.tip]} accessible accessibilityLabel={`${t('birthday:tipLabel')}. ${tip.text} ${tip.source}`} testID={`${testID}-tip`}>
              <Lightbulb color={palette.mint} size={22} />
              <View style={styles.rowText}>
                <Text style={styles.label}>{t('birthday:tipLabel')}</Text>
                <Text style={styles.value}>{tip.text}</Text>
                <Text style={styles.source} testID={`${testID}-source`}>
                  {tip.source}
                </Text>
              </View>
            </View>
          </ScrollView>
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  backdrop: { flex: 1, justifyContent: 'flex-end', backgroundColor: alpha(palette.black, 0.5) },
  sheet: {
    maxHeight: '85%',
    paddingHorizontal: 20,
    paddingTop: 16,
    borderTopLeftRadius: radius.sheet,
    borderTopRightRadius: radius.sheet,
    backgroundColor: dark.glassStrong,
    borderWidth: 1,
    borderColor: dark.glassBorder,
    gap: 8,
  },
  headerRow: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  title: { flex: 1, color: palette.white, fontSize: 22, letterSpacing: tightTracking(22), fontFamily: fonts.display },
  close: {
    width: MIN_TOUCH,
    height: MIN_TOUCH,
    borderRadius: MIN_TOUCH / 2,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: alpha(palette.white, 0.1),
  },
  list: { flexGrow: 0 },
  listContent: { gap: 14, paddingVertical: 4 },
  age: { color: palette.white, fontSize: 17, lineHeight: 24, fontWeight: '600' },
  row: { flexDirection: 'row', gap: 12, alignItems: 'flex-start' },
  rowText: { flex: 1, gap: 4 },
  tip: { borderLeftWidth: 3, borderLeftColor: palette.mint, paddingLeft: 10 },
  label: { color: palette.n400, fontSize: 12, fontWeight: '700', textTransform: 'uppercase', letterSpacing: 0.4 },
  value: { color: palette.white, fontSize: 16, lineHeight: 22, fontWeight: '500' },
  source: { color: palette.n400, fontSize: 13, lineHeight: 18 },
  pressed: { opacity: 0.8 },
});
