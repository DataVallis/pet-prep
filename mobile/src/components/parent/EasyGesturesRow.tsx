/**
 * M5-T17 »Lažje kretnje« / "Easier gestures" — a parent-only switch per child profile
 * (APP_REFINEMENT_SPEC §6 / §11, David 2026-10-23), separate from »Prilagojen način«.
 *
 * On: the child's swipe games (brushing, cat wand play, litter change, dog ball play) offer
 * press-and-hold / a button instead of swiping. Off: swipe only. With VoiceOver / TalkBack on
 * the child's phone the games use hold mode anyway (said in the row). Only the boolean goes to
 * `PATCH /api/parent/children/{child}/settings`; the value shown is the server's
 * (`family.children[].easy_gestures`) — while saving the switch shows the requested value; a
 * failure puts it back and shows a short error.
 */

import { useState } from 'react';
import { StyleSheet, Switch, View } from 'react-native';
import { Hand } from 'lucide-react-native';

import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { Text } from '@/components/ui/Text';
import { useSetEasyGestures } from '@/hooks/queries/useFamilyMutations';
import { strings } from '@/i18n/strings';
import { palette } from '@/theme';

/** All user-visible strings (`family:children.easyGestures`). */
export const EASY_GESTURES_STRINGS = strings('family', 'children', {});

interface EasyGesturesRowProps {
  childId: number;
  childName: string;
  /** The server's value. */
  enabled: boolean;
}

export default function EasyGesturesRow({ childId, childName, enabled }: EasyGesturesRowProps) {
  const S = EASY_GESTURES_STRINGS.easyGestures;
  const mutation = useSetEasyGestures();
  const [failed, setFailed] = useState(false);
  const shown = mutation.isPending && mutation.variables ? mutation.variables.easyGestures : enabled;

  const onChange = (next: boolean) => {
    setFailed(false);
    mutation.mutate({ childId, easyGestures: next }, { onError: () => setFailed(true) });
  };

  return (
    <View style={styles.box} testID={`easy-gestures-${childId}`}>
      <View style={styles.row}>
        <Hand color={palette.graphite} size={16} />
        <Text style={styles.label}>{S.label}</Text>
        <Switch
          value={shown}
          onValueChange={onChange}
          disabled={mutation.isPending}
          trackColor={{ false: C.track, true: palette.mintDeep }}
          accessibilityLabel={`${S.label}, ${childName}`}
          accessibilityHint={S.hint}
          testID={`easy-gestures-switch-${childId}`}
        />
      </View>
      <Text style={styles.hint}>{S.hint}</Text>
      <Text style={styles.hint}>{S.screenReader}</Text>
      {failed && (
        <Text style={styles.error} testID={`easy-gestures-error-${childId}`}>
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
