/**
 * M6-U01 (FREE_PET_UPGRADE_SPEC §4 »Starš (PR 1)«, David 2026-11-10) — the entry on a FREE pet's
 * card and on the child's page: »Začni 12-tedenski izziv z [ime]«. Parent app only; no price
 * here (the paywall shows the store price). Shown only when the server allows the upgrade
 * (`canUpgradePet`); a pre-M5 legacy-profile pet gets an honest short note instead (D3,
 * `upgradeNote`) — never a silently missing button. Graphite primary button like
 * `ChallengeBuyButton`, ≥ 44 pt, wraps at large font sizes.
 */

import { Pressable, StyleSheet, View } from 'react-native';
import { Sparkles } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { currentLanguage, t } from '@/i18n';
import type { FamilyPet } from '@/modules/family/family';
import { canUpgradePet, upgradeNote } from '@/modules/plan/purchaseEntry';
import { MIN_TOUCH, palette, radius } from '@/theme';

/** Slovenian »s« before a voiceless consonant (c č f h k p s š t), else »z« (»s Piko«, »z Luno«). */
export function slovenianWithS(name: string): boolean {
  return /^[cčfhkpsšt]/i.test(name.trim());
}

/** »Začni 12-tedenski izziv z Luno« — the pet's own name only (no name → the plain label). */
export function upgradeButtonLabel(name: string | null): string {
  const trimmed = name?.trim() ?? '';
  if (trimmed === '') return t('paywall:upgrade.buttonNoName');
  const key = currentLanguage() === 'sl' && slovenianWithS(trimmed) ? 'paywall:upgrade.buttonS' : 'paywall:upgrade.button';
  return t(key, { name: trimmed });
}

interface ChallengeUpgradeEntryProps {
  pet: FamilyPet | null;
  /** Child nickname for the screen-reader label. */
  childName: string;
  onUpgrade: (petId: number) => void;
  testID: string;
}

export default function ChallengeUpgradeEntry({ pet, childName, onUpgrade, testID }: ChallengeUpgradeEntryProps) {
  if (pet === null) return null;
  if (canUpgradePet(pet)) {
    return (
      <Pressable
        style={({ pressed }) => [styles.button, pressed && styles.pressed]}
        onPress={() => onUpgrade(pet.id)}
        accessibilityRole="button"
        accessibilityLabel={t('paywall:upgrade.buttonA11y', { child: childName })}
        accessibilityHint={t('paywall:upgrade.buttonHint')}
        testID={testID}
      >
        <Sparkles color={palette.white} size={16} />
        <Text style={styles.text}>{upgradeButtonLabel(pet.name ?? null)}</Text>
      </Pressable>
    );
  }
  if (upgradeNote(pet) === 'legacy_profile') {
    return (
      <View style={styles.note} testID={`${testID}-legacy`}>
        <Text style={styles.noteText}>{t('paywall:upgrade.legacyNote')}</Text>
      </View>
    );
  }
  return null;
}

const styles = StyleSheet.create({
  button: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    minHeight: MIN_TOUCH,
    paddingHorizontal: 16,
    paddingVertical: 10,
    borderRadius: radius.button,
    backgroundColor: C.accent,
  },
  text: { flexShrink: 1, fontSize: 15, fontWeight: '700', color: palette.white, textAlign: 'center' },
  pressed: { opacity: 0.85 },
  note: { paddingVertical: 4 },
  noteText: { fontSize: 13, lineHeight: 18, color: C.muted },
});
