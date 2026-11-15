/**
 * »Priboljšek« on the child HUD (M6-W04): a glass chip in the chip row, shown only while the
 * pet has treats (`showTreatButton`). A given treat plays the treat clip once ON THE STAGE
 * (M6-W03: `media.items.treats` via the HUD's `useItemMoment`; picture mode → its image);
 * without one the HUD shows a short toast and the hearts. Treats never change needs or scores (S1).
 *
 * David 2026-11-15 (the general media rule — a feature is never usable without its animation):
 * while the treat's animation is not ready (`treatsPreparing`, server `wishes.treats.ready`
 * false) the chip is shown but OFF, with »Priboljški se še pripravljajo« as its hint — the same
 * pattern as the school / game chips. Nothing is said about pictures or videos being made.
 */

import { Pressable, StyleSheet } from 'react-native';
import { Bone, Cookie } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { t } from '@/i18n';
import { ApiError } from '@/api/client';
import { classifyActionError, failureMessage } from '@/modules/childPet/actionMessages';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { COMPACT_CHIP_HIT_SLOP, compactChipStyles } from '@/modules/hud/compactChip';
import { treatFailureOf } from '@/modules/wishes/wishes';
import { alpha, palette } from '@/theme';

/** The friendly text of a refused / failed treat (422 reasons, 423 locks, offline …). */
export function treatErrorText(error: unknown, view: ChildPetView | null): string {
  switch (treatFailureOf(error)) {
    case 'treat_limit_today':
      return t('wishes:child.treat.limit');
    case 'no_treats':
      return t('wishes:child.treat.noTreats');
    case 'pet_sleeping':
      return t('wishes:child.treat.sleeping');
    case 'media_not_ready':
      return t('wishes:child.treat.preparing');
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
  /** The treats are not ready yet (`treatsPreparing`): the chip is off, the hint says why. */
  preparing?: boolean;
}

export function TreatChip({ left, species, busy, onPress, compact = false, preparing = false }: TreatChipProps) {
  const Icon = species === 'cat' ? Cookie : Bone;
  const disabled = busy || preparing;
  return (
    <Pressable
      testID="hud-treat"
      accessibilityRole="button"
      accessibilityLabel={t('wishes:child.treat.a11y', { count: left })}
      accessibilityHint={preparing ? t('wishes:child.treat.preparing') : undefined}
      accessibilityState={{ disabled, busy }}
      disabled={disabled}
      onPress={onPress}
      hitSlop={compact ? COMPACT_CHIP_HIT_SLOP : 6}
      style={({ pressed }) => [styles.chip, compact && compactChipStyles.chip, pressed && styles.pressed, disabled && styles.disabled]}
    >
      <Icon color={palette.mint} size={16} />
      {!compact && <Text style={styles.text}>{t('wishes:child.treat.button')}</Text>}
      {!compact && <Text style={styles.count}>{left}</Text>}
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
});
