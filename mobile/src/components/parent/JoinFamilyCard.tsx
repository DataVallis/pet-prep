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

export const JOIN_FAMILY_STRINGS = {
  title: 'Imate kodo družine?',
  hint: 'Če vas je drugi starš povabil, vpišite njegovo kodo. Mogoče le, dokler v svoji družini še nimate otrok ali psov.',
  placeholder: 'ABCD EFGH',
  join: 'Pridruži se družini',
  joined: (parents: number) =>
    `Pridružili ste se družini (${parents} ${parents === 1 ? 'starš' : parents === 2 ? 'starša' : parents <= 4 ? 'starši' : 'staršev'}).`,
  errors: {
    invalid_code: 'Ta koda ne obstaja. Preverite jo in poskusite znova.',
    code_expired: 'Koda je potekla. Prosite drugega starša za novo.',
    code_used: 'Koda je že uporabljena. Prosite drugega starša za novo.',
    already_member: 'V tej družini že ste.',
    family_not_empty: 'Vaša družina že ima otroke ali pse, zato se ne morete pridružiti drugi družini.',
    too_many_attempts: 'Preveč napačnih kod. Poskusite znova čez 15 minut.',
    rate_limited: 'Preveč poskusov v kratkem času. Počakajte minuto in poskusite znova.',
    not_a_parent: 'Družini se lahko pridruži samo starševski račun.',
    invalid_format: 'Koda ima 8 črk in številk (npr. ABCD EFGH).',
    offline: 'Ni povezave s strežnikom. Poskusite znova.',
    server: 'Pridružitev ni uspela. Poskusite znova.',
  } satisfies Record<JoinErrorKind, string>,
} as const;

const S = JOIN_FAMILY_STRINGS;

interface JoinFamilyCardProps {
  /**
   * Called with the success text. After joining, the family is no longer empty and
   * this card disappears with the refetch — the parent screen shows the notice.
   */
  onJoined?: (message: string) => void;
}

export default function JoinFamilyCard({ onJoined }: JoinFamilyCardProps) {
  const join = useJoinFamily();
  const [input, setInput] = useState('');
  const [message, setMessage] = useState<{ text: string; isError: boolean } | null>(null);

  const submit = () => {
    const code = normalizeInviteCode(input);
    if (!code) {
      setMessage({ text: S.errors.invalid_format, isError: true });
      return;
    }
    setMessage(null);
    join.mutate(code, {
      onSuccess: (res) => {
        setInput('');
        const parents = Array.isArray(res.family?.parents) ? res.family.parents.length : 2;
        const text = S.joined(parents);
        setMessage({ text, isError: false });
        onJoined?.(text);
      },
      onError: (err) => setMessage({ text: S.errors[classifyJoinError(err)], isError: true }),
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
          {message.text}
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
