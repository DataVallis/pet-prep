/**
 * Shared light styles for the parent auth screens (login, signup) — CGP v2: fog
 * background, horizontal logo, one white card, graphite primary button, mint-text links.
 */

import type { TextStyle, ViewStyle } from 'react-native';

import { fonts, light, palette, radius, tightTracking } from '@/theme';

export const AUTH_STYLES = {
  container: { flex: 1, backgroundColor: light.bg } satisfies ViewStyle,
  flex: { flex: 1 } satisfies ViewStyle,
  scrollContent: { flexGrow: 1, alignItems: 'center', paddingHorizontal: 24, paddingTop: 56, paddingBottom: 40 } satisfies ViewStyle,
  backRow: { alignSelf: 'flex-start', flexDirection: 'row', alignItems: 'center', gap: 2, paddingVertical: 10, minHeight: 44 } satisfies ViewStyle,
  backText: { fontSize: 15, fontWeight: '500', color: light.inkMuted } satisfies TextStyle,
  header: { alignItems: 'center', marginTop: 8, marginBottom: 24, gap: 18 } satisfies ViewStyle,
  title: { fontFamily: fonts.display, fontSize: 28, letterSpacing: tightTracking(28), color: light.ink, textAlign: 'center' } satisfies TextStyle,
  subtitle: { marginTop: -10, fontSize: 15, lineHeight: 21, color: light.inkMuted, textAlign: 'center' } satisfies TextStyle,
  formCard: {
    width: '100%',
    maxWidth: 380,
    backgroundColor: light.surface,
    borderWidth: 1,
    borderColor: light.border,
    borderRadius: radius.card,
    padding: 22,
  } satisfies ViewStyle,
  inputsContainer: { gap: 12 } satisfies ViewStyle,
  input: {
    height: 52,
    backgroundColor: light.bg,
    borderWidth: 1,
    borderColor: light.border,
    borderRadius: radius.input,
    paddingHorizontal: 16,
    fontSize: 15,
    color: light.ink,
  } satisfies TextStyle,
  inputInvalid: { borderColor: light.danger } satisfies ViewStyle,
  errorBox: {
    marginTop: 14,
    padding: 12,
    backgroundColor: palette.dangerSoft,
    borderWidth: 1,
    borderColor: palette.dangerBorder,
    borderRadius: radius.button,
  } satisfies ViewStyle,
  errorText: { fontSize: 13, color: light.danger, textAlign: 'center' } satisfies TextStyle,
  primaryButton: {
    marginTop: 18,
    height: 52,
    backgroundColor: light.action,
    borderRadius: radius.button,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
  } satisfies ViewStyle,
  buttonDisabled: { opacity: 0.45 } satisfies ViewStyle,
  primaryButtonText: { fontSize: 16, fontWeight: '600', color: light.onAction } satisfies TextStyle,
  switchRow: { marginTop: 14, alignItems: 'center', justifyContent: 'center', minHeight: 44 } satisfies ViewStyle,
  switchText: { fontSize: 14, color: light.mintText, fontWeight: '600' } satisfies TextStyle,
  pressed: { opacity: 0.85, transform: [{ scale: 0.98 }] } satisfies ViewStyle,
} as const;
