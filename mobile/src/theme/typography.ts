/**
 * CGP v2 type: Bricolage Grotesque 700/800 for headings and numbers, Instrument Sans
 * 400–700 for body and UI (both cover Slovenian diacritics). Loaded at runtime in
 * `App.tsx` via `useBrandFonts()`; until then (and in Jest) the system font is used.
 *
 * React Native picks a face by family name, not by `fontWeight`, so `@/components/ui/Text`
 * maps `fontWeight` → the matching Instrument Sans face whenever a style sets no family.
 */

// Per-weight subpaths: only these six faces are bundled (the package index pulls in all 15).
import { BricolageGrotesque_700Bold } from '@expo-google-fonts/bricolage-grotesque/700Bold';
import { BricolageGrotesque_800ExtraBold } from '@expo-google-fonts/bricolage-grotesque/800ExtraBold';
import { InstrumentSans_400Regular } from '@expo-google-fonts/instrument-sans/400Regular';
import { InstrumentSans_500Medium } from '@expo-google-fonts/instrument-sans/500Medium';
import { InstrumentSans_600SemiBold } from '@expo-google-fonts/instrument-sans/600SemiBold';
import { InstrumentSans_700Bold } from '@expo-google-fonts/instrument-sans/700Bold';
import { useFonts } from 'expo-font';

export const fonts = {
  /** Headings, hero numbers. */
  display: 'BricolageGrotesque_800ExtraBold',
  /** Smaller headings, numbers in meters / stats. */
  displayBold: 'BricolageGrotesque_700Bold',
  body: 'InstrumentSans_400Regular',
  bodyMedium: 'InstrumentSans_500Medium',
  bodySemiBold: 'InstrumentSans_600SemiBold',
  bodyBold: 'InstrumentSans_700Bold',
} as const;

const DISPLAY_FAMILIES: ReadonlySet<string> = new Set([fonts.display, fonts.displayBold]);

/** Headings use tight tracking (−0.01 to −0.02 em): letterSpacing for a font size. */
export function tightTracking(fontSize: number): number {
  return Math.round(-0.015 * fontSize * 100) / 100;
}

/** Instrument Sans face for a CSS-style weight (800/900 fall back to 700, the heaviest face). */
export function bodyFamilyForWeight(weight: string | number | undefined): string {
  const w = weight === 'bold' ? 700 : weight === 'normal' || weight === undefined ? 400 : Number(weight);
  if (!Number.isFinite(w) || w < 500) return fonts.body;
  if (w < 600) return fonts.bodyMedium;
  if (w < 700) return fonts.bodySemiBold;
  return fonts.bodyBold;
}

/** Bricolage face for a weight (≤ 700 → Bold, otherwise ExtraBold). */
export function displayFamilyForWeight(weight: string | number | undefined): string {
  const w = weight === 'bold' ? 700 : Number(weight ?? 800);
  return Number.isFinite(w) && w <= 700 ? fonts.displayBold : fonts.display;
}

export function isDisplayFamily(family: string | undefined): boolean {
  return family !== undefined && DISPLAY_FAMILIES.has(family);
}

/** Load the brand faces. Returns `[loaded, error]`; render anyway on error (system font). */
export function useBrandFonts(): [boolean, Error | null] {
  return useFonts({
    BricolageGrotesque_700Bold,
    BricolageGrotesque_800ExtraBold,
    InstrumentSans_400Regular,
    InstrumentSans_500Medium,
    InstrumentSans_600SemiBold,
    InstrumentSans_700Bold,
  });
}
