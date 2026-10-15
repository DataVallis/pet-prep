/**
 * "Nadaljuj z Googlom" / "Continue with Google" (M2-10c) — Google's light sign-in button
 * per the Google Identity branding guidelines: white fill, 1 px #747775 stroke, #1F1F1F
 * label, the official four-colour "G" mark (vector, unmodified) on the left, Roboto Medium
 * on Android (system font on iOS). Colours come from the `google` theme tokens — they are
 * Google's, not PetPrep's brand.
 */

import { ActivityIndicator, Platform, Pressable, StyleSheet, View } from 'react-native';
import Svg, { Path } from 'react-native-svg';

import { Text } from '@/components/ui/Text';
import { google, radius } from '@/theme';

/** The official Google "G" mark (Google sign-in branding kit, 48 × 48 viewBox). */
export function GoogleGMark({ size = 18 }: { size?: number }) {
  return (
    <Svg width={size} height={size} viewBox="0 0 48 48" accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
      <Path
        fill={google.red}
        d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"
      />
      <Path
        fill={google.blue}
        d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"
      />
      <Path
        fill={google.yellow}
        d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"
      />
      <Path
        fill={google.green}
        d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"
      />
    </Svg>
  );
}

interface GoogleSignInButtonProps {
  label: string;
  onPress: () => void;
  disabled: boolean;
  /** This button's flow is running: spinner instead of the label. */
  loading: boolean;
  testID?: string;
}

export default function GoogleSignInButton({ label, onPress, disabled, loading, testID }: GoogleSignInButtonProps) {
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      style={({ pressed }) => [styles.button, disabled && !loading && styles.disabled, pressed && styles.pressed]}
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ disabled, busy: loading }}
      testID={testID}
    >
      <View style={styles.logo}>
        <GoogleGMark />
      </View>
      {loading ? <ActivityIndicator color={google.text} /> : <Text style={styles.label}>{label}</Text>}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  button: {
    height: 48,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 10,
    paddingHorizontal: 12,
    borderRadius: radius.button,
    borderWidth: 1,
    borderColor: google.stroke,
    backgroundColor: google.surface,
  },
  logo: { width: 20, height: 20, alignItems: 'center', justifyContent: 'center' },
  label: {
    // Google asks for Roboto Medium: Android's `sans-serif-medium` is exactly that; iOS has
    // no Roboto, so it uses the system face at the same weight.
    fontFamily: Platform.select({ android: 'sans-serif-medium', ios: 'System', default: undefined }),
    fontWeight: '500',
    fontSize: 15,
    color: google.text,
  },
  disabled: { opacity: 0.5 },
  pressed: { opacity: 0.85 },
});
