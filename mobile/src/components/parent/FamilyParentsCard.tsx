/**
 * Parents of the family + "Povabi drugega starša" (M2-01a): `POST /api/parent/invite-parent`
 * → 8-character single-use code valid 24 h, shown large and shared through the system
 * share sheet. A new code revokes this parent's previous one.
 */

import { useState } from 'react';
import { ActivityIndicator, Pressable, Share, StyleSheet, Text, View } from 'react-native';
import { Share2, UserPlus } from 'lucide-react-native';

import { useInviteParent } from '@/hooks/queries/useParentQueries';
import { Card, PARENT_COLORS as C, SectionTitle } from '@/components/parent/ParentUi';
import type { FamilyOverview } from '@/modules/family/family';
import {
  classifyInviteError,
  expiryText,
  formatInviteCode,
  inviteShareMessage,
  type InviteErrorKind,
} from '@/modules/family/invite';

export const FAMILY_PARENTS_STRINGS = {
  title: 'Starši',
  me: '(vi)',
  invite: 'Povabi drugega starša',
  inviteHint: 'Drugi starš vidi iste otroke in pse ter lahko upravlja nadzor. Koda velja 24 ur in le enkrat.',
  codeLabel: 'Koda družine',
  validUntil: (when: string) => `Velja do ${when}.`,
  share: 'Deli kodo',
  newCode: 'Nova koda',
  newCodeHint: 'Nova koda razveljavi prejšnjo.',
  errors: {
    too_many: 'Preveč novih kod v kratkem času. Poskusite čez nekaj časa.',
    offline: 'Ni povezave s strežnikom. Poskusite znova.',
    server: 'Kode ni bilo mogoče ustvariti. Poskusite znova.',
  } satisfies Record<InviteErrorKind, string>,
} as const;

const S = FAMILY_PARENTS_STRINGS;

export default function FamilyParentsCard({ family }: { family: FamilyOverview }) {
  const invite = useInviteParent();
  const [error, setError] = useState<string | null>(null);
  const code = invite.data ?? null;
  const expires = code ? expiryText(code.expires_at, family.timezone) : null;

  const create = () => {
    setError(null);
    invite.mutate(undefined, { onError: (err) => setError(S.errors[classifyInviteError(err)]) });
  };

  const share = () => {
    if (!code) return;
    void Share.share({ message: inviteShareMessage(code.code, expires) }).catch(() => undefined);
  };

  return (
    <Card testID="family-parents">
      <SectionTitle>{S.title}</SectionTitle>
      {family.parents.map((p) => (
        <Text key={p.id} style={styles.parent} testID={`family-parent-${p.id}`}>
          {p.name} {p.is_me ? S.me : ''}
        </Text>
      ))}

      {code ? (
        <View style={styles.codeBox} testID="invite-box">
          <Text style={styles.codeLabel}>{S.codeLabel}</Text>
          <Text style={styles.code} testID="invite-code" selectable>
            {formatInviteCode(code.code)}
          </Text>
          {expires && (
            <Text style={styles.muted} testID="invite-expiry">
              {S.validUntil(expires)}
            </Text>
          )}
          <View style={styles.row}>
            <Pressable
              style={({ pressed }) => [styles.button, styles.primary, styles.flex, pressed && styles.pressed]}
              onPress={share}
              accessibilityRole="button"
              testID="invite-share"
            >
              <Share2 color="#ffffff" size={16} />
              <Text style={[styles.buttonText, styles.white]}>{S.share}</Text>
            </Pressable>
            <Pressable
              style={({ pressed }) => [styles.button, styles.flex, pressed && styles.pressed]}
              onPress={create}
              disabled={invite.isPending}
              accessibilityRole="button"
              accessibilityHint={S.newCodeHint}
              testID="invite-new"
            >
              {invite.isPending ? <ActivityIndicator color={C.accent} /> : <Text style={styles.buttonText}>{S.newCode}</Text>}
            </Pressable>
          </View>
        </View>
      ) : (
        <>
          <Text style={styles.muted}>{S.inviteHint}</Text>
          <Pressable
            style={({ pressed }) => [styles.button, pressed && styles.pressed]}
            onPress={create}
            disabled={invite.isPending}
            accessibilityRole="button"
            testID="invite-parent"
          >
            {invite.isPending ? (
              <ActivityIndicator color={C.accent} />
            ) : (
              <>
                <UserPlus color={C.accent} size={18} />
                <Text style={styles.buttonText}>{S.invite}</Text>
              </>
            )}
          </Pressable>
        </>
      )}

      {error && (
        <Text style={styles.error} testID="invite-error">
          {error}
        </Text>
      )}
    </Card>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  row: { flexDirection: 'row', gap: 8 },
  parent: { fontSize: 14, color: C.text },
  muted: { fontSize: 13, color: C.muted, lineHeight: 18 },
  codeBox: { gap: 8, padding: 14, borderRadius: 16, backgroundColor: C.accentSoft, alignItems: 'stretch' },
  codeLabel: { fontSize: 12, fontWeight: '700', color: C.muted, textTransform: 'uppercase', letterSpacing: 0.4 },
  code: { fontSize: 32, fontWeight: '800', letterSpacing: 4, color: C.text, fontVariant: ['tabular-nums'] },
  button: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    minHeight: 46,
    borderRadius: 14,
    paddingHorizontal: 14,
    borderWidth: 1,
    borderColor: '#c7d2fe',
    backgroundColor: C.card,
  },
  primary: { backgroundColor: C.accent, borderColor: C.accent },
  buttonText: { fontSize: 14, fontWeight: '700', color: C.accent },
  white: { color: '#ffffff' },
  error: { fontSize: 13, color: C.redText },
  pressed: { opacity: 0.85 },
});
