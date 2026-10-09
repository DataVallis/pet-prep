/**
 * "Igra" entry of the child HUD (M5-R05, PLAY_CUDDLE_SPEC §4.1), in the column above the
 * dock next to "Šola" (never under the dock):
 * - `PlayChip` — glass chip "Igra" that opens the choice Žoga / Crkljanje; disabled while
 *   the server says no (`can_play` false), its reason in `PlayBlockedNote` under the chips;
 * - `PlayInvitationCard` — the dog's invitation ("Kuža ti prinaša žogo. Se igrava?") with
 *   "Igraj se" / "Pobožaj" and "Mogoče kasneje". A soft pulse on the heart (none with
 *   "reduce motion"); no countdown, no pressure;
 * - `HappyBadge` — "Kuža je vesel" while the 30-minute happy scene runs.
 */

import { useEffect, useRef } from 'react';
import { Animated, Pressable, StyleSheet, View } from 'react-native';
import { Text } from '@/components/ui/Text';
import { HandHeart, Heart } from 'lucide-react-native';

import {
  invitationTexts,
  MOMENT_STRINGS,
  MOOD_STRINGS,
  PLAY_BLOCK_STRINGS,
  PLAY_STRINGS,
  type PlayBlock,
  type PlayInvitation,
  type PlayKind,
} from '@/modules/play/play';
import { MIN_TOUCH, alpha, palette } from '@/theme';

export interface PlayChipProps {
  block: PlayBlock | null;
  onPress: () => void;
  /** M5-R06-08b: a cat's chip reads "Crkljanje" (a cat only cuddles); default "Igra". */
  label?: string;
  accessibilityLabel?: string;
}

export function PlayChip({ block, onPress, label, accessibilityLabel }: PlayChipProps) {
  const disabled = block !== null;
  return (
    <Pressable
      testID="hud-play-open"
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel ?? PLAY_STRINGS.chipA11y}
      accessibilityHint={block !== null ? PLAY_BLOCK_STRINGS[block] : undefined}
      accessibilityState={{ disabled }}
      disabled={disabled}
      onPress={onPress}
      style={({ pressed }) => [styles.chip, pressed && styles.pressed, disabled && styles.disabled]}
    >
      <HandHeart color={palette.mint} size={16} />
      <Text style={styles.chipText}>{label ?? PLAY_STRINGS.chip}</Text>
    </Pressable>
  );
}

/** Why "Igra" is off ("Kuža spi …", "Najprej počisti …"); shown under the chip row. */
export function PlayBlockedNote({ text }: { text: string }) {
  return (
    <Text style={styles.note} testID="hud-play-blocked">
      {text}
    </Text>
  );
}

export interface PlayInvitationCardProps {
  invitation: PlayInvitation;
  onAccept: (kind: PlayKind) => void;
  onDismiss: (id: number) => void;
  reduceMotion: boolean;
}

export function PlayInvitationCard({ invitation, onAccept, onDismiss, reduceMotion }: PlayInvitationCardProps) {
  const texts = invitationTexts(invitation.kind);
  const pulse = useRef(new Animated.Value(0)).current;
  useEffect(() => {
    if (reduceMotion) {
      pulse.setValue(0);
      return;
    }
    const loop = Animated.loop(
      Animated.sequence([
        Animated.timing(pulse, { toValue: 1, duration: 700, useNativeDriver: true }),
        Animated.timing(pulse, { toValue: 0, duration: 700, useNativeDriver: true }),
      ]),
    );
    loop.start();
    return () => loop.stop();
  }, [pulse, reduceMotion]);
  const scale = pulse.interpolate({ inputRange: [0, 1], outputRange: [1, 1.18] });

  return (
    <View style={styles.card} testID={`hud-play-invitation-${invitation.kind}`}>
      <View style={styles.cardRow}>
        <Animated.View style={{ transform: [{ scale }] }} testID="hud-play-invitation-pulse">
          <Heart color={palette.raspberry} fill={palette.raspberry} size={18} />
        </Animated.View>
        <Text style={styles.cardText} accessibilityLiveRegion="polite">
          {texts.line}
        </Text>
      </View>
      <View style={styles.cardRow}>
        <Pressable
          testID="hud-play-invitation-accept"
          accessibilityRole="button"
          onPress={() => onAccept(invitation.kind)}
          style={({ pressed }) => [styles.acceptButton, pressed && styles.pressed]}
        >
          <Text style={styles.acceptText}>{texts.action}</Text>
        </Pressable>
        <Pressable
          testID="hud-play-invitation-dismiss"
          accessibilityRole="button"
          onPress={() => onDismiss(invitation.id)}
          hitSlop={8}
          style={({ pressed }) => [styles.dismissButton, pressed && styles.pressed]}
        >
          <Text style={styles.dismissText}>{MOMENT_STRINGS.dismiss}</Text>
        </Pressable>
      </View>
    </View>
  );
}

/** "Kuža je vesel" pill while the happy scene runs. */
export function HappyBadge() {
  return (
    <View style={styles.happy} testID="hud-happy" accessible accessibilityLabel={MOOD_STRINGS.happy}>
      <Heart color={palette.raspberry} fill={palette.raspberry} size={14} />
      <Text style={styles.happyText}>{MOOD_STRINGS.happy}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  chip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    minHeight: MIN_TOUCH,
    paddingHorizontal: 14,
    borderRadius: 20,
    backgroundColor: alpha(palette.graphite, 0.85),
    borderWidth: 1,
    borderColor: alpha(palette.mint, 0.35),
  },
  chipText: { color: palette.white, fontSize: 14, fontWeight: '800' },
  note: {
    maxWidth: '100%',
    color: palette.n300,
    fontSize: 12,
    fontWeight: '600',
    textAlign: 'center',
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: 12,
    overflow: 'hidden',
    backgroundColor: alpha(palette.graphite, 0.82),
  },
  card: {
    maxWidth: '100%',
    gap: 10,
    paddingHorizontal: 14,
    paddingVertical: 12,
    borderRadius: 20,
    backgroundColor: alpha(palette.graphite, 0.88),
    borderWidth: 1,
    borderColor: alpha(palette.mint, 0.45),
  },
  cardRow: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  cardText: { flexShrink: 1, color: palette.white, fontSize: 14, fontWeight: '700' },
  acceptButton: {
    minHeight: MIN_TOUCH,
    paddingHorizontal: 18,
    borderRadius: 22,
    backgroundColor: palette.mint,
    alignItems: 'center',
    justifyContent: 'center',
  },
  acceptText: { color: palette.graphite, fontSize: 14, fontWeight: '800' },
  dismissButton: { minHeight: MIN_TOUCH, paddingHorizontal: 8, justifyContent: 'center' },
  dismissText: { color: palette.n300, fontSize: 13, fontWeight: '600' },
  happy: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    paddingHorizontal: 12,
    paddingVertical: 6,
    borderRadius: 14,
    backgroundColor: alpha(palette.graphite, 0.82),
    borderWidth: 1,
    borderColor: alpha(palette.raspberry, 0.4),
  },
  happyText: { color: palette.white, fontSize: 12, fontWeight: '700' },
  pressed: { opacity: 0.75 },
  disabled: { opacity: 0.5 },
});
