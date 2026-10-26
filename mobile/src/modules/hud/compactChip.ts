/**
 * Icon-only chip of the collapsed HUD top stack (David 2026-10-26): a 36 pt round glass button
 * (+4 pt hitSlop each side = 44 pt target) with the status dot / ✓ in its top-right corner. The
 * chip keeps its full name as the accessibility label and its action.
 */

import { StyleSheet } from 'react-native';

export const COMPACT_CHIP_SIZE = 36;
/** (44 − 36) / 2: the touch target stays 44 pt. */
export const COMPACT_CHIP_HIT_SLOP = 4;

export const compactChipStyles = StyleSheet.create({
  chip: {
    width: COMPACT_CHIP_SIZE,
    height: COMPACT_CHIP_SIZE,
    minHeight: COMPACT_CHIP_SIZE,
    paddingHorizontal: 0,
    borderRadius: COMPACT_CHIP_SIZE / 2,
    justifyContent: 'center',
    gap: 0,
  },
  /** The status dot / ✓ sits on the circle's top-right edge. */
  corner: {
    position: 'absolute',
    top: -1,
    right: -1,
  },
});
