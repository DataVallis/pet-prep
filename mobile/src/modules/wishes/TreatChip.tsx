/**
 * »Priboljšek« on the child HUD (M6-W04): a glass chip in the chip row, shown only while the
 * pet has treats (`showTreatButton`). A given treat plays the treat clip when the server sent
 * one (`TreatClip`, once, tap / end / 8 s closes it); otherwise the HUD shows a short toast
 * and the hearts. Treats never change needs or scores (S1).
 */

import { useEffect, useMemo } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';
import { useVideoPlayer, VideoView } from 'expo-video';
import { Bone, Cookie } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { t } from '@/i18n';
import { ApiError } from '@/api/client';
import { classifyActionError, failureMessage } from '@/modules/childPet/actionMessages';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { COMPACT_CHIP_HIT_SLOP, compactChipStyles } from '@/modules/hud/compactChip';
import { useLocalMediaUri } from '@/modules/petMedia/hooks';
import { isLocalMediaUri } from '@/modules/petMedia/mediaFileCache';
import { treatFailureOf } from '@/modules/wishes/wishes';
import { MIN_TOUCH, alpha, palette } from '@/theme';

/** The friendly text of a refused / failed treat (422 reasons, 423 locks, offline …). */
export function treatErrorText(error: unknown, view: ChildPetView | null): string {
  switch (treatFailureOf(error)) {
    case 'treat_limit_today':
      return t('wishes:child.treat.limit');
    case 'no_treats':
      return t('wishes:child.treat.noTreats');
    case 'pet_sleeping':
      return t('wishes:child.treat.sleeping');
    default:
      break;
  }
  const failure = classifyActionError(error);
  if (failure.kind === 'refused' || (error instanceof ApiError && error.status === 422)) return t('wishes:child.treat.failed');
  return failureMessage(failure, view) ?? t('wishes:child.treat.failed');
}

export interface TreatChipProps {
  left: number;
  species: string | null;
  busy: boolean;
  onPress: () => void;
  compact?: boolean;
}

export function TreatChip({ left, species, busy, onPress, compact = false }: TreatChipProps) {
  const Icon = species === 'cat' ? Cookie : Bone;
  return (
    <Pressable
      testID="hud-treat"
      accessibilityRole="button"
      accessibilityLabel={t('wishes:child.treat.a11y', { count: left })}
      accessibilityState={{ disabled: busy, busy }}
      disabled={busy}
      onPress={onPress}
      hitSlop={compact ? COMPACT_CHIP_HIT_SLOP : 6}
      style={({ pressed }) => [styles.chip, compact && compactChipStyles.chip, pressed && styles.pressed, busy && styles.disabled]}
    >
      <Icon color={palette.mint} size={16} />
      {!compact && <Text style={styles.text}>{t('wishes:child.treat.button')}</Text>}
      {!compact && <Text style={styles.count}>{left}</Text>}
    </Pressable>
  );
}

/** Safety: a clip that never reports its end closes after this long. */
export const TREAT_CLIP_MAX_MS = 8_000;

export function TreatClip({ url, onClose }: { url: string; onClose: () => void }) {
  const local = useLocalMediaUri(url, 'video');
  const source = useMemo(() => (local.uri === null ? null : isLocalMediaUri(local.uri) ? { uri: local.uri } : { uri: local.uri, useCaching: true }), [local.uri]);
  const player = useVideoPlayer(source, (p) => {
    p.loop = false;
    p.muted = true;
    p.audioMixingMode = 'mixWithOthers';
  });
  useEffect(() => {
    if (source !== null) player.play();
    const sub = player.addListener('playToEnd', onClose);
    const timer = setTimeout(onClose, TREAT_CLIP_MAX_MS);
    return () => {
      sub.remove();
      clearTimeout(timer);
    };
  }, [player, source, onClose]);
  return (
    <Pressable
      style={styles.clipLayer}
      onPress={onClose}
      accessibilityRole="button"
      accessibilityLabel={t('wishes:child.treat.given')}
      testID="hud-treat-clip"
    >
      <View style={styles.clipBox} pointerEvents="none">
        <VideoView player={player} contentFit="cover" nativeControls={false} allowsPictureInPicture={false} style={StyleSheet.absoluteFill} />
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  chip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    minHeight: 40,
    paddingHorizontal: 12,
    borderRadius: 20,
    backgroundColor: alpha(palette.graphite, 0.85),
    borderWidth: 1,
    borderColor: alpha(palette.mint, 0.35),
  },
  text: { color: palette.white, fontSize: 14, fontWeight: '800' },
  count: { color: palette.mint, fontSize: 13, fontWeight: '800' },
  pressed: { opacity: 0.75 },
  disabled: { opacity: 0.5 },
  clipLayer: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, zIndex: 40, backgroundColor: alpha(palette.black, 0.6), alignItems: 'center', justifyContent: 'center' },
  clipBox: { width: '88%', aspectRatio: 9 / 16, maxHeight: '80%', borderRadius: 22, overflow: 'hidden', minHeight: MIN_TOUCH },
});
