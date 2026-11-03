/**
 * One design of a wish item (M6-W03, David 2026-11-03): its pre-generated product picture, big
 * and tappable, with its name. A design without a picture (`image: null`) or whose picture fails
 * to load shows a neutral placeholder card (the item icon + the design name) — it never blocks
 * wishing or buying. Never a price here (S5): the parent's price stays on the buy button.
 */

import { useState } from 'react';
import { Image, Pressable, StyleSheet, View } from 'react-native';
import { Check } from 'lucide-react-native';

import { Text } from '@/components/ui/Text';
import { t } from '@/i18n';
import { light, palette, alpha, dark, radius } from '@/theme';
import WishIcon from '@/modules/wishes/WishIcon';
import type { WishDesign, WishVariant } from '@/modules/wishes/wishes';

export interface DesignCardProps {
  design: WishDesign;
  /** The item (its name stands in when the design has none; its icon on the placeholder). */
  variant: WishVariant;
  tone: 'dark' | 'light';
  /** Card width (square picture). */
  size?: number;
  selected?: boolean;
  /** Omitted = a picture only (owned / requested design), not a button. */
  onPress?: () => void;
  testID?: string;
}

/** The name a design card shows: the server's (localized) name, else the item's. */
export function designLabel(design: WishDesign, variant: WishVariant): string {
  return design.name.length > 0 ? design.name : t(`wishes:items.${variant}`);
}

export default function DesignCard({ design, variant, tone, size = 132, selected = false, onPress, testID }: DesignCardProps) {
  const [failed, setFailed] = useState<string | null>(null);
  const label = designLabel(design, variant);
  const showImage = design.image !== null && failed !== design.image;
  const darkTone = tone === 'dark';
  const body = (
    <View style={[styles.card, { width: size }, darkTone ? styles.cardDark : styles.cardLight, selected && (darkTone ? styles.selectedDark : styles.selectedLight)]}>
      <View style={[styles.picture, { width: size - 2, height: size - 2 }, darkTone ? styles.pictureDark : styles.pictureLight]}>
        {showImage ? (
          <Image
            source={{ uri: design.image ?? undefined }}
            resizeMode="cover"
            style={StyleSheet.absoluteFill}
            onError={() => setFailed(design.image)}
            testID={testID ? `${testID}-image` : undefined}
          />
        ) : (
          <View style={styles.placeholder} testID={testID ? `${testID}-placeholder` : undefined}>
            <WishIcon variant={variant} tone={tone} size={Math.round(size * 0.4)} />
          </View>
        )}
        {selected && (
          <View style={styles.check}>
            <Check color={palette.graphite} size={16} />
          </View>
        )}
      </View>
      <Text style={[styles.name, darkTone ? styles.nameDark : styles.nameLight]} numberOfLines={2}>
        {label}
      </Text>
    </View>
  );
  if (onPress === undefined) {
    return (
      <View accessible accessibilityRole="image" accessibilityLabel={label} testID={testID}>
        {body}
      </View>
    );
  }
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={t('wishes:designs.pickA11y', { design: label })}
      accessibilityState={{ selected }}
      style={({ pressed }) => pressed && styles.pressed}
      testID={testID}
    >
      {body}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  card: { borderRadius: radius.card, borderWidth: 2, overflow: 'hidden', paddingBottom: 8, gap: 6 },
  cardDark: { borderColor: dark.glassBorder, backgroundColor: alpha(palette.white, 0.06) },
  cardLight: { borderColor: light.border, backgroundColor: palette.white },
  selectedDark: { borderColor: palette.mint },
  selectedLight: { borderColor: palette.graphite },
  picture: { alignItems: 'center', justifyContent: 'center', overflow: 'hidden' },
  pictureDark: { backgroundColor: alpha(palette.white, 0.04) },
  pictureLight: { backgroundColor: palette.fog },
  placeholder: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  check: { position: 'absolute', top: 6, right: 6, width: 26, height: 26, borderRadius: 13, backgroundColor: palette.mint, alignItems: 'center', justifyContent: 'center' },
  name: { paddingHorizontal: 8, fontSize: 13, fontWeight: '700', textAlign: 'center' },
  nameDark: { color: palette.white },
  nameLight: { color: light.ink },
  pressed: { opacity: 0.8 },
});

/**
 * A small square picture of a design in a list row (owned / requested / wished design); without a
 * picture (or when it fails) the item's own icon — decorative, the row's text carries the name.
 */
export function DesignThumb({ design, variant, tone, size = 52, testID }: { design: WishDesign | null; variant: WishVariant | 'welcome_bundle'; tone: 'dark' | 'light'; size?: number; testID?: string }) {
  const [failed, setFailed] = useState<string | null>(null);
  const image = design?.image ?? null;
  if (image === null || failed === image) return <WishIcon variant={variant} tone={tone} size={size} />;
  return (
    <View style={[thumb.box, { width: size, height: size, borderRadius: size / 4 }]} importantForAccessibility="no-hide-descendants" accessibilityElementsHidden>
      <Image source={{ uri: image }} resizeMode="cover" style={StyleSheet.absoluteFill} onError={() => setFailed(image)} testID={testID} />
    </View>
  );
}

const thumb = StyleSheet.create({
  box: { overflow: 'hidden', backgroundColor: alpha(palette.white, 0.06) },
});
