/**
 * "Imate kodo družine?" (M2-01a): a parent joins another parent's family with the
 * 8-character code (`POST /api/parent/join-family`). Only possible while this
 * parent's own family is still empty — no merging (409 `family_not_empty`).
 */

import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet } from 'react-native';
import { Text, TextInput } from '@/components/ui/Text';
import { Users } from 'lucide-react-native';

import { useJoinFamily } from '@/hooks/queries/useParentQueries';
import { Card, PARENT_COLORS as C, SectionTitle } from '@/components/parent/ParentUi';
import { classifyJoinError, normalizeInviteCode, type JoinErrorKind } from '@/modules/family/invite';
import { fonts, palette } from '@/theme';
import { t } from '@/i18n';
import { strings } from '@/i18n/strings';


/** All user-visible strings (`family:join`, M1-18). */
export const JOIN_FAMILY_STRINGS = strings('family', 'join', {
  joined: (parents: number) => t('family:join.joined', { count: parents }),
});

const S = JOIN_FAMILY_STRINGS;

interface JoinFamilyCardProps {
  /**
   * Called with the joined notice (a code, translated at render — M1-18 review). After
   * joining, the family is no longer empty and this card disappears with the refetch —
   * the parent screen shows the notice.
   */
  onJoined?: (notice: JoinNotice) => void;
}

/** "You joined the family" notice, kept as data so it follows a language switch. */
export interface JoinNotice {
  kind: 'joined';
  parents: number;
}

export function joinNoticeText(notice: JoinNotice): string {
  return S.joined(notice.parents);
}

export default function JoinFamilyCard({ onJoined }: JoinFamilyCardProps) {
  const join = useJoinFamily();
  const [input, setInput] = useState('');
  // Message as a thunk: translated at render, so it follows a language switch (M1-18 review).
  const [message, setMessage] = useState<{ text: () => string; isError: boolean } | null>(null);

  const submit = () => {
    const code = normalizeInviteCode(input);
    if (!code) {
      setMessage({ text: () => S.errors.invalid_format, isError: true });
      return;
    }
    setMessage(null);
    join.mutate(code, {
      onSuccess: (res) => {
        setInput('');
        const parents = Array.isArray(res.family?.parents) ? res.family.parents.length : 2;
        const notice: JoinNotice = { kind: 'joined', parents };
        setMessage({ text: () => joinNoticeText(notice), isError: false });
        onJoined?.(notice);
      },
      onError: (err) => setMessage({ text: () => S.errors[classifyJoinError(err)], isError: true }),
    });
  };

  return (
    <Card testID="join-family">
      <SectionTitle right={<Users color={C.accent} size={18} />}>{S.title}</SectionTitle>
      <Text style={styles.muted}>{S.hint}</Text>
      <TextInput
        style={styles.input}
        value={input}
        onChangeText={(v) => {
          setMessage(null);
          setInput(v);
        }}
        placeholder={S.placeholder}
        placeholderTextColor={C.faint}
        autoCapitalize="characters"
        autoCorrect={false}
        maxLength={16}
        accessibilityLabel={S.title}
        testID="join-code-input"
      />
      <Pressable
        style={({ pressed }) => [styles.button, (join.isPending || input.trim() === '') && styles.disabled, pressed && styles.pressed]}
        onPress={submit}
        disabled={join.isPending || input.trim() === ''}
        accessibilityRole="button"
        testID="join-submit"
      >
        {join.isPending ? <ActivityIndicator color={palette.white} /> : <Text style={styles.buttonText}>{S.join}</Text>}
      </Pressable>
      {message && (
        <Text style={[styles.message, message.isError && styles.error]} testID="join-message">
          {message.text()}
        </Text>
      )}
    </Card>
  );
}

const styles = StyleSheet.create({
  muted: { fontSize: 13, color: C.muted, lineHeight: 18 },
  input: {
    height: 50,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: C.border,
    backgroundColor: C.bg,
    paddingHorizontal: 14,
    fontSize: 20,
    fontFamily: fonts.displayBold,
    letterSpacing: 3,
    color: C.text,
  },
  button: {
    alignItems: 'center',
    justifyContent: 'center',
    minHeight: 46,
    borderRadius: 14,
    backgroundColor: C.accent,
  },
  buttonText: { fontSize: 15, fontWeight: '700', color: palette.white },
  message: { fontSize: 13, color: C.greenText },
  error: { color: C.redText },
  disabled: { opacity: 0.5 },
  pressed: { opacity: 0.85 },
});
