/** Jest mock of @shopify/react-native-skia: the canvas is a View, drawings render nothing. */
import { type ReactNode } from 'react';
import { View, type ViewProps } from 'react-native';

export function Canvas({ children, ...props }: ViewProps & { children?: ReactNode }) {
  return (
    <View {...props} testID={props.testID ?? 'skia-canvas'}>
      {children}
    </View>
  );
}
const Nothing = () => null;
export const Circle = Nothing;
export const Oval = Nothing;
export const Group = Nothing;
export const Path = Nothing;
export const Rect = Nothing;
export const RoundedRect = Nothing;
export const Line = Nothing;
export const RadialGradient = Nothing;
export const vec = (x: number, y: number) => ({ x, y });
