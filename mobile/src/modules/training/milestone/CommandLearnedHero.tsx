/**
 * »Pika zna ukaz »Prostor«!« — the top of a school result that learned a command for the first
 * time (David 2026-11-28; GAMES_SPEC §4.1 »Mejnik«). Plain React Native (safe in every binary):
 * - the big headline (`milestoneHeadline`, announced to the screen reader once);
 * - a short burst of stars around the badge (RN `Animated`, ~1 s, once) — reduce motion → no
 *   movement, the static badge only;
 * - the command's own pose from the school phase media (`milestoneMedia`: the clip once in video
 *   mode, the pose image in picture mode / without a clip; nothing → the badge alone, never blank);
 * - the success sound + haptic through the lazily loaded `MilestoneFx` (game-layer binaries only,
 *   off when the parent turned `game_feedback` off).
 */

import { useEffect, useMemo, useRef } from 'react';
import { AccessibilityInfo, Animated, Easing, StyleSheet, View } from 'react-native';
import { GraduationCap, Star } from 'lucide-react-native';

import PetMediaView from '@/components/PetMediaView';
import { Text } from '@/components/ui/Text';
import type { ShownBreed, Species } from '@/types';
import type { PetMediaInfo } from '@/modules/petMedia/petMedia';
import type { MediaMode } from '@/modules/petMedia/statePlayer';
import { milestoneHeadline, milestoneMedia } from '@/modules/training/commandLearned';
import { loadMilestoneFx } from '@/modules/training/milestone/loadMilestoneFx';
import { TRAINING_STRINGS, type TrainingCommand } from '@/modules/training/training';
import { t } from '@/i18n';
import { alpha, fonts, palette, radius, tightTracking } from '@/theme';

/** Length of the star burst (once). */
export const MILESTONE_BURST_MS = 1_000;

const PIECES = 10;
const PIECE_COLOURS = [palette.mint, palette.warnDark, palette.raspberry, palette.white] as const;
const BURST_DISTANCE = 92;

export interface CommandLearnedHeroProps {
  command: TrainingCommand;
  petName: string | null;
  media: PetMediaInfo;
  mode: MediaMode;
  breed: ShownBreed;
  species: Species | null;
  reduceMotion: boolean;
  /** The parent's »Zvoki in tresljaji« (`gameFeedbackOf(view)`). */
  soundAndHaptics: boolean;
  onMediaExpired?: () => void;
}

function Burst() {
  const progress = useRef(new Animated.Value(0)).current;
  useEffect(() => {
    const animation = Animated.timing(progress, { toValue: 1, duration: MILESTONE_BURST_MS, easing: Easing.out(Easing.cubic), useNativeDriver: true });
    animation.start();
    return () => animation.stop();
  }, [progress]);
  const opacity = progress.interpolate({ inputRange: [0, 0.15, 0.7, 1], outputRange: [0, 1, 1, 0] });
  return (
    <View style={styles.burst} pointerEvents="none" testID="training-milestone-burst">
      {Array.from({ length: PIECES }, (_, i) => {
        const angle = (i / PIECES) * Math.PI * 2;
        const distance = BURST_DISTANCE * (i % 2 === 0 ? 1 : 0.7);
        const translateX = progress.interpolate({ inputRange: [0, 1], outputRange: [0, Math.cos(angle) * distance] });
        const translateY = progress.interpolate({ inputRange: [0, 1], outputRange: [0, Math.sin(angle) * distance] });
        const scale = progress.interpolate({ inputRange: [0, 0.3, 1], outputRange: [0.3, 1.1, 0.8] });
        const colour = PIECE_COLOURS[i % PIECE_COLOURS.length];
        return (
          <Animated.View key={i} style={[styles.piece, { opacity, transform: [{ translateX }, { translateY }, { scale }] }]}>
            <Star size={16} color={colour} fill={colour} />
          </Animated.View>
        );
      })}
    </View>
  );
}

export default function CommandLearnedHero({ command, petName, media, mode, breed, species, reduceMotion, soundAndHaptics, onMediaExpired }: CommandLearnedHeroProps) {
  const headline = milestoneHeadline(command, petName);
  const commandName = TRAINING_STRINGS.commands[command].name;
  const picture = useMemo(() => milestoneMedia(media, command, mode), [media, command, mode]);
  const Fx = loadMilestoneFx();
  const announced = useRef(false);
  useEffect(() => {
    if (announced.current) return;
    announced.current = true;
    AccessibilityInfo.announceForAccessibility?.(headline);
  }, [headline]);

  return (
    <View style={styles.hero} testID="training-milestone">
      {Fx !== null && <Fx enabled={soundAndHaptics} />}
      <Text style={styles.headline} accessibilityRole="header" testID="training-milestone-title">
        {headline}
      </Text>
      <Text style={styles.sub} testID="training-milestone-sub">
        {TRAINING_STRINGS.milestone.sub}
      </Text>
      {picture !== null && (
        <PetMediaView
          media={media}
          petState="idle"
          phase={picture}
          mode={mode}
          breed={breed}
          species={species}
          onMediaExpired={onMediaExpired}
          variant="card"
          style={styles.media}
          testID="training-milestone-media"
        />
      )}
      <View style={styles.badgeArea}>
        {!reduceMotion && <Burst />}
        <View style={styles.badge} accessible accessibilityLabel={t('training:child.milestone.badgeA11y', { name: commandName })} testID="training-milestone-badge">
          <GraduationCap color={palette.graphite} size={34} />
        </View>
        <View style={styles.badgePill}>
          <Text style={styles.badgePillText}>
            {commandName} ✓ · {TRAINING_STRINGS.milestone.badge}
          </Text>
        </View>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  hero: { alignItems: 'center', gap: 12 },
  headline: { color: palette.mint, fontSize: 32, lineHeight: 38, letterSpacing: tightTracking(32), fontFamily: fonts.displayBold, textAlign: 'center' },
  sub: { color: palette.white, fontSize: 17, fontWeight: '700', textAlign: 'center' },
  media: { width: '100%', aspectRatio: 1, maxHeight: 280, borderRadius: 24, overflow: 'hidden' },
  badgeArea: { alignItems: 'center', justifyContent: 'center', paddingVertical: 8, gap: 10 },
  burst: { position: 'absolute', top: 8, left: 0, right: 0, height: 76, alignItems: 'center', justifyContent: 'center' },
  piece: { position: 'absolute' },
  badge: {
    width: 76,
    height: 76,
    borderRadius: 38,
    backgroundColor: palette.mint,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 3,
    borderColor: alpha(palette.white, 0.6),
  },
  badgePill: { paddingHorizontal: 14, paddingVertical: 6, borderRadius: radius.pill, backgroundColor: alpha(palette.mint, 0.18) },
  badgePillText: { color: palette.mint, fontSize: 15, fontWeight: '800' },
});
