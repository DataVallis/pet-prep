/**
 * »Zapis ure« in Nadzor (David 2026-11-16, choice 2: follow the phone + manual override):
 * Samodejno / 24-urni / 12-urni. Applies at once to every clock time in the app (the
 * whole tree re-renders through `useTimeFormat`), is remembered on this device and saved on
 * the server (`PUT /api/user/time-format`) so pushes use the same format. A failed save
 * keeps the choice on this phone and says so.
 */

import { useState } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';

import { api } from '@/api/client';
import { Card, PARENT_COLORS as C, SectionTitle, parentStyles } from '@/components/parent/ParentUi';
import { Text } from '@/components/ui/Text';
import { t } from '@/i18n';
import { MIN_TOUCH, radius } from '@/theme';
import { TIME_FORMATS, clockText, resolveHour12, saveTimeFormat, useTimeFormat, type TimeFormat } from '@/modules/time/clock';

const LABEL_KEYS: Record<TimeFormat, 'common:clock.auto' | 'common:clock.h24' | 'common:clock.h12'> = {
  auto: 'common:clock.auto',
  '24h': 'common:clock.h24',
  '12h': 'common:clock.h12',
};

export default function TimeFormatCard() {
  const current = useTimeFormat();
  const [failed, setFailed] = useState(false);

  const choose = (format: TimeFormat) => {
    if (format === current) return;
    setFailed(false);
    void saveTimeFormat(format);
    api.updateTimeFormat(format).catch(() => setFailed(true));
  };

  return (
    <Card testID="time-format-card">
      <SectionTitle>{t('common:clock.title')}</SectionTitle>
      <View style={styles.row} accessibilityRole="radiogroup" accessibilityLabel={t('common:clock.label')}>
        {TIME_FORMATS.map((format) => {
          const selected = format === current;
          // Example under each option: "17:30" / "5:30 PM" (the auto one = this phone).
          const example = clockText('17:30', resolveHour12(format));
          return (
            <Pressable
              key={format}
              onPress={() => choose(format)}
              style={({ pressed }) => [styles.pill, selected && styles.pillActive, pressed && styles.pressed]}
              accessibilityRole="radio"
              accessibilityState={{ selected }}
              accessibilityLabel={`${t(LABEL_KEYS[format])}, ${example}`}
              testID={`time-format-${format}`}
            >
              <Text style={[styles.text, selected && styles.textActive]}>{t(LABEL_KEYS[format])}</Text>
              <Text style={[styles.example, selected && styles.textActive]}>{example}</Text>
            </Pressable>
          );
        })}
      </View>
      <Text style={parentStyles.muted}>{t('common:clock.hint')}</Text>
      {failed && (
        <Text style={styles.error} testID="time-format-error" accessibilityRole="alert">
          {t('common:clock.saveError')}
        </Text>
      )}
    </Card>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', gap: 8, marginVertical: 8, flexWrap: 'wrap' },
  pill: {
    flexGrow: 1,
    minHeight: MIN_TOUCH,
    minWidth: 90,
    paddingHorizontal: 10,
    paddingVertical: 6,
    borderRadius: radius.button,
    borderWidth: 1,
    borderColor: C.border,
    backgroundColor: C.bg,
    alignItems: 'center',
    justifyContent: 'center',
  },
  pillActive: { backgroundColor: C.accent, borderColor: C.accent },
  text: { fontSize: 14, fontWeight: '700', color: C.text },
  example: { fontSize: 12, color: C.muted },
  textActive: { color: C.card },
  error: { fontSize: 13, color: C.redText },
  pressed: { opacity: 0.85 },
});
