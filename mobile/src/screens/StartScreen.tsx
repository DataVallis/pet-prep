/**
 * StartScreen — first screen when nobody is signed in (M2-02): two clear paths.
 * "Sem starš" → e-mail login (with "Registracija" → ParentSignupScreen, M2-10a);
 * "Sem otrok" → the 6-digit PIN from the parent.
 * Which path is open is local UI state (nothing to persist).
 * CGP v2: light fog screen, horizontal logo + slogan, the child path is the one mint surface.
 */

import { useContext, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';
import { Pressable, StyleSheet, View } from 'react-native';
import { Text } from '@/components/ui/Text';
import { ChevronRight, KeyRound, ShieldCheck } from 'lucide-react-native';

import BuildLabel from '@/components/BuildLabel';
import LanguageSwitch from '@/components/LanguageSwitch';
import { BrandLogo } from '@/components/brand/BrandLogo';
import ChildPinLoginScreen from '@/screens/ChildPinLoginScreen';
import ParentLoginScreen from '@/screens/ParentLoginScreen';
import ParentSignupScreen from '@/screens/ParentSignupScreen';
import { fonts, light, palette, radius, tightTracking } from '@/theme';
import { strings } from '@/i18n/strings';

/** All user-visible strings of this screen (`auth:start`, M1-18). */
export const START_STRINGS = strings('auth', 'start');

const S = START_STRINGS;

type Path = 'choose' | 'parent' | 'signup' | 'child';

export default function StartScreen() {
  const [path, setPath] = useState<Path>('choose');
  useTranslation(); // hosts the language switch: re-render here, not only via AppNavigator
  const topInset = useContext(SafeAreaInsetsContext)?.top ?? 0; // no provider in some tests

  if (path === 'parent') {
    return <ParentLoginScreen onBack={() => setPath('choose')} onSignup={() => setPath('signup')} />;
  }
  if (path === 'signup') {
    return <ParentSignupScreen onBack={() => setPath('parent')} onLogin={() => setPath('parent')} />;
  }
  if (path === 'child') return <ChildPinLoginScreen onBack={() => setPath('choose')} />;

  return (
    <View style={styles.container} testID="start-screen">
      {/* M1-18: language before anything else — a child may not read the device language. */}
      <LanguageSwitch compact style={[styles.language, { top: Math.max(topInset, 20) + 8 }]} />
      <View style={styles.header}>
        <BrandLogo width={188} tone="light" testID="start-logo" />
        <Text style={styles.slogan}>{S.slogan}</Text>
      </View>

      <Text style={styles.subtitle} accessibilityRole="header">
        {S.subtitle}
      </Text>

      <View style={styles.choices}>
        {/* The child's path is the screen's one mint surface (CGP v2: playfulness in one place). */}
        <Pressable
          onPress={() => setPath('child')}
          style={({ pressed }) => [styles.choice, styles.choiceChild, pressed && styles.pressed]}
          accessibilityRole="button"
          accessibilityLabel={S.child}
        >
          <View style={[styles.choiceIcon, styles.choiceIconChild]}>
            <KeyRound color={palette.mint} size={24} />
          </View>
          <View style={styles.choiceText}>
            <Text style={styles.choiceTitle}>{S.child}</Text>
            <Text style={[styles.choiceHint, styles.choiceHintChild]}>{S.childHint}</Text>
          </View>
          <ChevronRight color={palette.graphite} size={22} />
        </Pressable>

        <Pressable
          onPress={() => setPath('parent')}
          style={({ pressed }) => [styles.choice, pressed && styles.pressed]}
          accessibilityRole="button"
          accessibilityLabel={S.parent}
        >
          <View style={styles.choiceIcon}>
            <ShieldCheck color={palette.graphite} size={24} />
          </View>
          <View style={styles.choiceText}>
            <Text style={styles.choiceTitle}>{S.parent}</Text>
            <Text style={styles.choiceHint}>{S.parentHint}</Text>
          </View>
          <ChevronRight color={palette.n500} size={22} />
        </Pressable>
      </View>

      {/* Build identity: which code is being tested. */}
      <BuildLabel tone="light" style={styles.buildLabel} />
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: light.bg, justifyContent: 'center', paddingHorizontal: 24 },
  buildLabel: { position: 'absolute', bottom: 28, left: 0, right: 0 },
  language: { position: 'absolute', right: 20 },
  header: { alignItems: 'center', marginBottom: 44, gap: 14 },
  slogan: { maxWidth: 300, fontSize: 15, lineHeight: 21, color: light.inkMuted, textAlign: 'center' },
  subtitle: {
    width: '100%',
    maxWidth: 380,
    alignSelf: 'center',
    marginBottom: 14,
    fontFamily: fonts.display,
    fontSize: 24,
    letterSpacing: tightTracking(24),
    color: light.ink,
  },
  choices: { gap: 12, width: '100%', maxWidth: 380, alignSelf: 'center' },
  choice: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 14,
    minHeight: 84,
    paddingHorizontal: 18,
    borderRadius: radius.card,
    backgroundColor: light.surface,
    borderWidth: 1,
    borderColor: light.border,
  },
  choiceChild: { backgroundColor: palette.mint, borderColor: palette.mint },
  choiceIcon: {
    width: 48,
    height: 48,
    borderRadius: 24,
    backgroundColor: light.surfaceMuted,
    alignItems: 'center',
    justifyContent: 'center',
  },
  choiceIconChild: { backgroundColor: palette.graphite },
  choiceText: { flex: 1, gap: 2 },
  choiceTitle: { fontFamily: fonts.displayBold, fontSize: 20, letterSpacing: tightTracking(20), color: light.ink },
  choiceHint: { fontSize: 14, color: light.inkMuted },
  choiceHintChild: { color: palette.n800 },
  pressed: { opacity: 0.88, transform: [{ scale: 0.98 }] },
});
