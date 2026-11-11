/**
 * M6-U02 (FREE_PET_UPGRADE_SPEC U5 / D1, David 2026-11-10): the day-7 reminder card on the parent
 * dashboard — »Maja že 7 dni skrbi za kužka Pika. Je čas za pravi preizkus?« — one per free pet the
 * server marked (`pets[].upgrade_reminder`, see `modules/plan/upgradeReminder.ts`). The button opens
 * the PR 1 upgrade paywall for that pet; »×« hides the card on this phone. Parent app only, no
 * price here (the paywall shows the store price). The pet's name is a label after the species
 * word (no Slovenian declension of names).
 */

import { useEffect, useState } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';
import { X } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { tSpecies } from '@/i18n';
import type { FamilyOverview } from '@/modules/family/family';
import {
  dismissedReminderPets,
  dismissReminderPet,
  upgradeReminderCards,
  type UpgradeReminderCard,
} from '@/modules/plan/upgradeReminder';
import { palette } from '@/theme';

/** The card's sentence (child nickname / pet name optional). */
export function upgradeReminderText(card: Pick<UpgradeReminderCard, 'childName' | 'petName'> & { species: string | null | undefined }): string {
  const child = card.childName ?? null;
  const name = card.petName ?? null;
  const key =
    child !== null
      ? name !== null
        ? 'paywall:upgradeReminder.body'
        : 'paywall:upgradeReminder.bodyNoName'
      : name !== null
        ? 'paywall:upgradeReminder.bodyNoChild'
        : 'paywall:upgradeReminder.bodyNoChildNoName';
  return tSpecies(key, card.species, { child, name });
}

export default function UpgradeReminderCards({ family, onUpgrade }: { family: FamilyOverview | null; onUpgrade: (petId: number) => void }) {
  // null = not read yet (never flash a card that was dismissed on this phone).
  const [dismissed, setDismissed] = useState<number[] | null>(null);

  useEffect(() => {
    let alive = true;
    void dismissedReminderPets().then((ids) => {
      if (alive) setDismissed(ids);
    });
    return () => {
      alive = false;
    };
  }, []);

  if (dismissed === null) return null;
  const cards = upgradeReminderCards(family, dismissed);
  if (cards.length === 0) return null;

  return (
    <>
      {cards.map((card) => (
        <View key={card.pet.id} style={styles.box} testID={`upgrade-reminder-${card.pet.id}`}>
          <View style={styles.titleRow}>
            <Text style={[styles.title, styles.flex]}>{upgradeReminderText({ ...card, species: card.pet.species })}</Text>
            <Pressable
              onPress={() => {
                setDismissed((ids) => [...(ids ?? []), card.pet.id]);
                void dismissReminderPet(card.pet.id);
              }}
              hitSlop={12}
              accessibilityRole="button"
              accessibilityLabel={tSpecies('paywall:upgradeReminder.dismiss', card.pet.species)}
              testID={`upgrade-reminder-dismiss-${card.pet.id}`}
            >
              <X color={C.muted} size={18} />
            </Pressable>
          </View>
          <Text style={styles.body}>{tSpecies('paywall:upgradeReminder.hint', card.pet.species)}</Text>
          <Pressable
            style={({ pressed }) => [styles.button, pressed && styles.pressed]}
            onPress={() => onUpgrade(card.pet.id)}
            accessibilityRole="button"
            testID={`upgrade-reminder-open-${card.pet.id}`}
          >
            <Text style={styles.buttonText}>{tSpecies('paywall:upgradeReminder.action', card.pet.species)}</Text>
          </Pressable>
        </View>
      ))}
    </>
  );
}

const styles = StyleSheet.create({
  box: { padding: 14, borderRadius: 16, borderWidth: 1, gap: 6, backgroundColor: palette.okSoft, borderColor: palette.okBorder },
  titleRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 8 },
  flex: { flex: 1 },
  title: { fontSize: 15, fontWeight: '700', color: C.text },
  body: { fontSize: 14, lineHeight: 20, color: C.text },
  button: { marginTop: 4, minHeight: 44, borderRadius: 12, backgroundColor: C.accent, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 12 },
  buttonText: { fontSize: 15, fontWeight: '700', color: palette.white },
  pressed: { opacity: 0.85 },
});
