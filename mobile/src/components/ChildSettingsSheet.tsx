/**
 * Child HUD settings sheet (was the language sheet, David 2026-10-12; M5-T11 2026-10-21):
 * - **Jezik / Language** — the app language on this device (pushes follow through
 *   `usePushNotifications`, which re-registers the device in the new language);
 * - **Prikaz: Video / Slika** (APP_REFINEMENT_SPEC §1) — stored on the child's profile
 *   (`PATCH /api/child/settings`, a parent can set it too); `picture` shows the key image of
 *   the current state and downloads no video (the hint says so).
 * - rows that used to be icons in the HUD top bar (David 2026-10-23, the bar truncated the
 *   name / stage / breed): **Album**, **Kako skrbim** (the M5-T05 care guide) and, last, the
 *   exit (**Odjava**, or »Nazaj k staršu« in a shared »Igraj kot« session).
 * - **Želje** (M6-W04): the wish catalogue (`WishesSheet`) — not a care action, so it lives
 *   here and not in the dock / top stack.
 * A row closes the sheet first. The album is an in-screen layer and opens at once; the care
 * guide and the exit open a `Modal` (care guide sheet, parental gate), so they run once this
 * sheet is gone (iOS `onDismiss`, else after the slide-out) — iOS can't present a modal while
 * another one is still dismissing.
 * Opened from the gear button in the HUD header; same dark glass bottom sheet as
 * `PetProfileSheet` (RN `Modal`: Android back button and an accessibility-modal container).
 */

import { useCallback, useContext, useEffect, useRef, type ReactNode } from 'react';
import { Modal, Pressable, StyleSheet, View } from 'react-native';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';
import { ChevronRight, CircleQuestionMark, Gift, Images, LogOut, X } from 'lucide-react-native';

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
  /** »Album« row — omitted when the pet has no album media yet. */
  album?: { label: string; onOpen: () => void };
  /** »Kako skrbim« row (care guide) — omitted on an older server without `care_guide`. */
  careGuide?: { label: string; hint: string; onOpen: () => void };
  /** »Želje« row (M6-W04) — omitted on an older server without the `wishes` block. Opens a Modal. */
  wishes?: { label: string; hint: string; onOpen: () => void };
  /** The exit row at the bottom (»Odjava« / »Nazaj k staršu«). */
  exit?: { label: string; onPress: () => void };
  testID?: string;
}

/** Fallback when the platform reports no dismissal (Android; iOS `onDismiss` is faster). */
export const AFTER_CLOSE_MS = 350;

export default function ChildSettingsSheet({
  visible,
  onClose,
  mediaDisplay,
  album,
  careGuide,
  wishes,
  exit,
  testID = 'hud-settings-sheet',
}: ChildSettingsSheetProps) {
  const S = CHILD_SETTINGS_STRINGS.settingsSheet;
  const bottomInset = useContext(SafeAreaInsetsContext)?.bottom ?? 0;
  const setDisplay = useSetMediaDisplay();

  // A row's action waits until this sheet is dismissed: iOS can't present a modal while another one closes.
  const pending = useRef<(() => void) | null>(null);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const runPending = useCallback(() => {
    if (timer.current) clearTimeout(timer.current);
    timer.current = null;
    const action = pending.current;
    pending.current = null;
    action?.();
  }, []);
  useEffect(
    () => () => {
      if (timer.current) clearTimeout(timer.current);
    },
    [],
  );
  const closeThen = (action: () => void) => {
    pending.current = action;
    onClose();
    if (timer.current) clearTimeout(timer.current);
    timer.current = setTimeout(runPending, AFTER_CLOSE_MS);
  };

  return (
    <Modal
      visible={visible}
      transparent
      animationType="slide"
      onRequestClose={onClose}
      onDismiss={runPending}
      statusBarTranslucent
    >
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

          {(album || careGuide || wishes) && (
            <View style={styles.rows}>
              {album && (
                <SettingsRow
                  icon={<Images color={palette.mint} size={18} />}
                  label={album.label}
                  onPress={() => {
                    onClose();
                    album.onOpen();
                  }}
                  testID="hud-album-open"
                />
              )}
              {careGuide && (
                <SettingsRow
                  icon={<CircleQuestionMark color={palette.mint} size={18} />}
                  label={careGuide.label}
                  hint={careGuide.hint}
                  onPress={() => closeThen(careGuide.onOpen)}
                  testID="hud-care-guide-open"
                />
              )}
              {wishes && (
                <SettingsRow
                  icon={<Gift color={palette.mint} size={18} />}
                  label={wishes.label}
                  hint={wishes.hint}
                  onPress={() => closeThen(wishes.onOpen)}
                  testID="hud-wishes-open"
                />
              )}
            </View>
          )}

          {exit && (
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={exit.label}
              onPress={() => closeThen(exit.onPress)}
              style={({ pressed }) => [styles.exit, pressed && styles.pressed]}
              testID="hud-logout"
            >
              <LogOut color={palette.n400} size={16} />
              <Text style={styles.exitText}>{exit.label}</Text>
            </Pressable>
          )}
        </View>
      </View>
    </Modal>
  );
}

function SettingsRow({ icon, label, hint, onPress, testID }: { icon: ReactNode; label: string; hint?: string; onPress: () => void; testID: string }) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityHint={hint}
      onPress={onPress}
      style={({ pressed }) => [styles.row, pressed && styles.pressed]}
      testID={testID}
    >
      <View style={styles.rowIcon}>{icon}</View>
      <Text style={styles.rowLabel} numberOfLines={1}>
        {label}
      </Text>
      <ChevronRight color={alpha(palette.white, 0.5)} size={18} />
    </Pressable>
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
  rows: {
    marginTop: 4,
    borderRadius: radius.card,
    borderWidth: 1,
    borderColor: dark.glassBorder,
    backgroundColor: alpha(palette.white, 0.06),
    overflow: 'hidden',
  },
  row: { minHeight: MIN_TOUCH + 8, flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 14 },
  rowIcon: {
    width: 32,
    height: 32,
    borderRadius: 16,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: alpha(palette.mint, 0.12),
  },
  rowLabel: { flex: 1, color: palette.white, fontSize: 15, fontWeight: '600' },
  // Secondary, quiet exit at the bottom (brand: danger red is for real errors, not for signing out).
  exit: {
    minHeight: MIN_TOUCH,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    marginTop: 4,
    borderRadius: radius.pill,
    borderWidth: 1,
    borderColor: dark.glassBorder,
  },
  exitText: { color: palette.n400, fontSize: 15, fontWeight: '600' },
  pressed: { opacity: 0.8 },
});
