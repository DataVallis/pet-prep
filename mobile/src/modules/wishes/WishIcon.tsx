/**
 * Placeholder picture of a wish item (M6-W04): a round mint-tinted disc with a Lucide icon
 * (brand: icons = Lucide, 2 px stroke). Real AI item art is pending (M6-W03) — replace the
 * icon with the item's key image then. Decorative: the row's text carries the name.
 */

import { StyleSheet, View } from 'react-native';
import { BedDouble, Bone, Cookie, Feather, Gift, Rat, ShoppingBasket, Soup, Spline } from 'lucide-react-native';

import { alpha, palette } from '@/theme';
import type { WishVariant } from '@/modules/wishes/wishes';

const ICONS: Readonly<Record<WishVariant | 'welcome_bundle', typeof Gift>> = {
  dog_bed: BedDouble,
  cat_basket: ShoppingBasket,
  dog_bowls: Soup,
  cat_bowls: Soup,
  dog_rope: Spline,
  cat_mouse: Rat,
  dog_squeaky: Bone,
  cat_feather: Feather,
  dog_treats: Bone,
  cat_treats: Cookie,
  welcome_bundle: Gift,
};

export interface WishIconProps {
  variant: WishVariant | 'welcome_bundle';
  tone: 'dark' | 'light';
  size?: number;
  testID?: string;
}

export default function WishIcon({ variant, tone, size = 56, testID }: WishIconProps) {
  const Icon = ICONS[variant];
  return (
    <View
      style={[
        styles.disc,
        { width: size, height: size, borderRadius: size / 2 },
        tone === 'dark' ? styles.discDark : styles.discLight,
      ]}
      accessible={false}
      importantForAccessibility="no-hide-descendants"
      testID={testID}
    >
      <Icon color={tone === 'dark' ? palette.mint : palette.mintDeep} size={Math.round(size * 0.5)} strokeWidth={2} />
    </View>
  );
}

const styles = StyleSheet.create({
  disc: { alignItems: 'center', justifyContent: 'center', borderWidth: 1 },
  discDark: { backgroundColor: alpha(palette.mint, 0.12), borderColor: alpha(palette.mint, 0.35) },
  discLight: { backgroundColor: palette.mintSoft, borderColor: palette.mintBorder },
});
