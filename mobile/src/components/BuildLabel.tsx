/**
 * Small muted "v{version} · {sha}" label (build identity, see `config/buildInfo.ts`).
 * `tone`: `dark` on the child / start screens, `light` in the parent app.
 */

import { StyleSheet, Text, type StyleProp, type TextStyle } from 'react-native';

import { formatBuildLabel, getBuildInfo } from '@/config/buildInfo';

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
  dark: { color: 'rgba(148, 163, 184, 0.55)' },
  light: { color: '#94a3b8' },
});
