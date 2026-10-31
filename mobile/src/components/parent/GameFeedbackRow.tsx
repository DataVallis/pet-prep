/**
 * »Zvoki in vibracija v igrah« / "Game sounds and vibration" — a parent-only switch per child
 * profile (GAMES_SPEC §7.6, David 2026-10-30 V5 / V11), next to »Lažje kretnje«. Default ON.
 *
 * On: the child's games on the native game layer play short sounds (they follow the phone's
 * silent mode) and a gentle vibration for good moments — never for a miss. Off: silent, no
 * vibration. Only the boolean goes to `PATCH /api/parent/children/{child}/settings`; the value
 * shown is the server's (`family.children[].game_feedback`) — while saving the switch shows the
 * requested value; a failure puts it back and shows a short error.
 */

import { useState } from 'react';
import { StyleSheet, Switch, View } from 'react-native';
import { Volume2 } from 'lucide-react-native';

import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { Text } from '@/components/ui/Text';
import { useSetGameFeedback } from '@/hooks/queries/useFamilyMutations';
import { strings } from '@/i18n/strings';
import { palette } from '@/theme';

/** All user-visible strings (`family:children.gameFeedback`). */
export const GAME_FEEDBACK_STRINGS = strings('family', 'children', {});

interface GameFeedbackRowProps {
  childId: number;
  childName: string;
  /** The server's value. */
  enabled: boolean;
}

export default function GameFeedbackRow({ childId, childName, enabled }: GameFeedbackRowProps) {
  const S = GAME_FEEDBACK_STRINGS.gameFeedback;
  const mutation = useSetGameFeedback();
  const [failed, setFailed] = useState(false);
  const shown = mutation.isPending && mutation.variables ? mutation.variables.gameFeedback : enabled;

  const onChange = (next: boolean) => {
    setFailed(false);
    mutation.mutate({ childId, gameFeedback: next }, { onError: () => setFailed(true) });
  };

  return (
    <View style={styles.box} testID={`game-feedback-${childId}`}>
      <View style={styles.row}>
        <Volume2 color={palette.graphite} size={16} />
        <Text style={styles.label}>{S.label}</Text>
        <Switch
          value={shown}
          onValueChange={onChange}
          disabled={mutation.isPending}
          trackColor={{ false: C.track, true: palette.mintDeep }}
          accessibilityLabel={`${S.label}, ${childName}`}
          accessibilityHint={S.hint}
          testID={`game-feedback-switch-${childId}`}
        />
      </View>
      <Text style={styles.hint}>{S.hint}</Text>
      {failed && (
        <Text style={styles.error} testID={`game-feedback-error-${childId}`}>
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
