/**
 * StartScreen — first screen when nobody is signed in (M2-02): two clear paths.
 * "Sem starš" → e-mail login (with "Registracija" → ParentSignupScreen, M2-10a);
 * "Sem otrok" → the 6-digit PIN from the parent.
 * Which path is open is local UI state (nothing to persist).
 */

import { useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { ChevronRight, KeyRound, PawPrint, ShieldCheck } from 'lucide-react-native';

import BuildLabel from '@/components/BuildLabel';
import ChildPinLoginScreen from '@/screens/ChildPinLoginScreen';
import ParentLoginScreen from '@/screens/ParentLoginScreen';
import ParentSignupScreen from '@/screens/ParentSignupScreen';

/** All user-visible strings of this screen (extract to i18n with M1-18). */
export const START_STRINGS = {
  title: 'PetPrep',
  subtitle: 'Kdo se prijavlja?',
  child: 'Sem otrok',
  childHint: 'Imam kodo od staršev',
  parent: 'Sem starš',
  parentHint: 'Prijava ali registracija z e-pošto',
} as const;

const S = START_STRINGS;

type Path = 'choose' | 'parent' | 'signup' | 'child';

export default function StartScreen() {
  const [path, setPath] = useState<Path>('choose');

  if (path === 'parent') {
    return <ParentLoginScreen onBack={() => setPath('choose')} onSignup={() => setPath('signup')} />;
  }
  if (path === 'signup') {
    return <ParentSignupScreen onBack={() => setPath('parent')} onLogin={() => setPath('parent')} />;
  }
  if (path === 'child') return <ChildPinLoginScreen onBack={() => setPath('choose')} />;

  return (
    <View style={styles.container} testID="start-screen">
      <View style={[styles.glowOrb, styles.glowIndigo]} />
      <View style={[styles.glowOrb, styles.glowEmerald]} />

      <View style={styles.header}>
        <View style={styles.logoBadge}>
          <PawPrint color="#818cf8" size={38} />
        </View>
        <Text style={styles.title}>{S.title}</Text>
        <Text style={styles.subtitle}>{S.subtitle}</Text>
      </View>

      <View style={styles.choices}>
        <Pressable
          onPress={() => setPath('child')}
          style={({ pressed }) => [styles.choice, styles.choiceChild, pressed && styles.pressed]}
          accessibilityRole="button"
          accessibilityLabel={S.child}
        >
          <View style={[styles.choiceIcon, styles.choiceIconChild]}>
            <KeyRound color="#c7d2fe" size={28} />
          </View>
          <View style={styles.choiceText}>
            <Text style={styles.choiceTitle}>{S.child}</Text>
            <Text style={styles.choiceHint}>{S.childHint}</Text>
          </View>
          <ChevronRight color="#c7d2fe" size={22} />
        </Pressable>

        <Pressable
          onPress={() => setPath('parent')}
          style={({ pressed }) => [styles.choice, pressed && styles.pressed]}
          accessibilityRole="button"
          accessibilityLabel={S.parent}
        >
          <View style={styles.choiceIcon}>
            <ShieldCheck color="#6ee7b7" size={26} />
          </View>
          <View style={styles.choiceText}>
            <Text style={styles.choiceTitle}>{S.parent}</Text>
            <Text style={styles.choiceHint}>{S.parentHint}</Text>
          </View>
          <ChevronRight color="#94a3b8" size={22} />
        </Pressable>
      </View>

      {/* Build identity: which code is being tested. */}
      <BuildLabel tone="dark" style={styles.buildLabel} />
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#020617', justifyContent: 'center', paddingHorizontal: 24 },
  glowOrb: { position: 'absolute', borderRadius: 9999 },
  buildLabel: { position: 'absolute', bottom: 28, left: 0, right: 0 },
  glowIndigo: { width: 300, height: 300, top: 60, left: -90, backgroundColor: 'rgba(79, 70, 229, 0.22)' },
  glowEmerald: { width: 240, height: 240, bottom: 80, right: -60, backgroundColor: 'rgba(16, 185, 129, 0.12)' },
  header: { alignItems: 'center', marginBottom: 36 },
  logoBadge: {
    width: 84,
    height: 84,
    borderRadius: 26,
    backgroundColor: 'rgba(255, 255, 255, 0.08)',
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.18)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  title: { marginTop: 14, fontSize: 34, fontWeight: '800', letterSpacing: -0.5, color: '#ffffff' },
  subtitle: { marginTop: 6, fontSize: 17, color: '#cbd5e1' },
  choices: { gap: 14, width: '100%', maxWidth: 380, alignSelf: 'center' },
  choice: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 14,
    minHeight: 88,
    paddingHorizontal: 18,
    borderRadius: 24,
    backgroundColor: 'rgba(15, 23, 42, 0.75)',
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.14)',
  },
  choiceChild: { backgroundColor: 'rgba(79, 70, 229, 0.3)', borderColor: 'rgba(129, 140, 248, 0.6)' },
  choiceIcon: {
    width: 52,
    height: 52,
    borderRadius: 16,
    backgroundColor: 'rgba(255, 255, 255, 0.08)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  choiceIconChild: { backgroundColor: 'rgba(129, 140, 248, 0.25)' },
  choiceText: { flex: 1, gap: 2 },
  choiceTitle: { fontSize: 22, fontWeight: '800', color: '#ffffff' },
  choiceHint: { fontSize: 14, color: '#cbd5e1' },
  pressed: { opacity: 0.85, transform: [{ scale: 0.98 }] },
});
