/**
 * M5-T05 — the care guide card »Kako skrbim za …« / "How I care for …" (APP_REFINEMENT_SPEC §5).
 * Dark glass bottom sheet like `PetProfileSheet`; one block per rule in reading order (each
 * block is one screen-reader element: label + sentences), scrolls with large fonts, close ≥ 44 pt.
 * Every number comes from the server's `care_guide` (`careGuide.ts`). M5-T05b: the parent's child /
 * pet detail opens the same sheet with `audience="parent"` (the parent's wording).
 */

import { useContext } from 'react';
import { Modal, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';
import { useTranslation } from 'react-i18next';
import { X } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { t } from '@/i18n';
import { MIN_TOUCH, alpha, dark, fonts, palette, radius, tightTracking } from '@/theme';
import { careGuideSections, careGuideTitle, type CareGuide, type CareGuideAudience } from './careGuide';

interface CareGuideSheetProps {
  visible: boolean;
  guide: CareGuide;
  petName: string | null;
  onClose: () => void;
  /** M5-T05b: `parent` = the parent's wording (child / pet detail); same numbers and sections. */
  audience?: CareGuideAudience;
  testID?: string;
}

export default function CareGuideSheet({ visible, guide, petName, onClose, audience = 'child', testID = 'care-guide' }: CareGuideSheetProps) {
  // Re-render on a language switch (the texts are read at render time).
  useTranslation();
  const bottomInset = useContext(SafeAreaInsetsContext)?.bottom ?? 0;
  const sections = careGuideSections(guide, audience);
  const title = careGuideTitle(guide.species, petName, audience);
  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose} statusBarTranslucent>
      <View style={styles.backdrop}>
        <Pressable
          style={StyleSheet.absoluteFill}
          onPress={onClose}
          accessible={false}
          importantForAccessibility="no"
          testID={`${testID}-backdrop`}
        />
        <View style={[styles.sheet, { paddingBottom: 20 + bottomInset }]} testID={testID} accessibilityViewIsModal>
          <View style={styles.headerRow}>
            <Text style={styles.title} accessibilityRole="header" testID={`${testID}-title`}>
              {title}
            </Text>
            <Pressable
              onPress={onClose}
              accessibilityRole="button"
              accessibilityLabel={t('careGuide:close')}
              style={({ pressed }) => [styles.close, pressed && styles.pressed]}
              testID={`${testID}-close`}
            >
              <X color={palette.white} size={20} />
            </Pressable>
          </View>
          <ScrollView style={styles.list} contentContainerStyle={styles.listContent}>
            {sections.map((section) => (
              <View
                key={section.key}
                style={[styles.row, section.key === 'difference' && styles.highlight]}
                testID={`${testID}-${section.key}`}
                accessible
                accessibilityLabel={`${section.label}. ${section.lines.join(' ')}`}
              >
                <Text style={styles.label}>{section.label}</Text>
                {section.lines.map((line) => (
                  <Text key={line} style={styles.value}>
                    {line}
                  </Text>
                ))}
              </View>
            ))}
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
  title: { flex: 1, color: palette.white, fontSize: 20, letterSpacing: tightTracking(20), fontFamily: fonts.display },
  close: {
    width: MIN_TOUCH,
    height: MIN_TOUCH,
    borderRadius: MIN_TOUCH / 2,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: alpha(palette.white, 0.1),
  },
  list: { flexGrow: 0 },
  listContent: { gap: 12, paddingVertical: 4 },
  row: { gap: 4, paddingBottom: 10, borderBottomWidth: 1, borderBottomColor: alpha(palette.white, 0.08) },
  // The one rule children mix up (David 2026-10-20): a mint edge, no extra colour.
  highlight: { borderLeftWidth: 3, borderLeftColor: palette.mint, paddingLeft: 10, borderBottomWidth: 0 },
  label: { color: palette.n400, fontSize: 12, fontWeight: '700', textTransform: 'uppercase', letterSpacing: 0.4 },
  value: { color: palette.white, fontSize: 16, lineHeight: 22, fontWeight: '500' },
  pressed: { opacity: 0.8 },
});
