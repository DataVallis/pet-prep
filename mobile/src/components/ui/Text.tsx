/**
 * Brand-aware `Text` / `TextInput` (CGP v2). Drop-in replacements for the React Native
 * components — every screen imports these instead, so the whole app renders in
 * Instrument Sans without each style naming a font.
 *
 * React Native selects a face by family name (each weight of a Google font is its own
 * family), so `fontWeight` alone would fall back to the system font. These wrappers:
 * - no `fontFamily` in the style → the Instrument Sans face for `fontWeight`;
 * - a Bricolage family (`fonts.display*`) → kept, with the face matched to `fontWeight`;
 * - `fontWeight` is then dropped, so Android doesn't synthesise a fake bold.
 */

import { forwardRef } from 'react';
import {
  StyleSheet,
  Text as RNText,
  TextInput as RNTextInput,
  type TextInputProps,
  type TextProps,
  type TextStyle,
  type StyleProp,
} from 'react-native';

import { bodyFamilyForWeight, displayFamilyForWeight, isDisplayFamily } from '@/theme/typography';

/** Resolve the brand face for a style (exported for tests). */
export function brandFontStyle(style: StyleProp<TextStyle>): TextStyle {
  const flat = (StyleSheet.flatten(style) ?? {}) as TextStyle;
  const { fontFamily, fontWeight } = flat;
  if (fontFamily !== undefined && !isDisplayFamily(fontFamily)) return {};
  const family = isDisplayFamily(fontFamily) && fontWeight !== undefined
    ? displayFamilyForWeight(fontWeight)
    : fontFamily ?? bodyFamilyForWeight(fontWeight);
  return { fontFamily: family, fontWeight: undefined };
}

export const Text = forwardRef<RNText, TextProps>(function Text({ style, ...rest }, ref) {
  return <RNText ref={ref} {...rest} style={[style, brandFontStyle(style)]} />;
});

export const TextInput = forwardRef<RNTextInput, TextInputProps>(function TextInput({ style, ...rest }, ref) {
  return <RNTextInput ref={ref} {...rest} style={[style, brandFontStyle(style)]} />;
});
