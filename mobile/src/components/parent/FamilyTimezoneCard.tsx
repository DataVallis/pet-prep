/**
 * »Časovni pas družine« / »Family time zone« (David 2026-11-08, »kar B«) — Nadzor, right above
 * the quiet hours. Shows the family zone as a city + UTC offset; »Spremeni« opens a sheet:
 * step 1 = the phone's current zone first, then a searchable list of IANA zones; step 2 = the
 * warning (midnight / meals / quiet hours / step day move, today shorter or longer, allowed
 * during the challenge, once per 24 h) and »Zamenjaj« → `PUT /api/parent/settings`.
 * Not optimistic: the dashboard + quiet hours are refetched when the server has saved it.
 * Light parent theme (ADR-007).
 */

import { useContext, useEffect, useMemo, useState } from 'react';
import { ActivityIndicator, FlatList, KeyboardAvoidingView, Modal, Platform, Pressable, StyleSheet, View } from 'react-native';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';
import { Check, Globe, Smartphone, X } from 'lucide-react-native';

import { Text, TextInput } from '@/components/ui/Text';
import { Card, PARENT_COLORS as C, SectionTitle } from '@/components/parent/ParentUi';
import { useUpdateFamilyTimezone } from '@/hooks/queries/useParentQueries';
import { currentLanguage } from '@/i18n';
import {
  allZones,
  currentPhoneZone,
  readTimezoneChangeError,
  zoneCity,
  zoneChoices,
  zoneLabel,
  type TimezoneChangeError,
} from '@/modules/familyZone/familyZone';
import { FAMILY_ZONE_STRINGS as S, timezoneErrorText } from '@/modules/familyZone/strings';
import { MIN_TOUCH, alpha, fonts, palette, radius, tightTracking } from '@/theme';

type UpdateMutation = ReturnType<typeof useUpdateFamilyTimezone>;

interface SheetProps {
  familyZone: string;
  update: UpdateMutation;
  onClose: () => void;
  onSaved: () => void;
}

function ZoneSheet({ familyZone, update, onClose, onSaved }: SheetProps) {
  const [query, setQuery] = useState('');
  const [picked, setPicked] = useState<string | null>(null);
  const [error, setError] = useState<TimezoneChangeError | null>(null);
  const bottomInset = useContext(SafeAreaInsetsContext)?.bottom ?? 0;
  const busy = update.isPending;
  const language = currentLanguage();
  const now = Date.now();
  const phoneZone = currentPhoneZone();
  const zones = useMemo(() => allZones(), []);
  const rows = useMemo(() => zoneChoices({ phoneZone, query, zones, language }), [phoneZone, query, zones, language]);

  const confirm = () => {
    if (picked === null || busy) return;
    setError(null);
    update.mutate(picked, {
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
                const isPhone = item === phoneZone;
                const isCurrent = item === familyZone;
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
              {`${zoneCity(familyZone, language)} → ${zoneLabel(picked, language, now)}`}
            </Text>
            <View style={styles.warning} accessibilityRole="alert">
              <Text style={styles.warningText} testID="family-zone-warning">
                {S.warning}
              </Text>
            </View>
            {error !== null && (
              <Text style={styles.error} accessibilityRole="alert" accessibilityLiveRegion="polite" testID="family-zone-error">
                {timezoneErrorText(error, familyZone, now)}
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
  /** Bumped by the dashboard banner's »Zamenjam« — opens the sheet once per new value. */
  openRequest?: number;
  /** Called once the request opened the sheet (the owner resets it, so a later visit stays closed). */
  onOpenRequestHandled?: () => void;
}

export default function FamilyTimezoneCard({ familyZone, openRequest = 0, onOpenRequestHandled }: FamilyTimezoneCardProps) {
  const [open, setOpen] = useState(false);
  const [saved, setSaved] = useState(false);
  const update = useUpdateFamilyTimezone();
  const language = currentLanguage();

  useEffect(() => {
    if (openRequest > 0 && familyZone !== null) {
      setSaved(false);
      setOpen(true);
      onOpenRequestHandled?.();
    }
    // Only a new request opens the sheet (the callback's identity doesn't matter).
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [openRequest, familyZone]);

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
            familyZone={familyZone}
            update={update}
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

const styles = StyleSheet.create({
  flex: { flex: 1 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  value: { fontSize: 16, fontWeight: '700', color: C.text },
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
