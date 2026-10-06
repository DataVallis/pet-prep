/**
 * Small muted "v{version} · {sha}" label (build identity, see `config/buildInfo.ts`).
 * `tone`: `dark` on the child / start screens, `light` in the parent app.
 */

import { StyleSheet, type StyleProp, type TextStyle } from 'react-native';
import { Text } from '@/components/ui/Text';

import { formatBuildLabel, getBuildInfo } from '@/config/buildInfo';
import { alpha, palette } from '@/theme';

interface BuildLabelProps {
  tone?: 'dark' | 'light';
  style?: StyleProp<TextStyle>;
}

export default function BuildLabel({ tone = 'dark', style }: BuildLabelProps) {
  return (
    <Text
      style={[styles.label, tone === 'dark' ? styles.dark : styles.light, style]}
      testID="build-label"
      selectable
      accessibilityLabel={`Različica ${formatBuildLabel(getBuildInfo())}`}
    >
      {formatBuildLabel(getBuildInfo())}
    </Text>
  );
}

const styles = StyleSheet.create({
  label: { fontSize: 11, fontWeight: '500', textAlign: 'center', letterSpacing: 0.3, fontVariant: ['tabular-nums'] },
  dark: { color: alpha(palette.n400, 0.55) },
  light: { color: palette.n500 },
});
