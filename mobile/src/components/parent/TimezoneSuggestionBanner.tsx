/**
 * Parent dashboard banner (David 2026-11-08, »kar B«; parent only — never the child):
 * »Telefon je v drugem časovnem pasu (Lizbona) kot družina (Ljubljana). Zamenjam?« while the
 * phone's current UTC offset differs from the family zone's. »Zamenjam« opens the family time
 * zone setting (Nadzor); »Ne zdaj« hides it for THIS phone zone (device store) — it comes back
 * only in yet another zone. Re-checked when the app returns to the foreground (the phone may
 * have changed zone in the background).
 */

import { Pressable, StyleSheet, View } from 'react-native';

import { Text } from '@/components/ui/Text';
import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { currentLanguage } from '@/i18n';
import { suggestionVisible, zoneCity } from '@/modules/familyZone/familyZone';
import { usePhoneZone } from '@/modules/familyZone/usePhoneZone';
import { FAMILY_ZONE_STRINGS as S } from '@/modules/familyZone/strings';
import { dismissZoneSuggestion, useZoneSuggestionDismissal } from '@/modules/familyZone/zoneSuggestionPrefs';
import { MIN_TOUCH } from '@/theme';

interface TimezoneSuggestionBannerProps {
  familyZone: string | null;
  onChange: () => void;
}

export default function TimezoneSuggestionBanner({ familyZone, onChange }: TimezoneSuggestionBannerProps) {
  const phoneZone = usePhoneZone();
  const { loaded, dismissedZone } = useZoneSuggestionDismissal();
  if (!loaded || !suggestionVisible({ familyZone, phoneZone, dismissedZone, atMs: Date.now() })) return null;
  if (familyZone === null || phoneZone === null) return null;

  const language = currentLanguage();
  return (
    <View style={styles.banner} testID="zone-suggestion" accessibilityLiveRegion="polite">
      <Text style={styles.text}>{S.banner(zoneCity(phoneZone, language), zoneCity(familyZone, language))}</Text>
      <View style={styles.actions}>
        <Pressable
          style={({ pressed }) => [styles.secondary, pressed && styles.pressed]}
          onPress={() => dismissZoneSuggestion(phoneZone)}
          accessibilityRole="button"
          testID="zone-suggestion-dismiss"
        >
          <Text style={styles.secondaryText}>{S.bannerClose}</Text>
        </Pressable>
        <Pressable
          style={({ pressed }) => [styles.primary, pressed && styles.pressed]}
          onPress={onChange}
          accessibilityRole="button"
          testID="zone-suggestion-change"
        >
          <Text style={styles.primaryText}>{S.bannerAction}</Text>
        </Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  banner: { borderRadius: 16, padding: 14, gap: 10, backgroundColor: C.yellowSoft, borderWidth: 1, borderColor: C.border },
  text: { fontSize: 14, lineHeight: 20, color: C.text, fontWeight: '600' },
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
  pressed: { opacity: 0.85 },
});
