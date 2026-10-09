/**
 * Free-tier cat graphics for the cat mini-games (M5-R06-08a; David 2026-10-06: free =
 * graphics, premium = the pet's own AI video). Drawn with react-native-svg like
 * `BehaviourGraphics` — no bitmaps, no emoji, calm colours on the dark glass HUD. The
 * feather carries the screen's one raspberry accent (brand: ≤ 1 tiny accent per screen).
 */

import Svg, { Circle, Ellipse, G, Line, Path, Rect } from 'react-native-svg';

import { palette } from '@/theme';

export type CatPose = 'sit' | 'crouch' | 'pounce' | 'happy';

export interface CatFigureProps {
  size?: number;
  pose?: CatPose;
  testID?: string;
}

/** A friendly sitting cat (fog-grey, mint eyes). viewBox 120 × 120. */
export function CatFigure({ size = 120, pose = 'sit', testID = 'cat-figure' }: CatFigureProps) {
  const crouch = pose === 'crouch';
  const pounce = pose === 'pounce';
  const happy = pose === 'happy';
  // Crouching: lower body and head; pouncing: stretched up, front paws raised.
  const bodyRy = crouch ? 18 : pounce ? 30 : 26;
  const bodyCy = crouch ? 92 : pounce ? 74 : 82;
  const headCy = crouch ? 66 : pounce ? 36 : 46;
  return (
    <Svg width={size} height={size} viewBox="0 0 120 120" testID={testID}>
      {/* Tail */}
      <Path
        d={happy ? 'M86 96 C108 92 110 64 98 56' : 'M84 100 C104 104 112 86 104 74'}
        stroke={palette.n300}
        strokeWidth={8}
        strokeLinecap="round"
        fill="none"
      />
      {/* Body */}
      <Ellipse cx={60} cy={bodyCy} rx={28} ry={bodyRy} fill={palette.n200} stroke={palette.n400} strokeWidth={2} />
      {/* Front paws */}
      {pounce ? (
        <G>
          <Ellipse cx={44} cy={52} rx={7} ry={5} fill={palette.n100} stroke={palette.n400} strokeWidth={1.5} />
          <Ellipse cx={76} cy={52} rx={7} ry={5} fill={palette.n100} stroke={palette.n400} strokeWidth={1.5} />
        </G>
      ) : (
        <G>
          <Ellipse cx={50} cy={bodyCy + bodyRy - 3} rx={8} ry={5} fill={palette.n100} stroke={palette.n400} strokeWidth={1.5} />
          <Ellipse cx={70} cy={bodyCy + bodyRy - 3} rx={8} ry={5} fill={palette.n100} stroke={palette.n400} strokeWidth={1.5} />
        </G>
      )}
      {/* Head + ears */}
      <Path d={`M38 ${headCy - 4} L42 ${headCy - 28} L56 ${headCy - 14} Z`} fill={palette.n200} stroke={palette.n400} strokeWidth={2} strokeLinejoin="round" />
      <Path d={`M82 ${headCy - 4} L78 ${headCy - 28} L64 ${headCy - 14} Z`} fill={palette.n200} stroke={palette.n400} strokeWidth={2} strokeLinejoin="round" />
      <Circle cx={60} cy={headCy} r={22} fill={palette.n200} stroke={palette.n400} strokeWidth={2} />
      {/* Eyes: round and focused while hunting, happy arcs otherwise. */}
      {happy ? (
        <G>
          <Path d={`M48 ${headCy - 2} Q52 ${headCy - 7} 56 ${headCy - 2}`} stroke={palette.graphite} strokeWidth={2.5} fill="none" strokeLinecap="round" />
          <Path d={`M64 ${headCy - 2} Q68 ${headCy - 7} 72 ${headCy - 2}`} stroke={palette.graphite} strokeWidth={2.5} fill="none" strokeLinecap="round" />
        </G>
      ) : (
        <G>
          <Ellipse cx={52} cy={headCy - 3} rx={4.5} ry={crouch || pounce ? 5.5 : 4.5} fill={palette.mint} stroke={palette.graphite} strokeWidth={1.5} />
          <Ellipse cx={68} cy={headCy - 3} rx={4.5} ry={crouch || pounce ? 5.5 : 4.5} fill={palette.mint} stroke={palette.graphite} strokeWidth={1.5} />
          <Ellipse cx={52} cy={headCy - 3} rx={1.5} ry={3} fill={palette.graphite} />
          <Ellipse cx={68} cy={headCy - 3} rx={1.5} ry={3} fill={palette.graphite} />
        </G>
      )}
      {/* Nose + whiskers */}
      <Path d={`M57 ${headCy + 6} L63 ${headCy + 6} L60 ${headCy + 9} Z`} fill={palette.n500} />
      <Line x1={40} y1={headCy + 8} x2={52} y2={headCy + 9} stroke={palette.n400} strokeWidth={1.2} />
      <Line x1={80} y1={headCy + 8} x2={68} y2={headCy + 9} stroke={palette.n400} strokeWidth={1.2} />
    </Svg>
  );
}

/** A feather on a short string (the wand's end). viewBox 48 × 48; the string's knot is at the top. */
export function FeatherGraphic({ size = 48, testID = 'cat-feather' }: { size?: number; testID?: string }) {
  return (
    <Svg width={size} height={size} viewBox="0 0 48 48" testID={testID}>
      <Line x1={24} y1={0} x2={24} y2={12} stroke={palette.n300} strokeWidth={1.5} />
      <Path d="M24 12 C36 18 36 34 24 46 C12 34 12 18 24 12 Z" fill={palette.raspberry} fillOpacity={0.9} />
      <Line x1={24} y1={14} x2={24} y2={44} stroke={palette.white} strokeOpacity={0.7} strokeWidth={1.2} />
    </Svg>
  );
}

export interface TrayGraphicProps {
  size?: number;
  /** Clumps still in the litter (scoop) — drawn as small darker lumps. */
  clumps?: number;
  /** Litter change step: 1 full (old), 2 empty + bubbles (washing), 3 fresh litter. */
  step?: 1 | 2 | 3;
  testID?: string;
}

/** The litter box seen from the front. viewBox 160 × 90. */
export function TrayGraphic({ size = 160, clumps = 0, step, testID = 'cat-tray' }: TrayGraphicProps) {
  const empty = step === 2;
  return (
    <Svg width={size} height={(size * 90) / 160} viewBox="0 0 160 90" testID={testID}>
      <Path d="M12 30 L148 30 L136 84 L24 84 Z" fill={palette.n700} stroke={palette.n400} strokeWidth={2} strokeLinejoin="round" />
      {!empty && <Path d="M20 40 C50 34 110 34 140 40 L134 70 L26 70 Z" fill={step === 3 ? palette.n100 : palette.n300} />}
      {empty && (
        <G>
          <Circle cx={60} cy={56} r={6} fill={palette.white} fillOpacity={0.35} />
          <Circle cx={80} cy={48} r={4} fill={palette.white} fillOpacity={0.3} />
          <Circle cx={100} cy={58} r={7} fill={palette.white} fillOpacity={0.25} />
        </G>
      )}
      {Array.from({ length: Math.max(0, Math.min(5, clumps)) }, (_, i) => (
        <Ellipse key={i} cx={44 + i * 18} cy={48 + (i % 2) * 8} rx={7} ry={5} fill={palette.n500} />
      ))}
    </Svg>
  );
}

/** A sisal scratching post on a base. viewBox 60 × 120. */
export function ScratchingPostGraphic({ size = 120, testID = 'cat-scratching-post' }: { size?: number; testID?: string }) {
  return (
    <Svg width={(size * 60) / 120} height={size} viewBox="0 0 60 120" testID={testID}>
      <Rect x={4} y={108} width={52} height={10} rx={4} fill={palette.n500} />
      <Rect x={20} y={12} width={20} height={98} rx={3} fill={palette.n300} />
      {Array.from({ length: 9 }, (_, i) => (
        <Line key={i} x1={20} y1={20 + i * 10} x2={40} y2={24 + i * 10} stroke={palette.n500} strokeWidth={2} />
      ))}
      <Rect x={10} y={4} width={40} height={10} rx={4} fill={palette.n400} />
    </Svg>
  );
}
