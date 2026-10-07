/**
 * M5-F05 — bottom sheet with the pup's full profile, opened by tapping the child HUD header
 * (the header itself shows one short line). Dark glass like the rest of the HUD; rows wrap
 * freely (no `numberOfLines`), the list scrolls when large fonts make it taller than ~70 %
 * of the screen, and the close button is ≥ 44 pt. RN `Modal` gives the Android back button
 * and an accessibility-modal container for free.
 */

import { useContext } from 'react';
import { Modal, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';
import { X } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { t } from '@/i18n';
import type { ProfileSheetRow } from '@/modules/petProfile/profileSheet';
import { MIN_TOUCH, alpha, dark, fonts, palette, radius, tightTracking } from '@/theme';

interface PetProfileSheetProps {
  visible: boolean;
  rows: readonly ProfileSheetRow[];
  onClose: () => void;
  testID?: string;
}

export default function PetProfileSheet({ visible, rows, onClose, testID = 'hud-profile-sheet' }: PetProfileSheetProps) {
  // Home indicator / gesture bar: the last row stays above it (no provider in tests → 0).
  const bottomInset = useContext(SafeAreaInsetsContext)?.bottom ?? 0;
  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose} statusBarTranslucent>
      <View style={styles.backdrop}>
        {/* Tap outside the sheet closes it (hidden from screen readers — they use "Close"). */}
        <Pressable
          style={StyleSheet.absoluteFill}
          onPress={onClose}
          accessible={false}
          importantForAccessibility="no"
          testID={`${testID}-backdrop`}
        />
        <View style={[styles.sheet, { paddingBottom: 20 + bottomInset }]} testID={testID} accessibilityViewIsModal>
          <View style={styles.headerRow}>
            <Text style={styles.title} accessibilityRole="header">
              {t('child:hud.profileSheet.title')}
            </Text>
            <Pressable
              onPress={onClose}
              accessibilityRole="button"
              accessibilityLabel={t('child:hud.profileSheet.close')}
              style={({ pressed }) => [styles.close, pressed && styles.pressed]}
              testID={`${testID}-close`}
            >
              <X color={palette.white} size={20} />
            </Pressable>
          </View>
          <ScrollView style={styles.list} contentContainerStyle={styles.listContent}>
            {rows.map((row) => (
              <View key={row.key} style={styles.row} testID={`${testID}-${row.key}`} accessible accessibilityLabel={`${row.label}: ${row.value}`}>
                <Text style={styles.label}>{row.label}</Text>
                <Text style={styles.value}>{row.value}</Text>
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
    maxHeight: '75%',
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
  row: { gap: 2, paddingBottom: 10, borderBottomWidth: 1, borderBottomColor: alpha(palette.white, 0.08) },
  label: { color: palette.n400, fontSize: 12, fontWeight: '700', textTransform: 'uppercase', letterSpacing: 0.4 },
  value: { color: palette.white, fontSize: 16, lineHeight: 22, fontWeight: '600' },
  pressed: { opacity: 0.8 },
});
