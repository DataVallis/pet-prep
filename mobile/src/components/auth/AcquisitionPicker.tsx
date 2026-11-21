/**
 * M5-08 »Kje ste izvedeli za PetPrep?« — optional single choice (10 chips) + »Kje? (ali koda)«
 * for »Oglas« / »Drugo« (≤ 60 chars). Shared by the e-mail sign-up, the Google / Apple terms sheet
 * and the dashboard card (light parent / auth style). Never required: tapping the chosen option
 * again clears it. Rules in `modules/validation/acquisition.ts`.
 */

import { Pressable, StyleSheet, View } from 'react-native';

import { Text, TextInput } from '@/components/ui/Text';
import type { AcquisitionSource } from '@/api/client';
import {
  ACQUISITION_DETAIL_MAX,
  ACQUISITION_SOURCES,
  ACQUISITION_STRINGS as S,
  sourceTakesDetail,
  toggleSource,
  type AcquisitionChoice,
} from '@/modules/validation/acquisition';
import { light, MIN_TOUCH, palette, radius } from '@/theme';

interface AcquisitionPickerProps {
  value: AcquisitionChoice;
  onChange: (next: AcquisitionChoice) => void;
  disabled?: boolean;
  /** The card shows its own heading. */
  showTitle?: boolean;
  testID: string;
}

export default function AcquisitionPicker({ value, onChange, disabled = false, showTitle = true, testID }: AcquisitionPickerProps) {
  const label = (source: AcquisitionSource) => S.options[source];
  return (
    <View style={styles.wrap} testID={testID}>
      {showTitle && (
        <Text style={styles.title} accessibilityRole="header">
          {S.title} <Text style={styles.optional}>({S.optional})</Text>
        </Text>
      )}
      <View style={styles.chips} accessibilityRole="radiogroup" accessibilityLabel={S.title}>
        {ACQUISITION_SOURCES.map((source) => {
          const selected = value.source === source;
          return (
            <Pressable
              key={source}
              onPress={() => onChange(toggleSource(value, source))}
              disabled={disabled}
              style={({ pressed }) => [styles.chip, selected && styles.chipOn, pressed && styles.pressed]}
              accessibilityRole="radio"
              accessibilityState={{ checked: selected, disabled }}
              accessibilityLabel={label(source)}
              testID={`${testID}-${source}`}
            >
              <Text style={[styles.chipText, selected && styles.chipTextOn]}>{label(source)}</Text>
            </Pressable>
          );
        })}
      </View>
      {sourceTakesDetail(value.source) && (
        <TextInput
          style={styles.input}
          value={value.detail}
          onChangeText={(detail) => onChange({ ...value, detail })}
          placeholder={S.detailPlaceholder}
          placeholderTextColor={light.inkFaint}
          accessibilityLabel={S.detailPlaceholder}
          maxLength={ACQUISITION_DETAIL_MAX}
          autoCorrect={false}
          editable={!disabled}
          testID={`${testID}-detail`}
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { gap: 10 },
  title: { fontSize: 14, fontWeight: '600', color: light.ink },
  optional: { fontSize: 13, fontWeight: '400', color: light.inkMuted },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  chip: {
    minHeight: MIN_TOUCH,
    paddingHorizontal: 14,
    justifyContent: 'center',
    borderRadius: radius.button,
    borderWidth: 1,
    borderColor: light.border,
    backgroundColor: light.surface,
  },
  chipOn: { backgroundColor: palette.mintSoft, borderColor: palette.mintBorder },
  chipText: { fontSize: 14, color: light.ink },
  chipTextOn: { fontWeight: '600' },
  input: {
    height: 48,
    paddingHorizontal: 14,
    borderRadius: radius.button,
    borderWidth: 1,
    borderColor: light.border,
    backgroundColor: light.surface,
    fontSize: 15,
    color: light.ink,
  },
  pressed: { opacity: 0.85 },
});
