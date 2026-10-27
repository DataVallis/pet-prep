/**
 * Child »Želje« sheet (M6-W04, SHOP_SPEC §3.1): the pet's catalogue with a picture and a
 * name per item — **never a price** (S4). »Prosi starša« sends a wish; rows follow
 * `childRowState` (requested / owned / on the way / maybe another time / open limit / treats
 * left). Opened from the »Želje« row of the HUD settings sheet (not a care action, so it
 * stays out of the dock and the top stack). Dark glass bottom sheet like `ChildSettingsSheet`.
 */

import { useContext, useState } from 'react';
import { ActivityIndicator, Modal, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';
import { Check, X } from 'lucide-react-native';
import { useTranslation } from 'react-i18next';

import { Text } from '@/components/ui/Text';
import { t } from '@/i18n';
import { useChildWishes, useCreateWish } from '@/hooks/queries/useWishes';
import { classifyActionError } from '@/modules/childPet/actionMessages';
import { MIN_TOUCH, alpha, dark, fonts, palette, radius, tightTracking } from '@/theme';
import WishIcon from '@/modules/wishes/WishIcon';
import { childRowState, errorReason, isFeatureMissing, type ChildRowState, type ChildWishItem, type WishItemKey } from '@/modules/wishes/wishes';

export interface WishesSheetProps {
  visible: boolean;
  onClose: () => void;
  /** Device clock + server skew (ms) — decides when »Mogoče kdaj drugič« ends (`retry_at`). */
  nowMs: () => number;
}

type Feedback = { kind: 'sent' } | { kind: 'error'; key: string };

/** Text of a refused wish (422 / 423 reason) — never anything about money. */
export function wishErrorText(error: unknown): string {
  const reason = errorReason(error);
  switch (reason) {
    case 'already_owned':
    case 'already_requested':
    case 'declined_recently':
    case 'wish_limit_reached':
    case 'treats_still_left':
      return t(`wishes:child.errors.${reason}`);
    case 'game_over':
    case 'payment_required':
    case 'pet_not_started':
      return t('wishes:child.errors.blocked');
  }
  const failure = classifyActionError(error);
  return failure.kind === 'offline' ? t('wishes:child.errors.offline') : t('wishes:child.errors.failed');
}

export default function WishesSheet({ visible, onClose, nowMs }: WishesSheetProps) {
  useTranslation();
  const bottomInset = useContext(SafeAreaInsetsContext)?.bottom ?? 0;
  const query = useChildWishes(visible);
  const create = useCreateWish();
  const [feedback, setFeedback] = useState<Feedback | null>(null);
  const [errorText, setErrorText] = useState<string | null>(null);
  const data = query.data;
  const now = nowMs();

  const ask = (key: WishItemKey) => {
    if (create.isPending) return;
    setFeedback(null);
    create.mutate(key, {
      onSuccess: () => setFeedback({ kind: 'sent' }),
      onError: (error) => {
        setErrorText(wishErrorText(error));
        setFeedback({ kind: 'error', key });
      },
    });
  };

  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose} statusBarTranslucent>
      <View style={styles.backdrop}>
        <Pressable style={StyleSheet.absoluteFill} onPress={onClose} accessible={false} importantForAccessibility="no" testID="wishes-sheet-backdrop" />
        <View style={[styles.sheet, { paddingBottom: 24 + bottomInset }]} testID="wishes-sheet" accessibilityViewIsModal>
          <View style={styles.headerRow}>
            <Text style={styles.title} accessibilityRole="header">
              {t('wishes:child.title')}
            </Text>
            <Pressable
              onPress={onClose}
              accessibilityRole="button"
              accessibilityLabel={t('wishes:child.close')}
              style={({ pressed }) => [styles.close, pressed && styles.pressed]}
              testID="wishes-sheet-close"
            >
              <X color={palette.white} size={20} />
            </Pressable>
          </View>
          <Text style={styles.hint}>{t('wishes:child.intro')}</Text>

          {feedback && (
            <Text style={[styles.feedback, feedback.kind === 'error' && styles.feedbackInfo]} accessibilityLiveRegion="polite" testID="wishes-feedback">
              {feedback.kind === 'sent' ? t('wishes:child.sent') : errorText}
            </Text>
          )}

          {query.isPending ? (
            <View style={styles.center}>
              <ActivityIndicator color={palette.mint} />
              <Text style={styles.hint}>{t('wishes:child.loading')}</Text>
            </View>
          ) : query.isError || !data ? (
            <View style={styles.center} testID="wishes-error">
              <Text style={styles.hint}>{isFeatureMissing(query.error) ? t('wishes:child.unavailable') : t('wishes:child.loadError')}</Text>
              {!isFeatureMissing(query.error) && (
                <Pressable onPress={() => void query.refetch()} accessibilityRole="button" style={({ pressed }) => [styles.secondary, pressed && styles.pressed]}>
                  <Text style={styles.secondaryText}>{t('wishes:child.retry')}</Text>
                </Pressable>
              )}
            </View>
          ) : (
            <>
              {!data.can_wish ? (
                <Text style={styles.note} testID="wishes-blocked">
                  {t('wishes:child.blocked')}
                </Text>
              ) : data.open_count >= data.open_limit ? (
                <Text style={styles.note} testID="wishes-limit">
                  {t('wishes:child.limit', { count: data.open_limit })}
                </Text>
              ) : null}
              <ScrollView style={styles.list} contentContainerStyle={styles.listContent}>
                {data.items.map((item) => (
                  <WishRow key={item.key} item={item} state={childRowState(item, data, now)} busy={create.isPending && create.variables === item.key} onAsk={() => ask(item.key)} />
                ))}
              </ScrollView>
            </>
          )}
        </View>
      </View>
    </Modal>
  );
}

function statusText(state: ChildRowState, item: ChildWishItem): string | null {
  switch (state) {
    case 'requested':
      return t('wishes:child.requested');
    case 'owned':
      return t('wishes:child.owned');
    case 'on_the_way':
      return t('wishes:child.onTheWay');
    case 'declined':
      return t('wishes:child.declined');
    case 'treats_left':
      return t('wishes:child.treatsLeft', { count: item.treats_left ?? 0 });
    default:
      return null;
  }
}

function WishRow({ item, state, busy, onAsk }: { item: ChildWishItem; state: ChildRowState; busy: boolean; onAsk: () => void }) {
  const name = t(`wishes:items.${item.variant}`);
  const status = statusText(state, item);
  const showButton = state === 'ask' || state === 'limit';
  const disabled = state !== 'ask' || busy;
  const treatsNote = item.key === 'treats' && state !== 'treats_left' && (item.treats_left ?? 0) > 0 ? t('wishes:child.treatsLeft', { count: item.treats_left ?? 0 }) : null;
  return (
    <View style={styles.row} testID={`wish-row-${item.key}`}>
      <WishIcon variant={item.variant} tone="dark" size={52} />
      <View style={styles.rowTexts}>
        <Text style={styles.rowName}>{name}</Text>
        {treatsNote && <Text style={styles.rowStatus}>{treatsNote}</Text>}
        {status && !showButton && (
          <View style={styles.statusLine}>
            {(state === 'requested' || state === 'owned') && <Check color={palette.mint} size={14} />}
            <Text style={styles.rowStatus} testID={`wish-status-${item.key}`}>
              {status}
            </Text>
          </View>
        )}
      </View>
      {showButton && (
        <Pressable
          onPress={onAsk}
          disabled={disabled}
          accessibilityRole="button"
          accessibilityLabel={t('wishes:child.askA11y', { item: name })}
          accessibilityState={{ disabled, busy }}
          style={({ pressed }) => [styles.ask, disabled && styles.disabled, pressed && styles.pressed]}
          testID={`wish-ask-${item.key}`}
        >
          {busy ? <ActivityIndicator color={palette.graphite} /> : <Text style={styles.askText}>{t('wishes:child.ask')}</Text>}
        </Pressable>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  backdrop: { flex: 1, justifyContent: 'flex-end', backgroundColor: alpha(palette.black, 0.5) },
  sheet: {
    maxHeight: '88%',
    paddingHorizontal: 20,
    paddingTop: 16,
    borderTopLeftRadius: radius.sheet,
    borderTopRightRadius: radius.sheet,
    backgroundColor: dark.glassStrong,
    borderWidth: 1,
    borderColor: dark.glassBorder,
    gap: 10,
  },
  headerRow: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  title: { flex: 1, color: palette.white, fontSize: 20, letterSpacing: tightTracking(20), fontFamily: fonts.display },
  close: { width: MIN_TOUCH, height: MIN_TOUCH, borderRadius: MIN_TOUCH / 2, alignItems: 'center', justifyContent: 'center', backgroundColor: alpha(palette.white, 0.1) },
  hint: { color: palette.n400, fontSize: 13 },
  note: { color: palette.warnDark, fontSize: 13, fontWeight: '600' },
  feedback: { color: palette.okDark, fontSize: 14, fontWeight: '700' },
  feedbackInfo: { color: palette.n300 },
  center: { alignItems: 'center', gap: 8, paddingVertical: 20 },
  list: { flexGrow: 0 },
  listContent: { gap: 10, paddingBottom: 8 },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    padding: 12,
    borderRadius: radius.card,
    borderWidth: 1,
    borderColor: dark.glassBorder,
    backgroundColor: alpha(palette.white, 0.06),
  },
  rowTexts: { flex: 1, gap: 4 },
  rowName: { color: palette.white, fontSize: 15, fontWeight: '700' },
  statusLine: { flexDirection: 'row', alignItems: 'center', gap: 4 },
  rowStatus: { color: palette.n300, fontSize: 13, fontWeight: '600' },
  ask: { minHeight: MIN_TOUCH, paddingHorizontal: 14, borderRadius: 22, backgroundColor: palette.mint, alignItems: 'center', justifyContent: 'center' },
  askText: { color: palette.graphite, fontSize: 14, fontWeight: '800' },
  secondary: { minHeight: MIN_TOUCH, paddingHorizontal: 16, borderRadius: radius.button, borderWidth: 1, borderColor: dark.glassBorder, justifyContent: 'center' },
  secondaryText: { color: palette.mint, fontSize: 14, fontWeight: '700' },
  disabled: { opacity: 0.45 },
  pressed: { opacity: 0.75 },
});
