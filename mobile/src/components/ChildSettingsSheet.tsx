/**
 * Child HUD settings sheet (was the language sheet, David 2026-10-12; M5-T11 2026-10-21):
 * - **Jezik / Language** — the app language on this device (pushes follow through
 *   `usePushNotifications`, which re-registers the device in the new language);
 * - **Prikaz: Video / Slika** (APP_REFINEMENT_SPEC §1) — stored on the child's profile
 *   (`PATCH /api/child/settings`, a parent can set it too); `picture` shows the key image of
 *   the current state and downloads no video (the hint says so).
 * Opened from the gear button in the HUD header; same dark glass bottom sheet as
 * `PetProfileSheet` (RN `Modal`: Android back button and an accessibility-modal container).
 */

import { useContext } from 'react';
import { Modal, Pressable, StyleSheet, View } from 'react-native';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';
import { X } from 'lucide-react-native';

import LanguageSwitch from '@/components/LanguageSwitch';
import MediaDisplaySwitch from '@/components/MediaDisplaySwitch';
import { Text } from '@/components/ui/Text';
import { useSetMediaDisplay } from '@/hooks/queries/useChildSettings';
import type { MediaDisplay } from '@/modules/display/display';
import { t } from '@/i18n';
import { strings } from '@/i18n/strings';
import { MIN_TOUCH, alpha, dark, fonts, palette, radius, tightTracking } from '@/theme';

/** User-visible strings (`child:hud.settingsSheet`). */
export const CHILD_SETTINGS_STRINGS = strings('child', 'hud', {});

interface ChildSettingsSheetProps {
  visible: boolean;
  onClose: () => void;
  /** The child's display from the server state (`view.settings.media_display`). */
  mediaDisplay: MediaDisplay;
  testID?: string;
}

export default function ChildSettingsSheet({ visible, onClose, mediaDisplay, testID = 'hud-settings-sheet' }: ChildSettingsSheetProps) {
  const S = CHILD_SETTINGS_STRINGS.settingsSheet;
  const bottomInset = useContext(SafeAreaInsetsContext)?.bottom ?? 0;
  const setDisplay = useSetMediaDisplay();

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
              {S.title}
            </Text>
            <Pressable
              onPress={onClose}
              accessibilityRole="button"
              accessibilityLabel={S.close}
              style={({ pressed }) => [styles.close, pressed && styles.pressed]}
              testID={`${testID}-close`}
            >
              <X color={palette.white} size={20} />
            </Pressable>
          </View>

          <Text style={styles.section}>{S.language}</Text>
          <LanguageSwitch tone="dark" testID="child-language" />
          <Text style={styles.hint}>{t('common:language.hint')}</Text>

          <Text style={styles.section}>{S.display.label}</Text>
          <MediaDisplaySwitch
            value={mediaDisplay}
            onChange={(next) => setDisplay.mutate(next)}
            label={S.display.label}
            labels={{ video: S.display.video, picture: S.display.picture }}
            tone="dark"
            disabled={setDisplay.isPending}
            testID="child-media-display"
          />
          <Text style={styles.hint} testID="child-media-display-hint">
            {mediaDisplay === 'picture' ? S.display.hintPicture : S.display.hintVideo}
          </Text>
          {setDisplay.isError && (
            <Text style={styles.error} testID="child-media-display-error" accessibilityLiveRegion="polite">
              {S.display.error}
            </Text>
          )}
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
    gap: 12,
  },
  headerRow: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  title: { flex: 1, color: palette.white, fontSize: 20, letterSpacing: tightTracking(20), fontFamily: fonts.display },
  section: { color: palette.white, fontSize: 15, fontWeight: '700', marginTop: 4 },
  close: {
    width: MIN_TOUCH,
    height: MIN_TOUCH,
    borderRadius: MIN_TOUCH / 2,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: alpha(palette.white, 0.1),
  },
  hint: { color: palette.n400, fontSize: 13 },
  error: { color: palette.danger, fontSize: 13 },
  pressed: { opacity: 0.8 },
});
