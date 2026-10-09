/**
 * "Počisti pesek" — the scoop mini-game (M5-R06-08a; CAT_SPEC Q3 "mini-igra z lopatko, kot
 * drgnjenje"; server M5-R06-05 `POST /api/child/pet/litter/scoop`).
 *
 * A local tap game like cleaning: the litter shows two clumps per open litter use (2–6);
 * the child scoops each one (a button each — VoiceOver / TalkBack read "Poberi grudico").
 * When the last one is out the app sends ONE scoop request (optimistic: the tray is clean at
 * once, then the server's state). The server scoops every open use and decides whether it
 * was in time (4 h, 2 h while the weekly change is overdue — counted outside quiet hours);
 * a mess that already happened next to the tray stays for the normal cleaning. Nothing
 * to scoop → a calm "the litter is clean".
 */

import { useEffect, useMemo, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { Shovel } from 'lucide-react-native';

import { TrayGraphic } from '@/components/CatGraphics';
import { Text } from '@/components/ui/Text';
import { useScoopLitter } from '@/hooks/queries/useCatCare';
import { SCOOP_STRINGS, catWhen, onlyScratchingOpen } from '@/modules/catCare/catCare';
import { Busy, CatOverlayFrame, PrimaryButton, styles } from '@/modules/catCare/CatGameParts';
import { catFailureText } from '@/modules/catCare/useCatSessionGame';
import type { ChildPetView } from '@/modules/childPet/childPetView';
import { alpha, MIN_TOUCH, palette } from '@/theme';

const S = SCOOP_STRINGS;

/** Clumps to scoop for `uses` open litter uses (two each, 2–6). */
export function clumpCount(uses: number): number {
  return Math.max(2, Math.min(6, uses * 2));
}

/** Fixed, spread-out spots inside the tray (percent of the tray box) — no randomness, testable. */
const SPOTS: readonly { x: number; y: number }[] = [
  { x: 22, y: 38 },
  { x: 48, y: 58 },
  { x: 74, y: 40 },
  { x: 34, y: 70 },
  { x: 62, y: 74 },
  { x: 84, y: 64 },
];

export interface ScoopOverlayProps {
  view: ChildPetView;
  onClose: () => void;
  testID?: string;
}

type ScoopPhase = { kind: 'play' } | { kind: 'saving' } | { kind: 'done'; text: string };

export default function ScoopOverlay({ view, onClose, testID = 'cat-scoop' }: ScoopOverlayProps) {
  const litter = view.cat.litter;
  // Fixed when the overlay opens: the optimistic scoop empties `open_uses` at once.
  const [initialUses] = useState(() => litter?.open_uses ?? []);
  const total = clumpCount(initialUses.length);
  const [scooped, setScooped] = useState<ReadonlySet<number>>(new Set());
  const [phase, setPhase] = useState<ScoopPhase>({ kind: 'play' });
  const { mutate } = useScoopLitter();
  const ctx = useMemo(
    () => ({ nowIso: view.server_time, timezone: view.timezone, scratchingOnly: onlyScratchingOpen(view.cat) }),
    [view.server_time, view.timezone, view.cat],
  );
  const nothing = initialUses.length === 0 || litter === null;
  const messNearby = initialUses.some((u) => u.expired);
  const due = catWhen(litter?.next_due_at ?? null, view.server_time, view.timezone);

  const allScooped = !nothing && scooped.size >= total;
  useEffect(() => {
    if (!allScooped || phase.kind !== 'play') return;
    setPhase({ kind: 'saving' });
    mutate(undefined, {
      onSuccess: (response) => setPhase({ kind: 'done', text: response.status === 'accepted' ? S.success : S.unchanged }),
      onError: (error) => setPhase({ kind: 'done', text: catFailureText(error, ctx) }),
    });
  }, [allScooped, ctx, mutate, phase.kind]);

  return (
    <CatOverlayFrame title={S.title} icon={<Shovel color={palette.mint} size={22} />} onClose={phase.kind === 'saving' ? null : onClose} testID={testID}>
      <ScrollView contentContainerStyle={styles.content}>
        {nothing && phase.kind === 'play' ? (
          <>
            <Text style={styles.body} testID={`${testID}-nothing`}>
              {S.nothing}
            </Text>
            <PrimaryButton label={S.done} onPress={onClose} testID={`${testID}-done`} />
          </>
        ) : (
          <>
            {phase.kind === 'play' && <Text style={styles.body}>{S.intro}</Text>}
            {phase.kind === 'play' && due !== null && (
              <Text style={styles.bodyStrong} testID={`${testID}-due`}>
                {S.dueAt(due)}
              </Text>
            )}
            <View style={local.tray} testID={`${testID}-tray`}>
              <View style={local.trayArt} pointerEvents="none">
                <TrayGraphic size={260} clumps={0} step={3} />
              </View>
              {phase.kind === 'play' &&
                SPOTS.slice(0, total).map((spot, i) =>
                  scooped.has(i) ? null : (
                    <Pressable
                      key={i}
                      testID={`${testID}-clump-${i}`}
                      accessibilityRole="button"
                      accessibilityLabel={S.clumpA11y}
                      hitSlop={6}
                      onPress={() => setScooped((prev) => new Set(prev).add(i))}
                      style={({ pressed }) => [local.clump, { left: `${spot.x}%`, top: `${spot.y}%` }, pressed && local.clumpPressed]}
                    />
                  ),
                )}
            </View>
            {phase.kind === 'play' && (
              <Text style={styles.bodyCenter} testID={`${testID}-progress`} accessibilityLiveRegion="polite">
                {S.progress(scooped.size, total)}
              </Text>
            )}
            {phase.kind === 'saving' && <Busy text={S.saving} testID={`${testID}-saving`} />}
            {phase.kind === 'done' && (
              <>
                <Text style={styles.statusLine} testID={`${testID}-result`} accessibilityLiveRegion="polite">
                  {phase.text}
                </Text>
                <PrimaryButton label={S.done} onPress={onClose} testID={`${testID}-done`} />
              </>
            )}
            {messNearby && (
              <Text style={styles.note} testID={`${testID}-mess`}>
                {S.messNearby}
              </Text>
            )}
          </>
        )}
      </ScrollView>
    </CatOverlayFrame>
  );
}

const CLUMP = MIN_TOUCH;

const local = StyleSheet.create({
  tray: { height: 200, borderRadius: 24, backgroundColor: alpha(palette.white, 0.04), overflow: 'hidden' },
  trayArt: { ...StyleSheet.absoluteFill, alignItems: 'center', justifyContent: 'center' },
  clump: {
    position: 'absolute',
    width: CLUMP,
    height: CLUMP * 0.75,
    marginLeft: -CLUMP / 2,
    marginTop: (-CLUMP * 0.75) / 2,
    borderRadius: CLUMP / 2,
    backgroundColor: palette.n500,
    borderWidth: 2,
    borderColor: palette.n400,
  },
  clumpPressed: { transform: [{ scale: 0.8 }], opacity: 0.7 },
});

