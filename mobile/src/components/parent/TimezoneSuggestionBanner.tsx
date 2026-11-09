/**
 * Parent dashboard banner, PER PET (David 2026-11-09; first family-wide in 2026-11-08 »kar B«;
 * parent only — never the child). Shown while the phone's current UTC offset differs from the
 * clock of a pet cared for on THIS phone (the child profiles this parent opened here, incl. the
 * parent's own caretaker profile — `usePhoneChildIds`):
 *   one pet  → »Telefon je v drugem časovnem pasu (Lizbona) kot Suki (Ljubljana). Zamenjam za Suki?«
 *   several  → a short list with checkboxes, pre-checked only for this phone's pets (pets other
 *              family members care for are listed, never pre-selected).
 * »Zamenjam« → the warning (same text as the sheet) → »Zamenjaj« sets each checked pet to the
 * phone's zone (`PUT /api/parent/pets/{id}/timezone`, one by one; not optimistic). »Ne zdaj«
 * hides it for THIS phone zone (device store) — it comes back only in yet another zone.
 * Re-checked when the app returns to the foreground.
 */

import { useMemo, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, View } from 'react-native';
import { Check } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { useUpdatePetTimezone } from '@/hooks/queries/useParentQueries';
import { currentLanguage } from '@/i18n';
import { breedLabel } from '@/modules/family/family';
import {
  petZoneSuggestion,
  phonePetIds,
  readTimezoneChangeError,
  zoneCity,
  type TimezoneChangeError,
  type ZonePet,
} from '@/modules/familyZone/familyZone';
import { usePhoneChildIds } from '@/modules/familyZone/usePhoneChildIds';
import { usePhoneZone } from '@/modules/familyZone/usePhoneZone';
import { FAMILY_ZONE_STRINGS as S, timezoneErrorText } from '@/modules/familyZone/strings';
import { dismissZoneSuggestion, useZoneSuggestionDismissal } from '@/modules/familyZone/zoneSuggestionPrefs';
import { readPetName } from '@/modules/petName/petName';
import { MIN_TOUCH, palette } from '@/theme';

export type BannerPet = ZonePet & { name?: unknown; breed_type: string; species?: string | null };

/** »Suki« — the pet's name, else its breed (no »· breed« suffix in a sentence). */
export function bannerPetName(pet: Pick<BannerPet, 'name' | 'breed_type' | 'species'>): string {
  return readPetName(pet.name) ?? breedLabel(pet.breed_type, pet.species);
}

interface TimezoneSuggestionBannerProps {
  familyZone: string | null;
  pets: readonly BannerPet[];
  /** Tests: the child profiles signed in on this phone (default: this device's sessions). */
  phoneChildIds?: readonly number[];
}

export default function TimezoneSuggestionBanner(props: TimezoneSuggestionBannerProps) {
  const stored = usePhoneChildIds();
  return <Banner {...props} phoneChildIds={props.phoneChildIds ?? stored} />;
}

function Banner({ familyZone, pets, phoneChildIds }: TimezoneSuggestionBannerProps & { phoneChildIds: readonly number[] }) {
  const phoneZone = usePhoneZone();
  const { loaded, dismissedZone } = useZoneSuggestionDismissal();
  const update = useUpdatePetTimezone();
  const [unchecked, setUnchecked] = useState<Set<number>>(new Set());
  const [checkedOthers, setCheckedOthers] = useState<Set<number>>(new Set());
  const [confirming, setConfirming] = useState(false);
  const [error, setError] = useState<TimezoneChangeError | null>(null);
  const [busy, setBusy] = useState(false);

  const mine = useMemo(() => phonePetIds(pets, phoneChildIds), [pets, phoneChildIds]);
  const candidates = useMemo(
    () => petZoneSuggestion({ pets, familyZone, phoneZone, phonePetIds: mine, dismissedZone, atMs: Date.now() }),
    [pets, familyZone, phoneZone, mine, dismissedZone],
  );
  if (!loaded || candidates.length === 0 || phoneZone === null) return null;

  const language = currentLanguage();
  const byId = new Map(pets.map((p) => [p.id, p]));
  const isChecked = (petId: number, preselected: boolean) => (preselected ? !unchecked.has(petId) : checkedOthers.has(petId));
  const toggle = (petId: number, preselected: boolean) => {
    const flip = (set: Set<number>) => {
      const next = new Set(set);
      if (next.has(petId)) next.delete(petId);
      else next.add(petId);
      return next;
    };
    if (preselected) setUnchecked(flip);
    else setCheckedOthers(flip);
  };
  const chosen = candidates.filter((c) => isChecked(c.petId, c.preselected));
  const phoneCity = zoneCity(phoneZone, language);
  const single = candidates.length === 1 ? candidates[0] : null;
  const singlePet = single ? byId.get(single.petId) : undefined;

  const apply = async () => {
    if (busy || chosen.length === 0) return;
    setBusy(true);
    setError(null);
    try {
      // One by one: the server limits each pet separately; the first refusal stops here.
      for (const c of chosen) {
        await update.mutateAsync({ petId: c.petId, timezone: phoneZone });
      }
      setConfirming(false);
    } catch (err) {
      setError(readTimezoneChangeError(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <View style={styles.banner} testID="zone-suggestion" accessibilityLiveRegion="polite">
      {single && singlePet ? (
        <Text style={styles.text} testID="zone-suggestion-text">
          {S.petBanner(phoneCity, bannerPetName(singlePet), zoneCity(single.zone, language))}
        </Text>
      ) : (
        <>
          <Text style={styles.text} testID="zone-suggestion-text">
            {S.petBannerList(phoneCity)}
          </Text>
          {candidates.map((c) => {
            const pet = byId.get(c.petId);
            if (!pet) return null;
            const checked = isChecked(c.petId, c.preselected);
            return (
              <Pressable
                key={c.petId}
                style={({ pressed }) => [styles.option, pressed && styles.pressed]}
                onPress={() => toggle(c.petId, c.preselected)}
                disabled={busy}
                accessibilityRole="checkbox"
                accessibilityState={{ checked, disabled: busy }}
                testID={`zone-suggestion-pet-${c.petId}`}
              >
                <View style={[styles.box, checked && styles.boxOn]}>{checked && <Check color={palette.white} size={14} />}</View>
                <Text style={styles.optionText}>{S.petBannerRow(bannerPetName(pet), zoneCity(c.zone, language))}</Text>
              </Pressable>
            );
          })}
        </>
      )}
      {confirming && (
        <Text style={styles.warning} testID="zone-suggestion-warning">
          {S.warning}
        </Text>
      )}
      {error !== null && (
        <Text style={styles.error} accessibilityRole="alert" testID="zone-suggestion-error">
          {timezoneErrorText(error, chosen[0]?.zone ?? familyZone, Date.now())}
        </Text>
      )}
      {!single && chosen.length === 0 && <Text style={styles.muted}>{S.petBannerNone}</Text>}
      <View style={styles.actions}>
        <Pressable
          style={({ pressed }) => [styles.secondary, pressed && styles.pressed]}
          onPress={() => (confirming ? setConfirming(false) : dismissZoneSuggestion(phoneZone))}
          disabled={busy}
          accessibilityRole="button"
          testID={confirming ? 'zone-suggestion-back' : 'zone-suggestion-dismiss'}
        >
          <Text style={styles.secondaryText}>{confirming ? S.back : S.bannerClose}</Text>
        </Pressable>
        <Pressable
          style={({ pressed }) => [styles.primary, (busy || chosen.length === 0) && styles.disabled, pressed && styles.pressed]}
          onPress={() => (confirming ? void apply() : setConfirming(true))}
          disabled={busy || chosen.length === 0}
          accessibilityRole="button"
          accessibilityState={{ disabled: busy || chosen.length === 0, busy }}
          testID={confirming ? 'zone-suggestion-confirm' : 'zone-suggestion-change'}
        >
          {busy ? <ActivityIndicator color={C.card} /> : <Text style={styles.primaryText}>{confirming ? S.confirm : S.bannerAction}</Text>}
        </Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  banner: { borderRadius: 16, padding: 14, gap: 10, backgroundColor: C.yellowSoft, borderWidth: 1, borderColor: C.border },
  text: { fontSize: 14, lineHeight: 20, color: C.text, fontWeight: '600' },
  muted: { fontSize: 13, color: C.muted },
  warning: { fontSize: 13, lineHeight: 18, color: C.text },
  error: { fontSize: 13, lineHeight: 18, color: C.redText },
  option: { flexDirection: 'row', alignItems: 'center', gap: 10, minHeight: MIN_TOUCH },
  box: {
    width: 22,
    height: 22,
    borderRadius: 6,
    borderWidth: 2,
    borderColor: C.accent,
    alignItems: 'center',
    justifyContent: 'center',
  },
  boxOn: { backgroundColor: C.accent },
  optionText: { flex: 1, fontSize: 14, color: C.text },
  actions: { flexDirection: 'row', justifyContent: 'flex-end', gap: 10 },
  primary: {
    minHeight: MIN_TOUCH,
    paddingHorizontal: 16,
    borderRadius: 14,
    backgroundColor: C.accent,
    alignItems: 'center',
    justifyContent: 'center',
  },
  primaryText: { fontSize: 14, fontWeight: '700', color: C.card },
  secondary: { minHeight: MIN_TOUCH, paddingHorizontal: 12, alignItems: 'center', justifyContent: 'center' },
  secondaryText: { fontSize: 14, fontWeight: '600', color: C.muted },
  disabled: { opacity: 0.5 },
  pressed: { opacity: 0.85 },
});
