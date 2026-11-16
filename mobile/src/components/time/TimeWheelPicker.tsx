/**
 * Alarm-clock time picker (David 2026-11-16, choice 3 »kolesa ura + minute«): hour wheel,
 * minute wheel (15-minute steps) and, in the 12 h format, an AM / PM wheel. Value in and
 * out is "HH:MM" (24 h data for the API); only the display follows `useTimeFormat()`.
 * Rules in `modules/time/wheel.ts`; each wheel in `WheelColumn` (screen-reader adjustable).
 */

import { StyleSheet, useWindowDimensions, View } from 'react-native';

import WheelColumn from '@/components/time/WheelColumn';
import { Text } from '@/components/ui/Text';
import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { t } from '@/i18n';
import { isHour12, useTimeFormat } from '@/modules/time/clock';
import {
  hour12Of,
  hourItems,
  minuteItems,
  periodItems,
  periodOf,
  toHHMM,
  toParts,
  withHour,
  withMinute,
  withPeriod,
} from '@/modules/time/wheel';

interface TimeWheelPickerProps {
  value: string;
  onChange: (hhmm: string) => void;
  testID: string;
}

/** Height below which the wheels show 3 rows instead of 5. */
const COMPACT_BELOW_HEIGHT = 700;

export default function TimeWheelPicker({ value, onChange, testID }: TimeWheelPickerProps) {
  useTimeFormat(); // re-render on a format change
  const hour12 = isHour12();
  const { height } = useWindowDimensions();
  const compact = height < COMPACT_BELOW_HEIGHT;
  const parts = toParts(value);
  const emit = (next: typeof parts) => {
    const hhmm = toHHMM(next);
    if (hhmm !== value) onChange(hhmm);
  };

  return (
    <View style={styles.row} testID={testID}>
      <WheelColumn
        items={hourItems(hour12)}
        value={hour12 ? hour12Of(parts.hour) : parts.hour}
        onChange={(h) => emit(withHour(parts, h, hour12))}
        accessibilityLabel={t('common:clock.hour')}
        compact={compact}
        testID={`${testID}-hour`}
      />
      <Text style={styles.colon} importantForAccessibility="no">
        :
      </Text>
      <WheelColumn
        items={minuteItems(parts.minute)}
        value={parts.minute}
        onChange={(m) => emit(withMinute(parts, m))}
        accessibilityLabel={t('common:clock.minute')}
        compact={compact}
        testID={`${testID}-minute`}
      />
      {hour12 && (
        <WheelColumn
          items={periodItems()}
          value={periodOf(parts.hour)}
          onChange={(p) => emit(withPeriod(parts, p))}
          accessibilityLabel={t('common:clock.period')}
          compact={compact}
          testID={`${testID}-period`}
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', gap: 4 },
  colon: { fontSize: 20, fontWeight: '700', color: C.text },
});
