/**
 * Free-tier behaviour scenes (M5-R02, David 2026-10-06: free = icons / graphics only,
 * premium = AI video): a puddle (puppy accident), a chewed slipper and (cat, M5-R06-08a) a
 * scratched sofa, drawn in-app with
 * react-native-svg — no bundled bitmaps, no emoji, crisp at any size. Soft, friendly
 * colours on the dark glass HUD: a mess to tidy up, never something scary or gross.
 */

import Svg, { Circle, Ellipse, G, Path } from 'react-native-svg';

import type { BehaviourScene } from '@/modules/behaviour/behaviour';
import { palette } from '@/theme';

export interface SceneGraphicProps {
  size?: number;
  testID?: string;
}

/** A shallow pale-yellow puddle with a light shine and two drops. viewBox 120 × 80. */
export function PuddleGraphic({ size = 88, testID = 'scene-graphic-accident' }: SceneGraphicProps) {
  return (
    <Svg width={size} height={(size * 80) / 120} viewBox="0 0 120 80" testID={testID}>
      <Path
        d="M14 46 C6 34 22 22 38 26 C46 14 70 12 80 24 C96 18 114 30 106 44 C116 56 98 70 80 64 C68 74 44 74 36 64 C18 70 4 58 14 46 Z"
        fill={palette.warnDark}
        fillOpacity={0.78}
        stroke={palette.warnDark}
        strokeWidth={2}
      />
      <Ellipse cx={46} cy={38} rx={14} ry={5} fill={palette.white} fillOpacity={0.55} />
      <Ellipse cx={74} cy={52} rx={7} ry={2.5} fill={palette.white} fillOpacity={0.4} />
      <Circle cx={104} cy={18} r={4} fill={palette.warnDark} fillOpacity={0.8} />
      <Circle cx={112} cy={28} r={2.5} fill={palette.warnDark} fillOpacity={0.7} />
    </Svg>
  );
}

/** A soft fog-grey slipper (mint strap) seen from above with a bitten, zig-zag toe and fluff bits. viewBox 120 × 80. */
export function ChewedSlipperGraphic({ size = 88, testID = 'scene-graphic-chewing' }: SceneGraphicProps) {
  return (
    <Svg width={size} height={(size * 80) / 120} viewBox="0 0 120 80" testID={testID}>
      <G transform="rotate(-12 60 40)">
        {/* Sole: heel left, toe right — the toe edge is bitten (zig-zag). */}
        <Path
          d="M18 40 C18 26 30 20 46 20 L82 22 L88 18 L92 26 L98 22 L100 32 L106 30 L104 40 L108 48 L100 50 L100 58 L92 56 L86 60 L82 56 L46 60 C30 60 18 54 18 40 Z"
          fill={palette.n200}
          stroke={palette.n400}
          strokeWidth={2}
          strokeLinejoin="round"
        />
        {/* Strap across the middle. */}
        <Path d="M58 21 C70 26 70 54 58 59 L72 58 C82 52 82 28 72 22 Z" fill={palette.mint} stroke={palette.mintDeep} strokeWidth={1.5} />
        {/* Heel cushion. */}
        <Ellipse cx={34} cy={40} rx={10} ry={12} fill={palette.n300} />
      </G>
      {/* Fluff bits torn off the toe. */}
      <Circle cx={112} cy={14} r={3} fill={palette.n200} />
      <Circle cx={104} cy={70} r={2.5} fill={palette.n200} />
      <Circle cx={114} cy={58} r={2} fill={palette.n200} />
    </Svg>
  );
}

/**
 * A soft sofa cushion (fog grey) with three light claw lines and a few fluff bits — the cat's
 * "scratched the sofa" (M5-R06-05 / 08a). Friendly, never destructive-looking. viewBox 120 × 80.
 */
export function ScratchedSofaGraphic({ size = 88, testID = 'scene-graphic-scratching' }: SceneGraphicProps) {
  return (
    <Svg width={size} height={(size * 80) / 120} viewBox="0 0 120 80" testID={testID}>
      {/* Arm rests and seat. */}
      <Path d="M10 30 C10 22 22 22 22 30 L22 64 L10 64 Z" fill={palette.n300} stroke={palette.n400} strokeWidth={2} strokeLinejoin="round" />
      <Path d="M98 30 C98 22 110 22 110 30 L110 64 L98 64 Z" fill={palette.n300} stroke={palette.n400} strokeWidth={2} strokeLinejoin="round" />
      <Path d="M20 26 C20 18 100 18 100 26 L100 60 L20 60 Z" fill={palette.n200} stroke={palette.n400} strokeWidth={2} strokeLinejoin="round" />
      {/* Three claw lines. */}
      <Path d="M48 30 L56 50" stroke={palette.mintDeep} strokeWidth={3} strokeLinecap="round" />
      <Path d="M58 28 L66 48" stroke={palette.mintDeep} strokeWidth={3} strokeLinecap="round" />
      <Path d="M68 30 L76 50" stroke={palette.mintDeep} strokeWidth={3} strokeLinecap="round" />
      {/* Fluff bits. */}
      <Circle cx={84} cy={66} r={2.5} fill={palette.n200} />
      <Circle cx={92} cy={72} r={2} fill={palette.n200} />
      <Circle cx={40} cy={70} r={2} fill={palette.n200} />
    </Svg>
  );
}

export function SceneGraphic({ scene, size, testID }: SceneGraphicProps & { scene: BehaviourScene }) {
  switch (scene) {
    case 'accident':
      return <PuddleGraphic size={size} testID={testID} />;
    case 'chewing':
      return <ChewedSlipperGraphic size={size} testID={testID} />;
    case 'scratching':
      return <ScratchedSofaGraphic size={size} testID={testID} />;
  }
}
