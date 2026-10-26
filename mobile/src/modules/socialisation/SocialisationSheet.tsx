/**
 * Puppy socialisation (M5-R17, David 2026-10-12): the "Nova izkušnja" chip above the HUD dock
 * and its short card — the child picks what the puppy experienced this week (a new person,
 * another dog, a new sound, a new place) and one tap marks it done. No mini-game, no timer,
 * never scored. Same dark glass bottom sheet as `ChildSettingsSheet` (RN `Modal`: Android
 * back button, accessibility-modal container). A refusal (already done, the puppy sleeps)
 * is explained with its time; nothing here re-implements a server rule.
 */

import { useContext, useState } from 'react';
import { ActivityIndicator, Modal, Pressable, StyleSheet, View } from 'react-native';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';
import { Dog, MapPin, Sparkles, UserRound, Volume2, X } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { useSocialise } from '@/hooks/queries/useSocialise';
import { classifyActionError, serverUpdatingActionText } from '@/modules/childPet/actionMessages';
import {
  SOCIALISATION_STRINGS as S,
  experienceHint,
  experienceLabel,
  socialisationRefusalText,
  type ChildSocialisation,
  type SocialisationExperience,
} from '@/modules/socialisation/socialisation';
import { COMPACT_CHIP_HIT_SLOP, compactChipStyles } from '@/modules/hud/compactChip';
import { t } from '@/i18n';
import { MIN_TOUCH, alpha, dark, fonts, palette, radius, tightTracking } from '@/theme';

const ICONS: Readonly<Record<SocialisationExperience, typeof Dog>> = {
  person: UserRound,
  dog: Dog,
  sound: Volume2,
  place: MapPin,
};

export interface SocialisationChipProps {
  /** Amber dot: the server would accept a record now. */
  pending: boolean;
  onPress: () => void;
  /** Collapsed HUD top stack: icon only (the full name stays the a11y label). */
  compact?: boolean;
}

/** "Nova izkušnja" — a glass chip like "Šola" / "Počeši". */
export function SocialisationChip({ pending, onPress, compact = false }: SocialisationChipProps) {
  return (
    <Pressable
      testID="hud-socialisation-chip"
      accessibilityRole="button"
      accessibilityLabel={S.chipA11y}
      onPress={onPress}
      hitSlop={compact ? COMPACT_CHIP_HIT_SLOP : 6}
      style={({ pressed }) => [styles.chip, compact && compactChipStyles.chip, pressed && styles.pressed]}
    >
      <Sparkles color={palette.mint} size={16} />
      {!compact && <Text style={styles.chipText}>{S.chip}</Text>}
      {pending && <View style={[styles.badge, compact && compactChipStyles.corner]} testID="hud-socialisation-chip-badge" />}
    </Pressable>
  );
}

export interface SocialisationSheetProps {
  visible: boolean;
  socialisation: ChildSocialisation | null;
  /** Server time of the latest state + family zone (for "jutri ob 08:00"). */
  nowIso: string | null;
  timezone: string | null;
  onClose: () => void;
  testID?: string;
}

type Outcome = { kind: 'success' } | { kind: 'error'; text: string } | null;

export default function SocialisationSheet({
  visible,
  socialisation,
  nowIso,
  timezone,
  onClose,
  testID = 'hud-socialisation-sheet',
}: SocialisationSheetProps) {
  const bottomInset = useContext(SafeAreaInsetsContext)?.bottom ?? 0;
  const socialise = useSocialise();
  const [outcome, setOutcome] = useState<Outcome>(null);

  const close = () => {
    setOutcome(null);
    onClose();
  };

  const pick = (experience: SocialisationExperience) => {
    setOutcome(null);
    socialise.mutate(experience, {
      onSuccess: () => setOutcome({ kind: 'success' }),
      onError: (error) => {
        const failure = classifyActionError(error);
        const text =
          failure.kind === 'refused'
            ? socialisationRefusalText(failure.reason, failure.nextAllowedAt, nowIso, timezone)
            : failure.kind === 'offline'
              ? t('socialisation:errors.offline')
              : failure.kind === 'updating'
                ? serverUpdatingActionText()
                : t('socialisation:errors.other');
        setOutcome({ kind: 'error', text });
      },
    });
  };

  if (socialisation === null) return null;
  const busy = socialise.isPending;
  const blocked = !socialisation.done && !socialisation.can_record && socialisation.blocked_reason !== null;
  const disabled = busy || socialisation.done || !socialisation.can_record;
  const status =
    outcome?.kind === 'success'
      ? S.success
      : outcome?.kind === 'error'
        ? outcome.text
        : socialisation.done
          ? S.doneWith(socialisation.experience !== null ? experienceLabel(socialisation.experience) : '✓')
          : blocked
            ? socialisationRefusalText(socialisation.blocked_reason, socialisation.next_allowed_at, nowIso, timezone)
            : null;

  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={close} statusBarTranslucent>
      <View style={styles.backdrop}>
        <Pressable
          style={StyleSheet.absoluteFill}
          onPress={close}
          accessible={false}
          importantForAccessibility="no"
          testID={`${testID}-backdrop`}
        />
        <View style={[styles.sheet, { paddingBottom: 24 + bottomInset }]} testID={testID} accessibilityViewIsModal>
          <View style={styles.headerRow}>
            <View style={styles.flex}>
              <Text style={styles.title} accessibilityRole="header">
                {S.title}
              </Text>
              {socialisation.week > 0 && <Text style={styles.week}>{S.week(socialisation.week)}</Text>}
            </View>
            <Pressable
              onPress={close}
              accessibilityRole="button"
              accessibilityLabel={S.close}
              style={({ pressed }) => [styles.close, pressed && styles.pressed]}
              testID={`${testID}-close`}
            >
              <X color={palette.white} size={20} />
            </Pressable>
          </View>
          <Text style={styles.body}>{S.intro}</Text>
          {!socialisation.done && <Text style={styles.hint}>{S.pick}</Text>}
          <View style={styles.options}>
            {socialisation.experiences.map((experience) => {
              const Icon = ICONS[experience];
              const chosen = socialisation.done && socialisation.experience === experience;
              return (
                <Pressable
                  key={experience}
                  onPress={() => pick(experience)}
                  disabled={disabled}
                  accessibilityRole="button"
                  accessibilityState={{ disabled, selected: chosen }}
                  accessibilityLabel={`${experienceLabel(experience)}. ${experienceHint(experience)}`}
                  style={({ pressed }) => [styles.option, chosen && styles.optionChosen, disabled && !chosen && styles.optionDisabled, pressed && styles.pressed]}
                  testID={`${testID}-option-${experience}`}
                >
                  <Icon color={palette.mint} size={22} />
                  <View style={styles.flex}>
                    <Text style={styles.optionLabel}>{experienceLabel(experience)}</Text>
                    <Text style={styles.optionHint}>{experienceHint(experience)}</Text>
                  </View>
                </Pressable>
              );
            })}
          </View>
          {busy && (
            <View style={styles.statusRow}>
              <ActivityIndicator color={palette.mint} />
              <Text style={styles.hint}>{S.saving}</Text>
            </View>
          )}
          {!busy && status !== null && (
            <Text style={outcome?.kind === 'error' ? styles.error : styles.status} testID={`${testID}-status`} accessibilityLiveRegion="polite">
              {status}
            </Text>
          )}
          <Text style={styles.tip}>{S.tip}</Text>
        </View>
      </View>
    </Modal>
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
  badge: { width: 9, height: 9, borderRadius: 5, backgroundColor: palette.warnDark },
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
  flex: { flex: 1 },
  title: { color: palette.white, fontSize: 20, letterSpacing: tightTracking(20), fontFamily: fonts.display },
  week: { color: palette.n400, fontSize: 13, fontWeight: '600' },
  close: {
    width: MIN_TOUCH,
    height: MIN_TOUCH,
    borderRadius: MIN_TOUCH / 2,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: alpha(palette.white, 0.1),
  },
  body: { color: palette.n200, fontSize: 15, lineHeight: 21 },
  hint: { color: palette.n400, fontSize: 13 },
  options: { gap: 8 },
  option: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    minHeight: MIN_TOUCH + 8,
    paddingHorizontal: 14,
    paddingVertical: 10,
    borderRadius: 16,
    backgroundColor: alpha(palette.white, 0.06),
    borderWidth: 1,
    borderColor: alpha(palette.mint, 0.25),
  },
  optionChosen: { borderColor: palette.mint, backgroundColor: alpha(palette.mint, 0.14) },
  optionDisabled: { opacity: 0.5 },
  optionLabel: { color: palette.white, fontSize: 16, fontWeight: '800' },
  optionHint: { color: palette.n300, fontSize: 13, lineHeight: 18 },
  statusRow: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  status: { color: palette.mint, fontSize: 15, fontWeight: '700' },
  error: { color: palette.warnDark, fontSize: 14, fontWeight: '600' },
  tip: { color: palette.n400, fontSize: 12, lineHeight: 17 },
  pressed: { opacity: 0.8 },
});
