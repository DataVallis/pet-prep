/**
 * Mess illustrations and cleaning tools (GAMES_SPEC §6, David 2026-10-30), drawn with
 * react-native-svg from the shared shape data in `modules/cleaning/messArt.ts`.
 *
 * react-native-svg is in every store binary, so these replace the old circles everywhere a mess
 * is shown — the HUD scene card, the old tap overlays (`CleaningOverlay`, `ScoopOverlay`) and the
 * cleaning games of the native layer. Flat, rounded, no faces, never red; mint = "clean".
 * Readable from 32 pt (a pill icon) to 160 pt (a scene).
 */

import Svg, { Ellipse, G, Path } from 'react-native-svg';

import {
  BAG_PATHS,
  CLOTH_PATHS,
  CLUMP_PATHS,
  FLAKE_PATH,
  PEE_PATHS,
  POOP_PATHS,
  SCOOP_PATHS,
  TOY_PATH,
} from '@/modules/cleaning/messArt';
import { mess, palette } from '@/theme';

export interface MessGraphicProps {
  /** Width in pt; the height follows the viewBox. */
  size?: number;
  testID?: string;
}

/** Poop (sketch A): a soft three-tier brown swirl, one highlight, no face. viewBox 120 × 80. */
export function PoopGraphic({ size = 88, testID = 'mess-poop' }: MessGraphicProps) {
  return (
    <Svg width={size} height={(size * 80) / 120} viewBox="0 0 120 80" testID={testID}>
      <G stroke={mess.brownDeep} strokeWidth={2} strokeLinejoin="round" fill={mess.brown}>
        <Path d={POOP_PATHS.bottom} />
        <Path d={POOP_PATHS.middle} />
        <Path d={POOP_PATHS.top} />
      </G>
      <Ellipse {...POOP_PATHS.shine} fill={palette.white} fillOpacity={0.45} />
    </Svg>
  );
}

/** Pee: a shallow puddle with a rim and a reflection, plus a drop. viewBox 120 × 80. */
export function PeeGraphic({ size = 88, testID = 'mess-pee' }: MessGraphicProps) {
  return (
    <Svg width={size} height={(size * 80) / 120} viewBox="0 0 120 80" testID={testID}>
      <Path d={PEE_PATHS.body} fill={mess.puddle} fillOpacity={0.7} stroke={mess.puddleEdge} strokeWidth={2} strokeLinejoin="round" />
      <Path d={PEE_PATHS.rim} fill="none" stroke={mess.puddleEdge} strokeOpacity={0.55} strokeWidth={1.5} />
      {PEE_PATHS.shine.map((s, i) => (
        <Ellipse key={i} {...s} fill={palette.white} fillOpacity={0.5} />
      ))}
      <Path d={PEE_PATHS.drop} fill={mess.puddle} stroke={mess.puddleEdge} strokeWidth={2} strokeLinejoin="round" />
      <Ellipse {...PEE_PATHS.dropShine} fill={palette.white} fillOpacity={0.6} />
    </Svg>
  );
}

/** One litter clump: light and rounded (never a dark dot). viewBox 40 × 30. */
export function ClumpGraphic({ size = 40, testID = 'mess-clump' }: MessGraphicProps) {
  return (
    <Svg width={size} height={(size * 30) / 40} viewBox="0 0 40 30" testID={testID}>
      <Path d={CLUMP_PATHS.body} fill={mess.clumpLight} stroke={mess.clump} strokeWidth={2} strokeLinejoin="round" />
      <Ellipse {...CLUMP_PATHS.top} fill={palette.white} fillOpacity={0.35} />
    </Svg>
  );
}

/** One flake bitten off the slipper: a soft fog-grey tuft. viewBox 40 × 30. */
export function FlakeGraphic({ size = 32, testID = 'mess-flake' }: MessGraphicProps) {
  return (
    <Svg width={size} height={(size * 30) / 40} viewBox="0 0 40 30" testID={testID}>
      <Path d={FLAKE_PATH} fill={mess.fluff} stroke={palette.n400} strokeWidth={2} strokeLinejoin="round" />
    </Svg>
  );
}

/** The poop bag (mint = clean): open with two handles, or tied with a knot. viewBox 60 × 60. */
export function BagGraphic({ size = 60, tied = false, testID = 'tool-bag' }: MessGraphicProps & { tied?: boolean }) {
  return (
    <Svg width={size} height={size} viewBox="0 0 60 60" testID={testID}>
      {tied ? (
        <Path d={BAG_PATHS.knot} fill={palette.mint} stroke={palette.mintDeep} strokeWidth={2} strokeLinejoin="round" />
      ) : (
        <Path d={BAG_PATHS.handles} fill="none" stroke={palette.mintDeep} strokeWidth={3} strokeLinecap="round" />
      )}
      <Path d={BAG_PATHS.body} fill={palette.mint} stroke={palette.mintDeep} strokeWidth={2} strokeLinejoin="round" />
    </Svg>
  );
}

/** The cloth for a puddle. viewBox 60 × 60. */
export function ClothGraphic({ size = 60, testID = 'tool-cloth' }: MessGraphicProps) {
  return (
    <Svg width={size} height={size} viewBox="0 0 60 60" testID={testID}>
      <Path d={CLOTH_PATHS.body} fill={palette.mintSoft} stroke={palette.mintDeep} strokeWidth={2} strokeLinejoin="round" />
      <Path d={CLOTH_PATHS.stripe} stroke={palette.mint} strokeWidth={3} />
      <Path d={CLOTH_PATHS.fold} fill="none" stroke={palette.mintDeep} strokeOpacity={0.5} strokeWidth={1.5} strokeLinecap="round" />
    </Svg>
  );
}

/** The litter scoop (slotted). viewBox 60 × 60. */
export function ScoopGraphic({ size = 60, testID = 'tool-scoop' }: MessGraphicProps) {
  return (
    <Svg width={size} height={size} viewBox="0 0 60 60" testID={testID}>
      <Path d={SCOOP_PATHS.handle} fill={palette.mintDeep} stroke={palette.mintDeep} strokeWidth={2} strokeLinejoin="round" />
      <Path d={SCOOP_PATHS.head} fill={palette.mint} stroke={palette.mintDeep} strokeWidth={2} strokeLinejoin="round" />
      <Path d={SCOOP_PATHS.slots} stroke={palette.mintDeep} strokeWidth={3} strokeLinecap="round" />
    </Svg>
  );
}

/** The chew toy (a bone) the dog gets instead of the slipper. viewBox 60 × 60 (drawn in 60 × 44). */
export function ChewToyGraphic({ size = 60, testID = 'tool-toy' }: MessGraphicProps) {
  return (
    <Svg width={size} height={size} viewBox="0 -8 60 60" testID={testID}>
      <Path d={TOY_PATH} fill={palette.mint} stroke={palette.mintDeep} strokeWidth={2} strokeLinejoin="round" />
    </Svg>
  );
}
