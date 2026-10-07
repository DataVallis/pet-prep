import type { ReactNode } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';
import { Text } from '@/components/ui/Text';
import { alpha, palette } from '@/theme';
import { toDockHint, type DockHint } from '@/modules/childPet/dockHint';

/**
 * Dock texts may grow with the system text size only this far (375 pt, five buttons);
 * beyond that `adjustsFontSizeToFit` shrinks them instead of cutting the time off.
 */
const DOCK_MAX_FONT_SCALE = 1.3;

export interface ActionButtonProps {
  /** Lucide icon node displayed inside the circular button. */
  icon: ReactNode;
  /** Uppercase label shown beneath the button. */
  label: string;
  /** Press handler invoked when the button is tapped. */
  onPress: () => void;
  /** When true, renders the disabled style and blocks presses. */
  disabled?: boolean;
  /**
   * Small hint under the label ("ob 17:00", "4.857/4.000"). A {@link DockHint} with a
   * `day` renders two lines ("jutri" above "06:00") so the time is never cut off.
   */
  hint?: string | DockHint | null;
  /**
   * The care that is due right now (CGP v2: "the care button that is due is solid mint").
   * Pass a graphite icon when due.
   */
  due?: boolean;
  /** Shows a spinner-like dimmed state while the request runs. */
  busy?: boolean;
  /** Smaller button / label for a five-button dock (puppy "Pelji ven", M5-R02). */
  compact?: boolean;
  /** Spoken label when it should say more than the visible one (e.g. the full countdown). */
  accessibilityHint?: string;
  testID?: string;
}

/**
 * Circular glassmorphism action button used in the bottom control dock.
 */
export default function ActionButton({
  icon,
  label,
  onPress,
  disabled,
  hint,
  busy,
  due,
  compact,
  accessibilityHint,
  testID,
}: ActionButtonProps) {
  const blocked = disabled === true || busy === true;
  const dock = toDockHint(hint);
  return (
    <View style={[styles.container, compact && styles.containerCompact]}>
      <Pressable
        testID={testID}
        onPress={onPress}
        disabled={blocked}
        accessibilityRole="button"
        accessibilityLabel={dock ? `${label}, ${dock.a11y}` : label}
        accessibilityHint={accessibilityHint}
        accessibilityState={{ disabled: blocked, busy: busy === true }}
        style={({ pressed }) => [
          styles.button,
          compact && styles.buttonCompact,
          disabled ? styles.buttonDisabled : due ? styles.buttonDue : styles.buttonActive,
          busy && styles.buttonBusy,
          pressed && !blocked && (due && !disabled ? styles.buttonDuePressed : styles.buttonPressed),
        ]}
      >
        {icon}
      </Pressable>

      <Text
        style={[
          styles.label,
          compact && styles.labelCompact,
          disabled ? styles.labelDisabled : due ? styles.labelDue : styles.labelActive,
        ]}
        numberOfLines={2}
        adjustsFontSizeToFit
        minimumFontScale={0.8}
        maxFontSizeMultiplier={DOCK_MAX_FONT_SCALE}
        testID={testID ? `${testID}-label` : undefined}
      >
        {label}
      </Text>
      {dock ? (
        <View style={[styles.hintBox, compact && styles.hintBoxCompact]} testID={testID ? `${testID}-hint` : undefined}>
          {dock.day !== null && (
            <Text
              style={[styles.hintDay, compact && styles.hintDayCompact]}
              numberOfLines={1}
              adjustsFontSizeToFit
              minimumFontScale={0.7}
              maxFontSizeMultiplier={DOCK_MAX_FONT_SCALE}
              testID={testID ? `${testID}-hint-day` : undefined}
            >
              {dock.day}
            </Text>
          )}
          <Text
            style={[styles.hint, compact && styles.hintCompact, dock.day !== null && styles.hintTime]}
            // A time (with a day line above) is one line that shrinks; a text may wrap once.
            numberOfLines={dock.day !== null ? 1 : 2}
            adjustsFontSizeToFit
            minimumFontScale={0.7}
            maxFontSizeMultiplier={DOCK_MAX_FONT_SCALE}
            testID={testID ? `${testID}-hint-text` : undefined}
          >
            {dock.text}
          </Text>
        </View>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    alignItems: 'center',
    gap: 8,
  },
  containerCompact: {
    gap: 6,
  },
  button: {
    width: 64,
    height: 64,
    borderRadius: 32,
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: palette.black,
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.35,
    shadowRadius: 8,
    elevation: 6,
  },
  buttonCompact: {
    width: 54,
    height: 54,
    borderRadius: 27,
  },
  buttonActive: {
    borderWidth: 1.5,
    borderColor: alpha(palette.white, 0.3),
    backgroundColor: alpha(palette.white, 0.16),
  },
  buttonDue: {
    borderWidth: 1.5,
    borderColor: palette.mint,
    backgroundColor: palette.mint,
    shadowColor: palette.mint,
    shadowOpacity: 0.35,
  },
  buttonDuePressed: {
    transform: [{ scale: 0.9 }],
    opacity: 0.85,
  },
  buttonDisabled: {
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.08),
    backgroundColor: alpha(palette.n850, 0.45),
    opacity: 0.5,
  },
  buttonBusy: {
    opacity: 0.7,
  },
  buttonPressed: {
    transform: [{ scale: 0.9 }],
    backgroundColor: alpha(palette.white, 0.28),
  },
  label: {
    maxWidth: 80,
    fontSize: 11,
    fontWeight: '700',
    textTransform: 'uppercase',
    letterSpacing: 1,
    textAlign: 'center',
  },
  labelCompact: {
    maxWidth: 66,
    fontSize: 9,
    letterSpacing: 0.4,
  },
  labelActive: {
    color: alpha(palette.white, 0.85),
  },
  labelDue: {
    color: palette.mint,
  },
  labelDisabled: {
    color: alpha(palette.white, 0.3),
  },
  hintBox: {
    marginTop: -4,
    maxWidth: 76,
    alignItems: 'center',
  },
  hintBoxCompact: {
    maxWidth: 64,
  },
  hintDay: {
    fontSize: 9,
    fontWeight: '600',
    color: alpha(palette.warnDark, 0.8),
    textAlign: 'center',
  },
  hintDayCompact: {
    fontSize: 8,
  },
  hint: {
    fontSize: 10,
    fontWeight: '600',
    color: palette.warnDark,
    textAlign: 'center',
  },
  hintCompact: {
    fontSize: 9,
  },
  hintTime: {
    fontWeight: '700',
  },
});
