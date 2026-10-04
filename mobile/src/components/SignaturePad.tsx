/**
 * Finger signature pad (M1-07b) for the responsibility contract.
 *
 * Records touches with the plain RN responder system (no native module) and draws
 * them with react-native-svg. The value is SVG path data (`M`/`L` commands, integer
 * coordinates) accepted by `POST /api/child/contract` as `svg_path`. Controlled:
 * the parent owns `value` and clears it by passing ''.
 */

import { useRef } from 'react';
import { StyleSheet, View, type GestureResponderEvent } from 'react-native';
import Svg, { Path } from 'react-native-svg';

import { appendToPath, shouldRecord, type Point } from '@/modules/contract/signaturePath';

interface SignaturePadProps {
  value: string;
  onChange: (path: string) => void;
  disabled?: boolean;
  height?: number;
  testID?: string;
  accessibilityLabel?: string;
}

function pointFrom(event: GestureResponderEvent): Point {
  return { x: event.nativeEvent.locationX, y: event.nativeEvent.locationY };
}

export default function SignaturePad({
  value,
  onChange,
  disabled = false,
  height = 140,
  testID = 'signature-pad',
  accessibilityLabel,
}: SignaturePadProps) {
  // Refs, so a fast stream of move events always extends the latest path.
  const pathRef = useRef(value);
  const lastPointRef = useRef<Point | null>(null);
  pathRef.current = value;

  const record = (point: Point, newStroke: boolean) => {
    if (!newStroke && !shouldRecord(lastPointRef.current, point)) return;
    const next = appendToPath(pathRef.current, point, newStroke);
    if (next === null) return; // backend length limit reached — stop recording
    pathRef.current = next;
    lastPointRef.current = point;
    onChange(next);
  };

  return (
    <View
      testID={testID}
      accessibilityLabel={accessibilityLabel}
      style={[styles.pad, { height }, disabled && styles.disabled]}
      onStartShouldSetResponder={() => !disabled}
      onMoveShouldSetResponder={() => !disabled}
      // Keep the gesture while drawing (don't hand it to a parent scroll view).
      onResponderTerminationRequest={() => false}
      onResponderGrant={(event) => record(pointFrom(event), true)}
      onResponderMove={(event) => record(pointFrom(event), false)}
      onResponderRelease={() => {
        lastPointRef.current = null;
      }}
    >
      <Svg width="100%" height="100%" pointerEvents="none">
        {value.length > 0 && (
          <Path
            d={value}
            stroke="#a5b4fc"
            strokeWidth={3}
            strokeLinecap="round"
            strokeLinejoin="round"
            fill="none"
          />
        )}
      </Svg>
    </View>
  );
}

const styles = StyleSheet.create({
  pad: {
    width: '100%',
    borderRadius: 14,
    borderWidth: 1,
    borderStyle: 'dashed',
    borderColor: 'rgba(165, 180, 252, 0.45)',
    backgroundColor: 'rgba(255, 255, 255, 0.04)',
    overflow: 'hidden',
  },
  disabled: {
    opacity: 0.5,
  },
});
