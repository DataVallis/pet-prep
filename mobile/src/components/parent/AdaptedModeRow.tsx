/**
 * M5-T12 »Prilagojen način« / "Adapted mode" — a parent-only switch per child profile
 * (APP_REFINEMENT_SPEC §6, David 2026-10-20): the child's walk counts wheelchair pushes
 * instead of steps (1 push = 1 step; goals and the Care Score stay the same).
 *
 * Accessibility, not a diagnosis: the row asks for nothing but on / off and says so; only
 * the boolean goes to `PATCH /api/parent/children/{child}/settings`. The value shown is
 * the server's (`family.children[].adapted_mode`) — while saving, the switch shows the
 * requested value; a failure puts it back and shows a short error.
 *
 * David 2026-10-26 (iPhone without an Apple Watch → 0 pushes for 2 days): while the switch is
 * on (also right after turning it on), a clear box says what counts pushes — on iPhone only an
 * Apple Watch with Wheelchair = Yes, on Android a device / app writing to Health Connect — and
 * that without one the parent can log walk minutes (»Vpiši sprehod« opens the child's page).
 * The parent's view doesn't know the child's phone OS, so both lines are shown.
 */

import { useState } from 'react';
import { Pressable, StyleSheet, Switch, View } from 'react-native';
import { Text } from '@/components/ui/Text';
import { Accessibility, Footprints, Info } from 'lucide-react-native';

import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { useSetAdaptedMode } from '@/hooks/queries/useFamilyMutations';
import { palette } from '@/theme';
import { strings } from '@/i18n/strings';
import { pushSourceLines } from '@/modules/steps/adaptedPushHint';

/** All user-visible strings (`family:children.adapted`). */
export const ADAPTED_MODE_STRINGS = strings('family', 'children', {});

interface AdaptedModeRowProps {
  childId: number;
  childName: string;
  /** The server's value. */
  enabled: boolean;
  /** Opens the child's page at »Vpiši sprehod«; without it the box shows no button. */
  onLogWalk?: () => void;
}

export default function AdaptedModeRow({ childId, childName, enabled, onLogWalk }: AdaptedModeRowProps) {
  const S = ADAPTED_MODE_STRINGS.adapted;
  const mutation = useSetAdaptedMode();
  const [failed, setFailed] = useState(false);
  const shown = mutation.isPending && mutation.variables ? mutation.variables.adaptedMode : enabled;

  const onChange = (next: boolean) => {
    setFailed(false);
    mutation.mutate(
      { childId, adaptedMode: next },
      {
        onError: () => setFailed(true),
      },
    );
  };

  return (
    <View style={styles.box} testID={`adapted-mode-${childId}`}>
      <View style={styles.row}>
        <Accessibility color={palette.graphite} size={16} />
        <Text style={styles.label}>{S.label}</Text>
        <Switch
          value={shown}
          onValueChange={onChange}
          disabled={mutation.isPending}
          trackColor={{ false: C.track, true: palette.mintDeep }}
          accessibilityLabel={`${S.label}, ${childName}`}
          accessibilityHint={S.hint}
          testID={`adapted-mode-switch-${childId}`}
        />
      </View>
      <Text style={styles.hint}>{S.hint}</Text>
      {/* QA M5-T12 / David 2026-10-21: the switch itself is the parent's explicit consent (GDPR Art. 9). */}
      <Text style={styles.hint} testID={`adapted-mode-consent-${childId}`}>
        {S.consent}
      </Text>
      {shown && (
        <View style={styles.sources} testID={`adapted-mode-sources-${childId}`}>
          <View style={styles.row}>
            <Info color={palette.graphite} size={15} />
            <Text style={styles.sourcesTitle}>{S.sources.title}</Text>
          </View>
          {pushSourceLines(null).map((line) => (
            <Text key={line} style={styles.sourceLine}>
              {line}
            </Text>
          ))}
          <Text style={styles.hint} testID={`adapted-mode-native-${childId}`}>
            {S.native}
          </Text>
          {onLogWalk && (
            <Pressable
              onPress={onLogWalk}
              accessibilityRole="button"
              style={({ pressed }) => [styles.linkButton, pressed && styles.pressed]}
              testID={`adapted-mode-log-walk-${childId}`}
            >
              <Footprints color={C.link} size={15} />
              <Text style={styles.linkText}>{S.sources.logWalk}</Text>
            </Pressable>
          )}
        </View>
      )}
      {failed && (
        <Text style={styles.error} testID={`adapted-mode-error-${childId}`}>
          {S.error}
        </Text>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  box: { gap: 4 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  label: { flex: 1, fontSize: 14, fontWeight: '700', color: palette.graphite },
  hint: { fontSize: 12, lineHeight: 17, color: palette.n600 },
  error: { fontSize: 12, color: palette.danger },
  sources: {
    gap: 6,
    marginTop: 4,
    padding: 10,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: C.accentBorder,
    backgroundColor: C.accentSoft,
  },
  sourcesTitle: { flex: 1, fontSize: 13, fontWeight: '700', color: palette.graphite },
  sourceLine: { fontSize: 13, lineHeight: 18, color: palette.graphite },
  linkButton: { flexDirection: 'row', alignItems: 'center', gap: 6, minHeight: 44, alignSelf: 'flex-start' },
  linkText: { fontSize: 14, fontWeight: '700', color: C.link },
  pressed: { opacity: 0.7 },
});
