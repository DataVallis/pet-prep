import { StyleSheet, View } from 'react-native';
import { Text } from '@/components/ui/Text';
import { Lock } from 'lucide-react-native';

import { deviceTimeZone, lockClockWithFallback } from '@/modules/childPet/familyTime';
import { useAppStore, type LockDetails, type LockState } from '@/store/appStore';
import type { VideoState } from '@/modules/petMedia/petMedia';
import { alpha, fonts, palette } from '@/theme';

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

export function lockedCopy(
  lockState: LockState,
  details: LockDetails,
  deviceZone: string | null = deviceTimeZone(),
): { title: string; body: string } {
  switch (lockState) {
    case 'illness': {
      // Server `lock.until` carries the family offset → its own wall clock; UTC → Intl.
      // Without a known family zone (restored session, no state yet): the device zone —
      // never the UTC wall clock (hotfix 2026-10-06: "do 08:41" instead of 10:41).
      const until = lockClockWithFallback(details.until, details.timezone, deviceZone);
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
 * Background of the lock overlay:
 * - `opaque` — game over / inactive (no dog behind);
 * - `grey` — hard stop (sleeping dog), and a vet visit with a real `sick` video;
 * - `ill` — vet visit showing a substitute (free tier: sleeping / idle video, image or
 *   placeholder). A much darker, desaturated veil so the dog reads as unwell instead of
 *   cheerful (2026-10-06, awaiting David's OK). The card text sits on its own dark glass,
 *   so it stays readable on any veil.
 */
export type LockVeil = 'opaque' | 'grey' | 'ill';

// Legacy payloads without a `videos` map report state null → dark veil even if the
// server's current_video_url happens to be a real sick video (acceptable).
export function lockVeil(lockState: LockState, hudVideoState: VideoState | null): LockVeil {
  if (!isTranslucentLock(lockState)) return 'opaque';
  if (lockState === 'illness' && hudVideoState !== 'sick') return 'ill';
  return 'grey';
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
  const hudVideoState = useAppStore((s) => s.hudVideoState);
  const { title, body } = lockedCopy(lockState, details);
  const veil = lockVeil(lockState, hudVideoState);
  const translucent = veil !== 'opaque';

  return (
    <View
      style={[styles.root, veil === 'opaque' ? styles.opaque : veil === 'ill' ? styles.illVeil : styles.translucent]}
      testID="locked-screen"
      accessibilityViewIsModal
    >
      {/* Red ambient glow (opaque locks only) */}
      {!translucent && <View style={styles.glow} testID="locked-glow" />}

      <View
        style={[styles.content, translucent && styles.card]}
        testID={translucent ? 'locked-card-glass' : 'locked-card'}
      >
        <View style={styles.iconCircle}>
          <Lock color={palette.dangerDark} size={48} strokeWidth={2} />
        </View>
        <Text style={styles.title}>{title}</Text>
        <Text style={[styles.body, translucent && styles.bodyOnGlass]}>{body}</Text>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  root: { ...StyleSheet.absoluteFill, zIndex: 50, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 24 },
  opaque: { backgroundColor: palette.graphite },
  /** Grey veil: the dog video stays visible (and playing) underneath. */
  translucent: { backgroundColor: alpha(palette.n600, 0.55) },
  /** Vet visit without a real sick video: dark, cold, washed-out — the dog barely shows through. */
  illVeil: { backgroundColor: alpha(palette.n850, 0.8) },
  glow: {
    position: 'absolute',
    width: 256,
    height: 256,
    borderRadius: 128,
    backgroundColor: alpha(palette.dangerDark, 0.08),
  },
  content: { alignItems: 'center' },
  card: {
    alignItems: 'center',
    paddingHorizontal: 24,
    paddingVertical: 28,
    borderRadius: 28,
    backgroundColor: alpha(palette.graphite, 0.82),
    borderWidth: 1,
    borderColor: alpha(palette.white, 0.12),
  },
  iconCircle: {
    width: 96,
    height: 96,
    borderRadius: 48,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 1,
    borderColor: alpha(palette.dangerDark, 0.3),
    backgroundColor: alpha(palette.dangerDark, 0.1),
  },
  title: {
    marginTop: 32,
    textAlign: 'center',
    fontSize: 24,
    lineHeight: 32,
    fontFamily: fonts.displayBold,
    letterSpacing: -0.6,
    color: palette.white,
  },
  body: {
    marginTop: 12,
    maxWidth: 280,
    textAlign: 'center',
    fontSize: 16,
    lineHeight: 28,
    color: palette.n400,
  },
  bodyOnGlass: { color: palette.n300 },
});
