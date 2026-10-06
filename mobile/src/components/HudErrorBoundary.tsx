/**
 * Error boundary around the child HUD (hotfix 2026-10-06). A JS error while rendering
 * the HUD (or in one of its effects) shows a calm dark "something got stuck" card with
 * "Poskusi znova" instead of taking the whole session down. "Poskusi znova" remounts
 * the HUD; the cached pet state is kept, so it comes back without a new request.
 *
 * `overlay` (the LockedScreen overlay): the boundary itself is laid out as an absolute
 * full-screen layer, so it takes no space from the HUD underneath and its fallback card
 * covers the HUD instead of sitting next to it.
 *
 * It only catches React render / effect errors — an exception thrown from a native
 * callback outside React is still fatal; keep those callbacks free of throws.
 */

import { Component, type ErrorInfo, type ReactNode } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';
import { Text } from '@/components/ui/Text';
import { alpha, fonts, palette, tightTracking } from '@/theme';

export const HUD_ERROR_STRINGS = {
  title: 'Ups, nekaj se je zataknilo',
  body: 'Kuža je v redu. Poskusi znova.',
  retry: 'Poskusi znova',
} as const;

interface Props {
  children: ReactNode;
  /** Called with every caught error (logging); never throws. */
  onError?: (error: Error, info: ErrorInfo) => void;
  /** Render as an absolute full-screen layer (overlays such as LockedScreen). */
  overlay?: boolean;
}

interface State {
  error: Error | null;
  /** Bumped on retry → the children mount fresh. */
  attempt: number;
}

export default class HudErrorBoundary extends Component<Props, State> {
  state: State = { error: null, attempt: 0 };

  static getDerivedStateFromError(error: Error): Partial<State> {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    try {
      this.props.onError?.(error, info);
    } catch {
      // Logging must never break the fallback.
    }
  }

  private retry = (): void => {
    this.setState((s) => ({ error: null, attempt: s.attempt + 1 }));
  };

  render(): ReactNode {
    if (this.state.error) {
      return (
        <View style={[styles.root, this.props.overlay && styles.overlay]} testID="hud-error-boundary">
          <View style={styles.card}>
            <Text style={styles.title}>{HUD_ERROR_STRINGS.title}</Text>
            <Text style={styles.body}>{HUD_ERROR_STRINGS.body}</Text>
            <Pressable
              accessibilityRole="button"
              onPress={this.retry}
              style={({ pressed }) => [styles.button, pressed && styles.pressed]}
              testID="hud-error-retry"
            >
              <Text style={styles.buttonText}>{HUD_ERROR_STRINGS.retry}</Text>
            </Pressable>
          </View>
        </View>
      );
    }
    return (
      <View
        key={this.state.attempt}
        pointerEvents={this.props.overlay ? 'box-none' : 'auto'}
        style={this.props.overlay ? styles.overlay : styles.fill}
      >
        {this.props.children}
      </View>
    );
  }
}

const styles = StyleSheet.create({
  fill: { flex: 1 },
  overlay: { ...StyleSheet.absoluteFill, zIndex: 50 },
  root: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: palette.graphite,
    paddingHorizontal: 24,
  },
  card: {
    alignItems: 'center',
    gap: 12,
    paddingHorizontal: 24,
    paddingVertical: 28,
    borderRadius: 28,
    backgroundColor: alpha(palette.graphite, 0.82),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.12),
  },
  title: { color: palette.white, fontSize: 20, letterSpacing: tightTracking(20), fontFamily: fonts.displayBold, textAlign: 'center' },
  body: { color: palette.n300, fontSize: 15, textAlign: 'center' },
  button: {
    marginTop: 8,
    paddingHorizontal: 24,
    paddingVertical: 12,
    borderRadius: 999,
    backgroundColor: alpha(palette.mint, 0.9),
  },
  pressed: { opacity: 0.8 },
  buttonText: { color: palette.white, fontSize: 15, fontWeight: '700' },
});
