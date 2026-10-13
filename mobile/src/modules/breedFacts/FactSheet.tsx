/**
 * M5-R09c »Ali si vedel?« card (DID_YOU_KNOW_SPEC §3.3): a bottom sheet over the HUD (radius 30,
 * dimmed backdrop — the pet video still shows behind it). Round picture of the child's own pet
 * (the current reference image from the AI media, no new AI call) with a mint ring, eyebrow
 * »ALI SI VEDEL?«, »Pasma · kategorija«, the fact as the title, the »why« line, the source as
 * plain text (never a link for the child), »8 / 40« with a thin mint bar, »Super!« (primary)
 * and »Več o moji pasmi« (outline). Closes with »Super!«, the backdrop, Android back or a swipe
 * down. Calm: no sound, no confetti; with Reduce Motion the sheet fades instead of sliding.
 * Accessibility: `role=dialog` labelled by the fact; VoiceOver reads fact + why + source.
 */

import { useContext, useRef } from 'react';
import { Animated, Image, Modal, PanResponder, Pressable, StyleSheet, View } from 'react-native';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';
import { PawPrint } from 'lucide-react-native';

import type { BreedFact } from '@/api/client';
import { Text } from '@/components/ui/Text';
import { t } from '@/i18n';
import { useStableUrl } from '@/modules/petMedia/hooks';
import { MIN_TOUCH, alpha, dark, fonts, palette, radius, tightTracking } from '@/theme';

/** Drag distance (pt) or speed that closes the sheet. */
export const SWIPE_CLOSE_DY = 80;
export const SWIPE_CLOSE_VY = 0.8;

export interface FactSheetProps {
  fact: BreedFact | null;
  breedLabel: string;
  imageUrl: string | null;
  readCount: number;
  totalCount: number;
  reduceMotion: boolean;
  onClose: () => void;
  onMore: () => void;
  testID?: string;
}

/** Spoken text of the card (fact, why, source) — what VoiceOver reads first. */
export function factA11yText(fact: Pick<BreedFact, 'title' | 'why' | 'source_name'>): string {
  return fact.why
    ? t('facts:child.dialogRead', { title: fact.title, why: fact.why, source: fact.source_name })
    : t('facts:child.dialogReadNoWhy', { title: fact.title, source: fact.source_name });
}

export function categoryLabel(category: BreedFact['category']): string {
  return t(`facts:categories.${category}`);
}

/** Round pet picture with the mint ring; a paw while there is no picture / it fails. */
export function PetAvatar({ url, size, testID }: { url: string | null; size: number; testID?: string }) {
  const { uri, onError } = useStableUrl(url);
  const ring = { width: size + 8, height: size + 8, borderRadius: (size + 8) / 2 };
  const inner = { width: size, height: size, borderRadius: size / 2 };
  return (
    <View style={[styles.ring, ring]} testID={testID} accessibilityLabel={t('facts:child.petImage')} accessibilityRole="image">
      {uri ? (
        <Image source={{ uri }} onError={onError} style={inner} resizeMode="cover" testID={testID ? `${testID}-image` : undefined} />
      ) : (
        <View style={[inner, styles.avatarFallback]}>
          <PawPrint color={palette.mint} size={size * 0.4} />
        </View>
      )}
    </View>
  );
}

export default function FactSheet({
  fact,
  breedLabel,
  imageUrl,
  readCount,
  totalCount,
  reduceMotion,
  onClose,
  onMore,
  testID = 'fact-sheet',
}: FactSheetProps) {
  const bottomInset = useContext(SafeAreaInsetsContext)?.bottom ?? 0;
  const dragY = useRef(new Animated.Value(0)).current;
  const closeRef = useRef(onClose);
  closeRef.current = onClose;
  const pan = useRef(
    PanResponder.create({
      onMoveShouldSetPanResponder: (_e, g) => g.dy > 8 && Math.abs(g.dy) > Math.abs(g.dx),
      onPanResponderMove: (_e, g) => dragY.setValue(Math.max(0, g.dy)),
      onPanResponderRelease: (_e, g) => {
        if (g.dy > SWIPE_CLOSE_DY || g.vy > SWIPE_CLOSE_VY) {
          dragY.setValue(0);
          closeRef.current();
          return;
        }
        Animated.spring(dragY, { toValue: 0, useNativeDriver: true }).start();
      },
      onPanResponderTerminate: () => dragY.setValue(0),
    }),
  ).current;

  const visible = fact !== null;
  const progress = totalCount > 0 ? Math.min(1, readCount / totalCount) : 0;

  return (
    <Modal visible={visible} transparent animationType={reduceMotion ? 'fade' : 'slide'} onRequestClose={onClose} statusBarTranslucent>
      <View style={styles.backdrop}>
        <Pressable
          style={StyleSheet.absoluteFill}
          onPress={onClose}
          accessible={false}
          importantForAccessibility="no"
          testID={`${testID}-backdrop`}
        />
        {fact !== null && (
          <Animated.View
            {...pan.panHandlers}
            style={[styles.sheet, { paddingBottom: 20 + bottomInset, transform: [{ translateY: dragY }] }]}
            role="dialog"
            accessibilityViewIsModal
            aria-modal
            accessibilityLabel={t('facts:child.dialogLabel', { title: fact.title })}
            testID={testID}
          >
            {/* Decorative drag handle (screen readers close with »Super!»). */}
            <View style={styles.handle} accessible={false} importantForAccessibility="no" testID={`${testID}-handle`} />
            <PetAvatar url={imageUrl} size={84} testID={`${testID}-pet`} />
            <View accessible accessibilityLabel={factA11yText(fact)} style={styles.body} testID={`${testID}-content`}>
              <Text style={styles.eyebrow}>{t('facts:child.eyebrow')}</Text>
              <Text style={styles.meta} testID={`${testID}-meta`}>
                {t('facts:child.meta', { breed: breedLabel, category: categoryLabel(fact.category) })}
              </Text>
              <Text style={styles.title} testID={`${testID}-title`} accessibilityRole="header">
                {fact.title}
              </Text>
              {fact.why !== null && (
                <Text style={styles.why} testID={`${testID}-why`}>
                  {fact.why}
                </Text>
              )}
              <Text style={styles.source} testID={`${testID}-source`}>
                {t('facts:child.source', { source: fact.source_name })}
              </Text>
            </View>
            {totalCount > 0 && (
              <View
                style={styles.progressRow}
                accessible
                accessibilityLabel={t('facts:child.progressA11y', { read: readCount, total: totalCount })}
                testID={`${testID}-progress`}
              >
                <View style={styles.track}>
                  <View style={[styles.fill, { width: `${Math.round(progress * 100)}%` }]} />
                </View>
                <Text style={styles.progressText}>{t('facts:child.progress', { read: readCount, total: totalCount })}</Text>
              </View>
            )}
            <Pressable
              accessibilityRole="button"
              onPress={onClose}
              style={({ pressed }) => [styles.primary, pressed && styles.pressed]}
              testID={`${testID}-great`}
            >
              <Text style={styles.primaryText}>{t('facts:child.great')}</Text>
            </Pressable>
            <Pressable
              accessibilityRole="button"
              onPress={onMore}
              style={({ pressed }) => [styles.outline, pressed && styles.pressed]}
              testID={`${testID}-more`}
            >
              <Text style={styles.outlineText}>{t('facts:child.more')}</Text>
            </Pressable>
          </Animated.View>
        )}
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  backdrop: { flex: 1, justifyContent: 'flex-end', backgroundColor: alpha(palette.black, 0.55) },
  sheet: {
    maxHeight: '88%',
    alignItems: 'center',
    gap: 14,
    paddingHorizontal: 22,
    paddingTop: 10,
    borderTopLeftRadius: radius.sheet,
    borderTopRightRadius: radius.sheet,
    backgroundColor: dark.glassStrong,
    borderWidth: 1,
    borderColor: dark.glassBorder,
  },
  handle: { width: 44, height: 5, borderRadius: 3, backgroundColor: alpha(palette.white, 0.25), marginBottom: 4 },
  ring: { borderWidth: 3, borderColor: palette.mint, alignItems: 'center', justifyContent: 'center' },
  avatarFallback: { backgroundColor: alpha(palette.white, 0.08), alignItems: 'center', justifyContent: 'center' },
  body: { alignSelf: 'stretch', alignItems: 'center', gap: 8 },
  eyebrow: { color: palette.mint, fontSize: 12, fontWeight: '700', letterSpacing: 1.4 },
  meta: { color: palette.n400, fontSize: 13, fontWeight: '600', textAlign: 'center' },
  title: {
    color: palette.white,
    fontSize: 28,
    lineHeight: 34,
    fontFamily: fonts.display,
    letterSpacing: tightTracking(28),
    textAlign: 'center',
  },
  why: { color: alpha(palette.white, 0.85), fontSize: 18, lineHeight: 25, textAlign: 'center' },
  source: { color: palette.n400, fontSize: 13, textAlign: 'center' },
  progressRow: { alignSelf: 'stretch', flexDirection: 'row', alignItems: 'center', gap: 10 },
  track: { flex: 1, height: 4, borderRadius: 2, backgroundColor: alpha(palette.white, 0.12), overflow: 'hidden' },
  fill: { height: 4, borderRadius: 2, backgroundColor: palette.mint },
  progressText: { color: palette.n300, fontSize: 13, fontWeight: '700' },
  primary: {
    alignSelf: 'stretch',
    minHeight: MIN_TOUCH + 8,
    borderRadius: radius.button,
    backgroundColor: dark.action,
    alignItems: 'center',
    justifyContent: 'center',
  },
  primaryText: { color: dark.onAction, fontSize: 16, fontWeight: '800' },
  outline: {
    alignSelf: 'stretch',
    minHeight: MIN_TOUCH + 4,
    borderRadius: radius.button,
    borderWidth: 1,
    borderColor: alpha(palette.mint, 0.55),
    alignItems: 'center',
    justifyContent: 'center',
  },
  outlineText: { color: palette.mint, fontSize: 15, fontWeight: '700' },
  pressed: { opacity: 0.8 },
});
