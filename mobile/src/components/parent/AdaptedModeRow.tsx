/**
 * M5-T12 »Prilagojen način« / "Adapted mode" — a parent-only switch per child profile
 * (APP_REFINEMENT_SPEC §6, David 2026-10-20): the child's walk counts wheelchair pushes
 * instead of steps (1 push = 1 step; goals and the Care Score stay the same).
 *
 * Accessibility, not a diagnosis: the row asks for nothing but on / off and says so; only
 * the boolean goes to `PATCH /api/parent/children/{child}/settings`. The value shown is
 * the server's (`family.children[].adapted_mode`) — while saving, the switch shows the
 * requested value; a failure puts it back and shows a short error.
 */

import { useState } from 'react';
import { StyleSheet, Switch, View } from 'react-native';
import { Text } from '@/components/ui/Text';
import { Accessibility } from 'lucide-react-native';

import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { useSetAdaptedMode } from '@/hooks/queries/useFamilyMutations';
import { palette } from '@/theme';
import { strings } from '@/i18n/strings';

/** All user-visible strings (`family:children.adapted`). */
export const ADAPTED_MODE_STRINGS = strings('family', 'children', {});

interface AdaptedModeRowProps {
  childId: number;
  childName: string;
  /** The server's value. */
  enabled: boolean;
}

export default function AdaptedModeRow({ childId, childName, enabled }: AdaptedModeRowProps) {
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
      {shown && <Text style={styles.hint} testID={`adapted-mode-native-${childId}`}>{S.native}</Text>}
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
});
