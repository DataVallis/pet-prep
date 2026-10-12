/**
 * Child HUD language sheet (David 2026-10-12): the child can change the app language after
 * signing in, not only on the start screen. Opened from the globe button in the HUD header;
 * same dark glass bottom sheet as `PetProfileSheet` (RN `Modal`: Android back button and an
 * accessibility-modal container). The switch saves the language on this device; pushes follow
 * through `usePushNotifications` (re-registers the device in the new language).
 */

import { useContext } from 'react';
import { Modal, Pressable, StyleSheet, View } from 'react-native';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';
import { X } from 'lucide-react-native';

import LanguageSwitch from '@/components/LanguageSwitch';
import { Text } from '@/components/ui/Text';
import { t } from '@/i18n';
import { MIN_TOUCH, alpha, dark, fonts, palette, radius, tightTracking } from '@/theme';

interface ChildLanguageSheetProps {
  visible: boolean;
  onClose: () => void;
  testID?: string;
}

export default function ChildLanguageSheet({ visible, onClose, testID = 'hud-language-sheet' }: ChildLanguageSheetProps) {
  const bottomInset = useContext(SafeAreaInsetsContext)?.bottom ?? 0;
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
        <View style={[styles.sheet, { paddingBottom: 24 + bottomInset }]} testID={testID} accessibilityViewIsModal>
          <View style={styles.headerRow}>
            <Text style={styles.title} accessibilityRole="header">
              {t('child:hud.languageSheet.title')}
            </Text>
            <Pressable
              onPress={onClose}
              accessibilityRole="button"
              accessibilityLabel={t('child:hud.languageSheet.close')}
              style={({ pressed }) => [styles.close, pressed && styles.pressed]}
              testID={`${testID}-close`}
            >
              <X color={palette.white} size={20} />
            </Pressable>
          </View>
          <LanguageSwitch tone="dark" testID="child-language" />
          <Text style={styles.hint}>{t('common:language.hint')}</Text>
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  backdrop: { flex: 1, justifyContent: 'flex-end', backgroundColor: alpha(palette.black, 0.5) },
  sheet: {
    paddingHorizontal: 20,
    paddingTop: 16,
    borderTopLeftRadius: radius.sheet,
    borderTopRightRadius: radius.sheet,
    backgroundColor: dark.glassStrong,
    borderWidth: 1,
    borderColor: dark.glassBorder,
    gap: 14,
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
  hint: { color: palette.n400, fontSize: 13 },
  pressed: { opacity: 0.8 },
});
