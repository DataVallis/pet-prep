/**
 * The quiet-hours schedule editor (school + bedtime), shared by the family card
 * (`QuietHoursCard`) and the per-pet »Lastne ure« (`PetQuietHoursRow`) — David 2026-11-16.
 * No typing: each group has an on / off switch (off = no window, null times) and each
 * time is a field that opens the alarm-clock wheels (`TimeWheelPicker`). Values stay
 * "HH:MM" data; the field shows them with `clockText` (12 / 24 h).
 */

import { useState } from 'react';
import { Pressable, StyleSheet, Switch, View } from 'react-native';

import { Text } from '@/components/ui/Text';
import TimeWheelPicker from '@/components/time/TimeWheelPicker';
import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { palette, radius, MIN_TOUCH } from '@/theme';
import { t } from '@/i18n';
import { clockText, useTimeFormat } from '@/modules/time/clock';
import { strings } from '@/i18n/strings';
import type { QuietTimes } from '@/modules/quietHours/quietHours';

const S = strings('parent', 'quietHours');

/** Times a group gets when its switch is turned on. */
const GROUP_DEFAULTS = {
  school: ['08:00', '13:00'],
  bedtime: ['21:00', '07:00'],
} as const;

type Group = 'school' | 'bedtime';
type Field = 'school_start' | 'school_end' | 'bedtime_start' | 'bedtime_end';

interface TimeFieldProps {
  label: string;
  value: string;
  open: boolean;
  onToggle: () => void;
  testID: string;
}

/** One time: shows "8:00" / "8:00 AM"; tap opens / closes the wheels under it. */
export function TimeField({ label, value, open, onToggle, testID }: TimeFieldProps) {
  const text = clockText(value);
  return (
    <View style={styles.field}>
      <Pressable
        style={({ pressed }) => [styles.fieldButton, open && styles.fieldOpen, pressed && styles.pressed]}
        onPress={onToggle}
        accessibilityRole="button"
        accessibilityLabel={`${label}, ${text}`}
        accessibilityHint={t('common:clock.change')}
        accessibilityState={{ expanded: open }}
        testID={testID}
      >
        <Text style={styles.fieldLabel}>{label}</Text>
        <Text style={styles.fieldValue} testID={`${testID}-value`}>
          {text}
        </Text>
      </Pressable>
    </View>
  );
}

interface QuietHoursFormProps {
  value: QuietTimes;
  onChange: (next: QuietTimes) => void;
  /** testID prefix: `qh` (family) or `pqh-<petId>` (pet). */
  testID: string;
  /** Show the »Vklopljeno« switch for the whole schedule (the family card has it in its title). */
  showActive?: boolean;
}

export default function QuietHoursForm({ value, onChange, testID, showActive = false }: QuietHoursFormProps) {
  useTimeFormat();
  const [openField, setOpenField] = useState<Field | null>(null);

  const setTime = (field: Field, hhmm: string) => onChange({ ...value, [field]: hhmm });
  const setGroup = (group: Group, on: boolean) => {
    setOpenField(null);
    const [start, end] = GROUP_DEFAULTS[group];
    onChange({ ...value, [`${group}_start`]: on ? start : null, [`${group}_end`]: on ? end : null });
  };

  const renderGroup = (group: Group, title: string, short: string) => {
    const startKey = `${group}_start` as Field;
    const endKey = `${group}_end` as Field;
    const on = value[startKey] !== null || value[endKey] !== null;
    const fields: [Field, string, string][] = [
      [startKey, S.start, `${testID}-${short}-start`],
      [endKey, S.end, `${testID}-${short}-end`],
    ];
    return (
      <View style={styles.group}>
        <View style={styles.groupHeader}>
          <Text style={styles.groupTitle}>{title}</Text>
          <Switch
            value={on}
            onValueChange={(v) => setGroup(group, v)}
            trackColor={{ false: C.track, true: palette.mintDeep }}
            accessibilityLabel={title}
            testID={`${testID}-${short}-switch`}
          />
        </View>
        {on && (
          <>
            <View style={styles.row}>
              {fields.map(([field, label, id]) => (
                <TimeField
                  key={field}
                  label={label}
                  value={value[field] ?? GROUP_DEFAULTS[group][field.endsWith('start') ? 0 : 1]}
                  open={openField === field}
                  onToggle={() => setOpenField((cur) => (cur === field ? null : field))}
                  testID={id}
                />
              ))}
            </View>
            {fields.map(([field, , id]) =>
              openField === field ? (
                <TimeWheelPicker
                  key={field}
                  value={value[field] ?? GROUP_DEFAULTS[group][field.endsWith('start') ? 0 : 1]}
                  onChange={(v) => setTime(field, v)}
                  testID={`${id}-wheel`}
                />
              ) : null,
            )}
          </>
        )}
      </View>
    );
  };

  return (
    <View style={styles.root}>
      {showActive && (
        <View style={styles.groupHeader}>
          <Text style={styles.groupTitle}>{S.active}</Text>
          <Switch
            value={value.is_active}
            onValueChange={(v) => onChange({ ...value, is_active: v })}
            trackColor={{ false: C.track, true: palette.mintDeep }}
            accessibilityLabel={S.active}
            testID={`${testID}-active`}
          />
        </View>
      )}
      {renderGroup('school', S.school, 'school')}
      {renderGroup('bedtime', S.bedtime, 'bed')}
    </View>
  );
}

const styles = StyleSheet.create({
  root: { gap: 10 },
  group: { gap: 8 },
  groupHeader: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', minHeight: MIN_TOUCH },
  groupTitle: { fontSize: 13, fontWeight: '700', color: C.text },
  row: { flexDirection: 'row', gap: 10 },
  field: { flex: 1 },
  fieldButton: {
    minHeight: MIN_TOUCH + 12,
    borderRadius: radius.input,
    borderWidth: 1,
    borderColor: C.border,
    backgroundColor: C.bg,
    paddingHorizontal: 12,
    paddingVertical: 6,
    justifyContent: 'center',
  },
  fieldOpen: { borderColor: C.accent },
  fieldLabel: { fontSize: 12, color: C.muted },
  fieldValue: { fontSize: 17, fontWeight: '700', color: C.text },
  pressed: { opacity: 0.85 },
});
