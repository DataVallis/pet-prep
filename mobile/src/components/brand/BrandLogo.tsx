/**
 * CGP v2 logos, drawn with react-native-svg from the same geometry as `brand/logo/*.svg`
 * (crisp at any size, no bitmaps). Rules (`brand/README.md`): don't recolour, stretch,
 * outline or shadow; clear space = one eye; minimum size mark 20 px, horizontal logo 110 px.
 *
 * - `BrandMark` — the "Radovednež" face. `tone="light"` (graphite face, for light
 *   backgrounds), `"dark"` (mint face, for dark backgrounds), `"mono"` (one colour).
 * - `BrandLogo` — mark + "petprep" wordmark, `layout="horizontal" | "stacked"`.
 */

import { View, type StyleProp, type ViewStyle } from 'react-native';
import Svg, { Circle, G, Path, Rect } from 'react-native-svg';

import { palette } from '@/theme';

import { WORDMARK_GLYPHS } from './wordmarkGlyphs';

export type BrandTone = 'light' | 'dark' | 'mono';

const BRAND_LABEL = 'PetPrep';

function markColours(tone: BrandTone, monoColor: string) {
  if (tone === 'dark') return { face: palette.mint, eye: palette.graphite, pupil: palette.mint, nose: palette.raspberry };
  if (tone === 'mono') return { face: monoColor, eye: palette.white, pupil: monoColor, nose: palette.white };
  return { face: palette.graphite, eye: palette.white, pupil: palette.graphite, nose: palette.raspberry };
}

/** The mark's shapes in its own 100 × 100 box (rx 30, pupils looking up-right). */
function MarkShapes({ tone, monoColor = palette.graphite }: { tone: BrandTone; monoColor?: string }) {
  const c = markColours(tone, monoColor);
  return (
    <>
      <Rect x={6} y={10} width={88} height={80} rx={30} fill={c.face} />
      <Circle cx={35} cy={47} r={15} fill={c.eye} />
      <Circle cx={65} cy={47} r={15} fill={c.eye} />
      <Circle cx={40} cy={42} r={6.5} fill={c.pupil} />
      <Circle cx={70} cy={42} r={6.5} fill={c.pupil} />
      <Rect x={43} y={69} width={14} height={8} rx={4} fill={c.nose} />
    </>
  );
}

function WordmarkShapes({ color }: { color: string }) {
  return (
    <>
      {WORDMARK_GLYPHS.map((g) => (
        <Path key={g.x} transform={`translate(${g.x} 0)`} d={g.d} fill={color} />
      ))}
    </>
  );
}

export interface BrandMarkProps {
  size?: number;
  tone?: BrandTone;
  /** Face colour for `tone="mono"`. */
  monoColor?: string;
  style?: StyleProp<ViewStyle>;
  testID?: string;
}

export function BrandMark({ size = 40, tone = 'light', monoColor, style, testID = 'brand-mark' }: BrandMarkProps) {
  return (
    <View style={style} accessible accessibilityRole="image" accessibilityLabel={BRAND_LABEL} testID={testID}>
      <Svg width={Math.max(20, size)} height={Math.max(20, size)} viewBox="0 0 100 100">
        <MarkShapes tone={tone} monoColor={monoColor} />
      </Svg>
    </View>
  );
}

export interface BrandLogoProps {
  /** Rendered width in pt (height follows the logo's aspect ratio). */
  width?: number;
  layout?: 'horizontal' | 'stacked';
  /** `light` = for light backgrounds (graphite), `dark` = for dark backgrounds (mint mark, fog wordmark). */
  tone?: 'light' | 'dark';
  style?: StyleProp<ViewStyle>;
  testID?: string;
}

const LAYOUTS = {
  horizontal: { w: 514, h: 150, mark: 'translate(0 22) scale(0.96)', word: 'translate(118 102)', min: 110 },
  stacked: { w: 410, h: 330, mark: 'translate(115 0) scale(1.8)', word: 'translate(10.1 290)', min: 90 },
} as const;

export function BrandLogo({ width = 180, layout = 'horizontal', tone = 'light', style, testID = 'brand-logo' }: BrandLogoProps) {
  const l = LAYOUTS[layout];
  const w = Math.max(l.min, width);
  const ink = tone === 'dark' ? palette.fog : palette.graphite;
  return (
    <View style={style} accessible accessibilityRole="image" accessibilityLabel={BRAND_LABEL} testID={testID}>
      <Svg width={w} height={(w * l.h) / l.w} viewBox={`0 0 ${l.w} ${l.h}`}>
        <G transform={l.mark}>
          <MarkShapes tone={tone} />
        </G>
        <G transform={l.word}>
          <WordmarkShapes color={ink} />
        </G>
      </Svg>
    </View>
  );
}
