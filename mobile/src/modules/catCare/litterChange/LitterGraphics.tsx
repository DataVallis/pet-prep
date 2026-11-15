/**
 * Draws the litter change's shape data (`litterArt.ts`) with react-native-svg. Static pictures —
 * everything that moves is a `View` transform around them (`LitterChangeGame`).
 */

import Svg, { Circle, Ellipse, Path, Rect } from 'react-native-svg';

import type { Art, Shape } from '@/modules/catCare/litterChange/litterArt';

function shapeNode(s: Shape, key: number) {
  switch (s.t) {
    case 'path':
      return (
        <Path
          key={key}
          d={s.d}
          fill={s.fill ?? 'none'}
          fillOpacity={s.fillOpacity}
          fillRule={s.evenOdd ? 'evenodd' : undefined}
          stroke={s.stroke}
          strokeWidth={s.strokeWidth}
          strokeOpacity={s.strokeOpacity}
          strokeDasharray={s.dash}
          strokeLinecap="round"
          strokeLinejoin="round"
        />
      );
    case 'circle':
      return <Circle key={key} cx={s.cx} cy={s.cy} r={s.r} fill={s.fill ?? 'none'} fillOpacity={s.fillOpacity} stroke={s.stroke} strokeWidth={s.strokeWidth} strokeOpacity={s.strokeOpacity} />;
    case 'ellipse':
      return <Ellipse key={key} cx={s.cx} cy={s.cy} rx={s.rx} ry={s.ry} fill={s.fill ?? 'none'} fillOpacity={s.fillOpacity} stroke={s.stroke} strokeWidth={s.strokeWidth} />;
    case 'rect':
      return <Rect key={key} x={s.x} y={s.y} width={s.w} height={s.h} rx={s.rx} fill={s.fill ?? 'none'} fillOpacity={s.fillOpacity} stroke={s.stroke} strokeWidth={s.strokeWidth} />;
  }
}

/** One drawing, `width` wide (height follows its viewBox). */
export function ArtView({ art, width, testID }: { art: Art; width: number; testID?: string }) {
  return (
    <Svg width={width} height={(width * art.h) / art.w} viewBox={`0 0 ${art.w} ${art.h}`} testID={testID}>
      {art.shapes.map(shapeNode)}
    </Svg>
  );
}
