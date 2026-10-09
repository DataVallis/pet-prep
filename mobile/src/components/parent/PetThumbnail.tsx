/**
 * Small reference-image thumbnail of a pet for the parent's light UI (M4-03 app side).
 * No video (one card per child — players would cost battery for nothing). Pending →
 * a soft placeholder with "Kuža se pripravlja…" / "Muca se pripravlja…" as the accessibility hint; failed /
 * disabled / broken image → the paw placeholder. Re-signed URLs of the same image
 * don't reload it (`useStableUrl`).
 */

import { Image, StyleSheet, View } from 'react-native';
import { PawPrint } from 'lucide-react-native';

import { PARENT_COLORS as C } from '@/components/parent/ParentUi';
import { tSpecies } from '@/i18n';
import { useStableUrl } from '@/modules/petMedia/hooks';
import type { PetMediaInfo } from '@/modules/petMedia/petMedia';

interface PetThumbnailProps {
  media: PetMediaInfo;
  size?: number;
  /** M5-R06-08c: the pet's species ("Muca se pripravlja…" for a cat). */
  species?: string | null;
  testID?: string;
}

export default function PetThumbnail({ media, size = 56, species = null, testID = 'pet-thumbnail' }: PetThumbnailProps) {
  const image = useStableUrl(media.referenceImageUrl);
  const box = { width: size, height: size, borderRadius: Math.round(size / 4) };

  if (image.uri === null) {
    const pending = media.status === 'pending';
    return (
      <View
        style={[styles.placeholder, box]}
        testID={`${testID}-${pending ? 'pending' : 'placeholder'}`}
        accessibilityLabel={pending ? tSpecies('pet:media.pendingParent', species) : undefined}
      >
        <PawPrint color={pending ? C.muted : C.accent} size={Math.round(size * 0.45)} />
      </View>
    );
  }

  return (
    <Image
      source={{ uri: image.uri }}
      resizeMode="cover"
      onError={image.onError}
      style={[styles.image, box]}
      testID={`${testID}-image`}
    />
  );
}

const styles = StyleSheet.create({
  placeholder: {
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: C.accentSoft,
  },
  image: {
    backgroundColor: C.accentSoft,
  },
});
