/**
 * M5-T11 »Prikaz: Video / Slika« for one child (APP_REFINEMENT_SPEC §1) — the parent sets how
 * the child's app shows the pet: `video` (default) or `picture` (the key image of the current
 * state, no video download — less mobile data). The child can change it too (HUD settings).
 *
 * The value shown is the server's (`family.children[].media_display`); while saving, the
 * switch shows the requested value; a failure puts it back and shows a short error.
 */

import { useState } from 'react';
import { StyleSheet, View } from 'react-native';

import MediaDisplaySwitch from '@/components/MediaDisplaySwitch';
import { Text } from '@/components/ui/Text';
import { useSetChildMediaDisplay } from '@/hooks/queries/useFamilyMutations';
import type { MediaDisplay } from '@/modules/display/display';
import { palette } from '@/theme';
import { strings } from '@/i18n/strings';

/** All user-visible strings (`family:children.display`). */
export const MEDIA_DISPLAY_STRINGS = strings('family', 'children', {});

interface MediaDisplayRowProps {
  childId: number;
  childName: string;
  /** The server's value. */
  value: MediaDisplay;
}

export default function MediaDisplayRow({ childId, childName, value }: MediaDisplayRowProps) {
  const S = MEDIA_DISPLAY_STRINGS.display;
  const mutation = useSetChildMediaDisplay();
  const [failed, setFailed] = useState(false);
  const shown = mutation.isPending && mutation.variables ? mutation.variables.mediaDisplay : value;

  const onChange = (next: MediaDisplay) => {
    setFailed(false);
    mutation.mutate({ childId, mediaDisplay: next }, { onError: () => setFailed(true) });
  };

  return (
    <View style={styles.box} testID={`media-display-${childId}`}>
      <View style={styles.row}>
        <Text style={styles.label}>{S.label}</Text>
        <MediaDisplaySwitch
          value={shown}
          onChange={onChange}
          label={`${S.label}, ${childName}`}
          labels={{ video: S.video, picture: S.picture }}
          tone="light"
          disabled={mutation.isPending}
          testID={`media-display-switch-${childId}`}
        />
      </View>
      <Text style={styles.hint}>{S.hint}</Text>
      {failed && (
        <Text style={styles.error} testID={`media-display-error-${childId}`}>
          {S.error}
        </Text>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  box: { gap: 4 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 8, flexWrap: 'wrap' },
  label: { flex: 1, fontSize: 14, fontWeight: '700', color: palette.graphite },
  hint: { fontSize: 12, lineHeight: 17, color: palette.n600 },
  error: { fontSize: 12, color: palette.danger },
});
