/**
 * Prominent parent call-to-action shown while no child is paired (M2-02, partial).
 * Light parent theme (ADR-007).
 */

import { Pressable, StyleSheet, Text, View } from 'react-native';
import { ChevronRight, UserPlus } from 'lucide-react-native';

/** User-visible strings (extract to i18n with M1-18). */
export const ADD_CHILD_CARD_STRINGS = {
  title: 'Dodaj otroka',
  body: 'Ustvarite 6-mestno kodo. Otrok jo vtipka na svojem telefonu in njegov kuža se rodi.',
  cta: 'Ustvari kodo',
} as const;

interface AddChildCardProps {
  onPress: () => void;
  /** Smaller variant for the Controls screen. */
  compact?: boolean;
}

export default function AddChildCard({ onPress, compact = false }: AddChildCardProps) {
  const S = ADD_CHILD_CARD_STRINGS;
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={S.title}
      testID="add-child-card"
      style={({ pressed }) => [styles.card, compact && styles.cardCompact, pressed && styles.pressed]}
    >
      <View style={styles.iconBadge}>
        <UserPlus color="#4f46e5" size={compact ? 20 : 26} />
      </View>
      <View style={styles.textCol}>
        <Text style={[styles.title, compact && styles.titleCompact]}>{S.title}</Text>
        {!compact && <Text style={styles.body}>{S.body}</Text>}
        <View style={styles.ctaRow}>
          <Text style={styles.cta}>{S.cta}</Text>
          <ChevronRight color="#4f46e5" size={16} />
        </View>
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  card: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: 14,
    padding: 18,
    borderRadius: 20,
    backgroundColor: '#ffffff',
    borderWidth: 2,
    borderColor: '#c7d2fe',
    shadowColor: '#4f46e5',
    shadowOffset: { width: 0, height: 6 },
    shadowOpacity: 0.15,
    shadowRadius: 14,
    elevation: 4,
  },
  cardCompact: { padding: 14, borderWidth: 1, shadowOpacity: 0, elevation: 0 },
  iconBadge: {
    width: 48,
    height: 48,
    borderRadius: 14,
    backgroundColor: '#eef2ff',
    alignItems: 'center',
    justifyContent: 'center',
  },
  textCol: { flex: 1, gap: 4 },
  title: { fontSize: 19, fontWeight: '800', color: '#0f172a' },
  titleCompact: { fontSize: 16 },
  body: { fontSize: 14, lineHeight: 20, color: '#475569' },
  ctaRow: { flexDirection: 'row', alignItems: 'center', gap: 4, marginTop: 4 },
  cta: { fontSize: 14, fontWeight: '700', color: '#4f46e5' },
  pressed: { opacity: 0.85 },
});
