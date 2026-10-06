/**
 * Brand-aware `Text` / `TextInput` (CGP v2). Drop-in replacements for the React Native
 * components — every screen imports these instead, so the whole app renders in
 * Instrument Sans without each style naming a font.
 *
 * React Native selects a face by family name (each weight of a Google font is its own
 * family), so `fontWeight` alone would fall back to the system font. These wrappers:
 * - no `fontFamily` in the style → the Instrument Sans face for `fontWeight`;
 * - a Bricolage family (`fonts.display*`) → kept, with the face matched to `fontWeight`;
 * - `fontWeight` is then dropped, so Android doesn't synthesise a fake bold;
 * - a nested `<Text>` that sets neither weight nor family inherits its parent's face;
 * - until the faces are registered (`setBrandFontsReady`), styles pass through untouched
 *   (system font, real `fontWeight`) — never an unregistered family name.
 */

import { createContext, forwardRef, useContext } from 'react';
import {
  StyleSheet,
  Text as RNText,
  TextInput as RNTextInput,
  type TextInputProps,
  type TextProps,
  type TextStyle,
  type StyleProp,
} from 'react-native';

import { areBrandFontsReady, bodyFamilyForWeight, displayFamilyForWeight, isDisplayFamily } from '@/theme/typography';

/** True inside a brand `<Text>` — nested text inherits the face instead of resetting it. */
const InsideText = createContext(false);

/** Resolve the brand face for a style (exported for tests). `{}` = leave the style alone. */
export function brandFontStyle(style: StyleProp<TextStyle>, nested = false): TextStyle {
  if (!areBrandFontsReady()) return {};
  const flat = (StyleSheet.flatten(style) ?? {}) as TextStyle;
  const { fontFamily, fontWeight } = flat;
  if (fontFamily !== undefined && !isDisplayFamily(fontFamily)) return {};
  if (nested && fontFamily === undefined && fontWeight === undefined) return {};
  const family = isDisplayFamily(fontFamily) && fontWeight !== undefined
    ? displayFamilyForWeight(fontWeight)
    : fontFamily ?? bodyFamilyForWeight(fontWeight);
  return { fontFamily: family, fontWeight: undefined };
}

export const Text = forwardRef<RNText, TextProps>(function Text({ style, ...rest }, ref) {
  const nested = useContext(InsideText);
  const brand = brandFontStyle(style, nested);
  const text = <RNText ref={ref} {...rest} style={brand.fontFamily ? [style, brand] : style} />;
  return nested ? text : <InsideText.Provider value>{text}</InsideText.Provider>;
});

export const TextInput = forwardRef<RNTextInput, TextInputProps>(function TextInput({ style, ...rest }, ref) {
  const brand = brandFontStyle(style);
  return <RNTextInput ref={ref} {...rest} style={brand.fontFamily ? [style, brand] : style} />;
});
