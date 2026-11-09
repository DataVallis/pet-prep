/**
 * »Časovni pas družine« / »Family time zone« (David 2026-11-08, »kar B«) — Nadzor, right above
 * the quiet hours. Since David 2026-11-09 it is the DEFAULT zone of the pets without their own;
 * each pet has its own »Časovni pas« row (`PetTimezoneRow`, in its `PetControlsCard`) with the
 * same sheet plus »Po družini« on top (= back to the family zone,
 * `PUT /api/parent/pets/{id}/timezone`).
 *
 * Shows the zone as a city + UTC offset; »Spremeni« opens a sheet: step 1 = the phone's current
 * zone first, then a searchable list of IANA zones; step 2 = the warning (midnight / meals /
 * quiet hours / step day move, today shorter or longer, allowed during the challenge, once per
 * 24 h) and »Zamenjaj« → `PUT /api/parent/settings` (family) or the pet endpoint. Not
 * optimistic: the dashboard (+ quiet hours) is refetched when the server has saved it.
 * Light parent theme (ADR-007).
 */

import { useContext, useMemo, useState } from 'react';
import { ActivityIndicator, FlatList, KeyboardAvoidingView, Modal, Platform, Pressable, StyleSheet, View } from 'react-native';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';
import { Check, Globe, Smartphone, Users, X } from 'lucide-react-native';

import { Text, TextInput } from '@/components/ui/Text';
import { Card, PARENT_COLORS as C, SectionTitle } from '@/components/parent/ParentUi';
import { useUpdateFamilyTimezone, useUpdatePetTimezone } from '@/hooks/queries/useParentQueries';
import { currentLanguage } from '@/i18n';
import {
  allZones,
  currentPhoneZone,
  followsFamily,
  petZoneOf,
  readTimezoneChangeError,
  zoneCity,
  zoneChoices,
  zoneLabel,
  type TimezoneChangeError,
  type ZonePet,
} from '@/modules/familyZone/familyZone';
import { FAMILY_ZONE_STRINGS as S, timezoneErrorText } from '@/modules/familyZone/strings';
import { petLabel } from '@/modules/petName/petName';
import { MIN_TOUCH, alpha, fonts, palette, radius, tightTracking } from '@/theme';

/** The sheet's choice: an IANA zone, or FOLLOW_FAMILY (a pet back to the family zone). */
const FOLLOW_FAMILY = 'follow-family';

interface SaveCallbacks {
  onSuccess: () => void;
  onError: (err: unknown) => void;
}

interface SheetProps {
  /** The zone the clock reads now (the family's, or the pet's effective zone). */
  currentZone: string;
  /** Pet sheet only: the family zone offered as »Po družini«, and whether the pet follows it. */
  family?: { zone: string; followed: boolean };
  busy: boolean;
  /** Save the choice (null = follow the family zone) and report the outcome. */
  save: (zone: string | null, done: SaveCallbacks) => void;
  onClose: () => void;
  onSaved: () => void;
}

function ZoneSheet({ currentZone, family, busy, save, onClose, onSaved }: SheetProps) {
  const [query, setQuery] = useState('');
  const [picked, setPicked] = useState<string | null>(null);
  const [error, setError] = useState<TimezoneChangeError | null>(null);
  const bottomInset = useContext(SafeAreaInsetsContext)?.bottom ?? 0;
  const language = currentLanguage();
  const now = Date.now();
  const phoneZone = currentPhoneZone();
  const zones = useMemo(() => allZones(), []);
  const rows = useMemo(() => {
    const list = zoneChoices({ phoneZone, query, zones, language });
    return family !== undefined && query.trim() === '' ? [FOLLOW_FAMILY, ...list] : list;
  }, [phoneZone, query, zones, language, family]);
  const pickedZone = picked === FOLLOW_FAMILY ? (family?.zone ?? currentZone) : picked;

  const confirm = () => {
    if (picked === null || busy) return;
    setError(null);
    save(picked === FOLLOW_FAMILY ? null : picked, {
      onSuccess: onSaved,
      onError: (err) => setError(readTimezoneChangeError(err)),
    });
  };

  return (
    <KeyboardAvoidingView style={styles.backdrop} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <Pressable
        style={StyleSheet.absoluteFill}
        onPress={busy ? undefined : onClose}
        accessible={false}
        importantForAccessibility="no"
        testID="family-zone-sheet-backdrop"
      />
      <View style={[styles.sheet, { paddingBottom: 20 + bottomInset }]} testID="family-zone-sheet" accessibilityViewIsModal>
        <View style={styles.headerRow}>
          <Text style={styles.title} accessibilityRole="header">
            {picked === null ? S.sheetTitle : S.confirmTitle}
          </Text>
          <Pressable
            onPress={onClose}
            disabled={busy}
            accessibilityRole="button"
            accessibilityLabel={S.close}
            style={({ pressed }) => [styles.close, pressed && styles.pressed]}
            testID="family-zone-sheet-close"
          >
            <X color={C.text} size={20} />
          </Pressable>
        </View>

        {picked === null ? (
          <>
            <TextInput
              style={styles.input}
              value={query}
              onChangeText={setQuery}
              placeholder={S.search}
              placeholderTextColor={palette.n500}
              autoCorrect={false}
              autoComplete="off"
              accessibilityLabel={S.search}
              testID="family-zone-search"
            />
            <FlatList
              style={styles.list}
              data={rows}
              keyExtractor={(zone) => zone}
              keyboardShouldPersistTaps="handled"
              initialNumToRender={20}
              ListEmptyComponent={<Text style={styles.muted}>{S.noResults}</Text>}
              renderItem={({ item }) => {
                if (item === FOLLOW_FAMILY && family !== undefined) {
                  return (
                    <Pressable
                      style={({ pressed }) => [styles.zoneRow, pressed && styles.pressed]}
                      onPress={() => (family.followed ? onClose() : setPicked(FOLLOW_FAMILY))}
                      accessibilityRole="button"
                      accessibilityState={{ selected: family.followed }}
                      testID="family-zone-option-follow-family"
                    >
                      <Users color={C.accent} size={18} />
                      <View style={styles.flex}>
                        <Text style={styles.zoneName}>{S.followFamilyOption(zoneCity(family.zone, language))}</Text>
                        <Text style={styles.zoneId}>{S.followFamilyHint}</Text>
                      </View>
                      {family.followed && (
                        <View style={styles.currentRow}>
                          <Check color={C.greenText} size={16} />
                          <Text style={styles.currentText}>{S.current}</Text>
                        </View>
                      )}
                    </Pressable>
                  );
                }
                const isPhone = item === phoneZone;
                const isCurrent = item === currentZone && family?.followed !== true;
                return (
                  <Pressable
                    style={({ pressed }) => [styles.zoneRow, pressed && styles.pressed]}
                    onPress={() => (isCurrent ? onClose() : setPicked(item))}
                    accessibilityRole="button"
                    accessibilityState={{ selected: isCurrent }}
                    testID={`family-zone-option-${item}`}
                  >
                    {isPhone ? <Smartphone color={C.accent} size={18} /> : <Globe color={C.muted} size={18} />}
                    <View style={styles.flex}>
                      <Text style={styles.zoneName}>{zoneLabel(item, language, now)}</Text>
                      <Text style={styles.zoneId}>{isPhone ? `${S.phone} · ${item}` : item}</Text>
                    </View>
                    {isCurrent && (
                      <View style={styles.currentRow}>
                        <Check color={C.greenText} size={16} />
                        <Text style={styles.currentText}>{S.current}</Text>
                      </View>
                    )}
                  </Pressable>
                );
              }}
            />
          </>
        ) : (
          <>
            <Text style={styles.change} testID="family-zone-confirm-target">
              {`${zoneCity(currentZone, language)} → ${zoneLabel(pickedZone ?? currentZone, language, now)}`}
            </Text>
            <View style={styles.warning} accessibilityRole="alert">
              <Text style={styles.warningText} testID="family-zone-warning">
                {S.warning}
              </Text>
            </View>
            {error !== null && (
              <Text style={styles.error} accessibilityRole="alert" accessibilityLiveRegion="polite" testID="family-zone-error">
                {timezoneErrorText(error, currentZone, now)}
              </Text>
            )}
            <Pressable
              style={({ pressed }) => [styles.primary, busy && styles.disabled, pressed && styles.pressed]}
              onPress={confirm}
              disabled={busy}
              accessibilityRole="button"
              accessibilityState={{ disabled: busy, busy }}
              testID="family-zone-confirm"
            >
              {busy ? <ActivityIndicator color={palette.white} /> : <Text style={styles.primaryText}>{S.confirm}</Text>}
            </Pressable>
            <Pressable
              style={({ pressed }) => [styles.tertiary, busy && styles.disabled, pressed && styles.pressed]}
              onPress={() => {
                setPicked(null);
                setError(null);
              }}
              disabled={busy}
              accessibilityRole="button"
              testID="family-zone-back"
            >
              <Text style={styles.tertiaryText}>{S.back}</Text>
            </Pressable>
          </>
        )}
      </View>
    </KeyboardAvoidingView>
  );
}

interface FamilyTimezoneCardProps {
  /** `family.timezone` from the dashboard; null while it loads (row shown, »Spremeni« off). */
  familyZone: string | null;
}

export default function FamilyTimezoneCard({ familyZone }: FamilyTimezoneCardProps) {
  const [open, setOpen] = useState(false);
  const [saved, setSaved] = useState(false);
  const update = useUpdateFamilyTimezone();
  const language = currentLanguage();

  // Never closed while a save runs (backdrop, ✕ and the Android back button alike).
  const close = () => {
    if (!update.isPending) setOpen(false);
  };

  return (
    <Card testID="family-zone">
      <SectionTitle>{S.title}</SectionTitle>
      <View style={styles.row}>
        <Text style={[styles.value, styles.flex]} testID="family-zone-value">
          {familyZone !== null ? zoneLabel(familyZone, language, Date.now()) : '—'}
        </Text>
        <Pressable
          style={({ pressed }) => [styles.editButton, familyZone === null && styles.disabled, pressed && styles.pressed]}
          onPress={() => {
            setSaved(false);
            setOpen(true);
          }}
          disabled={familyZone === null}
          accessibilityRole="button"
          accessibilityLabel={S.changeA11y}
          accessibilityState={{ disabled: familyZone === null }}
          testID="family-zone-change"
        >
          <Text style={styles.editText}>{S.change}</Text>
        </Pressable>
      </View>
      <Text style={styles.muted}>{S.hint}</Text>
      {saved && (
        <Text style={styles.saved} accessibilityLiveRegion="polite" testID="family-zone-saved">
          {S.saved}
        </Text>
      )}
      <Modal visible={open && familyZone !== null} transparent animationType="slide" onRequestClose={close} statusBarTranslucent>
        {open && familyZone !== null && (
          <ZoneSheet
            currentZone={familyZone}
            busy={update.isPending}
            save={(zone, done) => {
              if (zone !== null) update.mutate(zone, done);
            }}
            onClose={close}
            onSaved={() => {
              setOpen(false);
              setSaved(true);
            }}
          />
        )}
      </Modal>
    </Card>
  );
}

interface PetTimezoneRowProps {
  pet: ZonePet & { name?: unknown; breed_type: string; species?: string | null };
  /** `family.timezone` (the default); null while it loads. */
  familyZone: string | null;
}

/**
 * »Časovni pas« of one pet (David 2026-11-09) in its Nadzor card: the effective zone
 * (»Lizbona · UTC+0«), with »· po družini« while it follows the family; »Spremeni« = the same
 * sheet with »Po družini« on top.
 */
export function PetTimezoneRow({ pet, familyZone }: PetTimezoneRowProps) {
  const [open, setOpen] = useState(false);
  const [saved, setSaved] = useState(false);
  const update = useUpdatePetTimezone();
  const language = currentLanguage();
  const zone = petZoneOf(pet, familyZone);
  const follows = followsFamily(pet);
  const name = petLabel(pet);
  const close = () => {
    if (!update.isPending) setOpen(false);
  };
  const label = zone === null ? '—' : zoneLabel(zone, language, Date.now());

  return (
    <View style={styles.petRow} testID={`pet-zone-${pet.id}`}>
      <Text style={styles.petLabel}>{S.petTitle}</Text>
      <View style={styles.row}>
        <Text style={[styles.petValue, styles.flex]} testID={`pet-zone-value-${pet.id}`}>
          {zone !== null && follows ? `${label} · ${S.followsFamily}` : label}
        </Text>
        <Pressable
          style={({ pressed }) => [styles.editButton, zone === null && styles.disabled, pressed && styles.pressed]}
          onPress={() => {
            setSaved(false);
            setOpen(true);
          }}
          disabled={zone === null}
          accessibilityRole="button"
          accessibilityLabel={S.petChangeA11y(name)}
          accessibilityState={{ disabled: zone === null }}
          testID={`pet-zone-change-${pet.id}`}
        >
          <Text style={styles.editText}>{S.change}</Text>
        </Pressable>
      </View>
      {saved && (
        <Text style={styles.saved} accessibilityLiveRegion="polite" testID={`pet-zone-saved-${pet.id}`}>
          {S.petSaved(name)}
        </Text>
      )}
      <Modal visible={open && zone !== null} transparent animationType="slide" onRequestClose={close} statusBarTranslucent>
        {open && zone !== null && (
          <ZoneSheet
            currentZone={zone}
            family={familyZone !== null ? { zone: familyZone, followed: follows } : undefined}
            busy={update.isPending}
            save={(next, done) => update.mutate({ petId: pet.id, timezone: next }, done)}
            onClose={close}
            onSaved={() => {
              setOpen(false);
              setSaved(true);
            }}
          />
        )}
      </Modal>
    </View>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  value: { fontSize: 16, fontWeight: '700', color: C.text },
  petRow: { gap: 6, paddingTop: 10, borderTopWidth: 1, borderTopColor: C.divider },
  petLabel: { fontSize: 13, fontWeight: '700', color: C.muted },
  petValue: { fontSize: 15, fontWeight: '700', color: C.text },
  muted: { fontSize: 13, color: C.muted, lineHeight: 18 },
  saved: { fontSize: 13, color: C.greenText },
  editButton: {
    minHeight: MIN_TOUCH,
    paddingHorizontal: 14,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: C.border,
    backgroundColor: C.bg,
    alignItems: 'center',
    justifyContent: 'center',
  },
  editText: { fontSize: 14, fontWeight: '700', color: C.accent },
  backdrop: { flex: 1, justifyContent: 'flex-end', backgroundColor: alpha(palette.black, 0.4) },
  sheet: {
    maxHeight: '85%',
    paddingHorizontal: 20,
    paddingTop: 16,
    gap: 10,
    borderTopLeftRadius: radius.sheet,
    borderTopRightRadius: radius.sheet,
    backgroundColor: C.card,
  },
  headerRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12 },
  title: { flex: 1, fontFamily: fonts.displayBold, fontSize: 20, letterSpacing: tightTracking(20), color: C.text },
  close: { width: MIN_TOUCH, height: MIN_TOUCH, alignItems: 'center', justifyContent: 'center' },
  input: {
    height: 48,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: palette.n300,
    backgroundColor: palette.fog,
    paddingHorizontal: 14,
    fontSize: 16,
    color: palette.graphite,
  },
  list: { flexGrow: 0 },
  zoneRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    minHeight: MIN_TOUCH + 8,
    paddingVertical: 8,
    borderBottomWidth: 1,
    borderBottomColor: C.divider,
  },
  zoneName: { fontSize: 15, fontWeight: '600', color: C.text },
  zoneId: { fontSize: 12, color: C.muted },
  currentRow: { flexDirection: 'row', alignItems: 'center', gap: 4 },
  currentText: { fontSize: 12, fontWeight: '700', color: C.greenText },
  change: { fontSize: 16, fontWeight: '700', color: C.text },
  warning: { borderRadius: 12, padding: 12, backgroundColor: C.yellowSoft },
  warningText: { fontSize: 14, lineHeight: 20, color: C.text },
  error: { fontSize: 13, lineHeight: 18, color: C.redText },
  primary: {
    height: 50,
    borderRadius: 14,
    backgroundColor: palette.graphite,
    alignItems: 'center',
    justifyContent: 'center',
  },
  primaryText: { fontSize: 16, fontWeight: '700', color: palette.white },
  tertiary: { minHeight: MIN_TOUCH, alignItems: 'center', justifyContent: 'center' },
  tertiaryText: { fontSize: 15, fontWeight: '600', color: C.muted },
  disabled: { opacity: 0.5 },
  pressed: { opacity: 0.85 },
});
