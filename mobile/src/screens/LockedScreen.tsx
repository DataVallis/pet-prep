import { StyleSheet, Text, View } from 'react-native';
import { Lock } from 'lucide-react-native';

import { lockClock } from '@/modules/childPet/familyTime';
import { useAppStore, type LockDetails, type LockState } from '@/store/appStore';

/** User-visible strings (i18n with M1-18). PRODUCT_SPEC §7 / §8. */
export const LOCKED_STRINGS = {
  hard_stop: {
    title: 'Starš je ustavil igro',
    body: 'Simulacija je začasno ustavljena. Pogovori se s starši.',
  },
  illness: {
    title: 'Kuža je pri veterinarju',
    body: (until: string) => `Kuža je pri veterinarju do ${until}. Potrebuje počitek.`,
    bodyNoTime: 'Kuža potrebuje počitek. Kmalu se vrne.',
  },
  game_over: {
    title: 'Kuža je v zavetišču',
    body: 'Kuža je odšel v virtualno zavetišče. Pogovori se s starši o novem začetku.',
  },
  inactive: {
    title: 'Igra ni aktivna',
    body: 'Igra trenutno ni aktivna. Prosi starša, da preveri nastavitve.',
  },
} as const;

export function lockedCopy(lockState: LockState, details: LockDetails): { title: string; body: string } {
  switch (lockState) {
    case 'illness': {
      // Server `lock.until` carries the family offset → its own wall clock; UTC → Intl.
      const until = lockClock(details.until, details.timezone);
      return {
        title: LOCKED_STRINGS.illness.title,
        body: until ? LOCKED_STRINGS.illness.body(until) : LOCKED_STRINGS.illness.bodyNoTime,
      };
    }
    case 'game_over':
      return LOCKED_STRINGS.game_over;
    case 'inactive':
      return LOCKED_STRINGS.inactive;
    default:
      return LOCKED_STRINGS.hard_stop;
  }
}

/**
 * Locks that keep the dog visible (PRODUCT_SPEC §7 illness: "zaslon sivo, video težkega
 * dihanja"; the parent's pause shows the sleeping dog): the state video keeps playing
 * under a translucent grey layer. Game over / inactive stay opaque, without video.
 */
export function isTranslucentLock(lockState: LockState): boolean {
  return lockState === 'illness' || lockState === 'hard_stop';
}

/**
 * Full-screen lock overlay over the child HUD (M1-16). The HUD stays mounted below it,
 * so live updates keep arriving and the overlay disappears as soon as the server lifts
 * the lock (hard stop off, back from the vet). Vet visit / hard stop: translucent grey
 * over the playing dog video, the texts on a dark glass card; game over / inactive: opaque.
 */
export default function LockedScreen() {
  const lockState = useAppStore((s) => s.lockState);
  const details = useAppStore((s) => s.lockDetails);
  const { title, body } = lockedCopy(lockState, details);
  const translucent = isTranslucentLock(lockState);

  return (
    <View
      className="absolute inset-0 z-50 items-center justify-center px-6"
      style={[styles.root, translucent ? styles.translucent : styles.opaque]}
      testID="locked-screen"
      accessibilityViewIsModal
    >
      {/* Red ambient glow (opaque locks only) */}
      {!translucent && <View className="absolute h-64 w-64 rounded-full bg-rose-600/15" />}

      <View className="items-center" style={translucent ? styles.card : undefined} testID={translucent ? 'locked-card-glass' : 'locked-card'}>
        <View className="h-24 w-24 items-center justify-center rounded-full border border-rose-500/30 bg-rose-500/10">
          <Lock color="#ef4444" size={48} strokeWidth={2} />
        </View>
        <Text className="mt-8 text-center text-2xl font-bold tracking-tight text-white">{title}</Text>
        <Text
          className="mt-3 max-w-[280px] text-center text-base leading-7 text-slate-400"
          style={translucent ? styles.bodyOnGlass : undefined}
        >
          {body}
        </Text>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  root: { ...StyleSheet.absoluteFill, zIndex: 50, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 24 },
  opaque: { backgroundColor: '#000000' },
  /** Grey veil: the dog video stays visible (and playing) underneath. */
  translucent: { backgroundColor: 'rgba(71, 85, 105, 0.55)' },
  card: {
    alignItems: 'center',
    paddingHorizontal: 24,
    paddingVertical: 28,
    borderRadius: 28,
    backgroundColor: 'rgba(15, 23, 42, 0.82)',
    borderWidth: 1,
    borderColor: 'rgba(255, 255, 255, 0.12)',
  },
  bodyOnGlass: { color: '#cbd5e1' },
});
