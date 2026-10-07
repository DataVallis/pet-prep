/**
 * One child on the parent's family overview (M2-05, PRODUCT_SPEC §9 / §11): traffic
 * light with friendly reasons, Care Score ("x od y rutin", illnesses), 12-week
 * progress, today's routines (missed ones with type and family-local time), the
 * last 7 days as simple bars, and the pet's mini status with its AI reference image
 * thumbnail (M4-03). All numbers come from the
 * server — nothing is scored on the phone. M5-R02: missed cleans name their mess
 * (luža / pregrizen copat), the pet block lists the puppy's bladder clock and open
 * messes, and the 7-day take-outs / tidied slippers. M5-F01: "12-week challenge — buy"
 * under the header while the dog's challenge can be bought (`canBuyChallenge`).
 */

import { Pressable, StyleSheet, View } from 'react-native';
import { Text } from '@/components/ui/Text';
import { ChevronRight, KeyRound } from 'lucide-react-native';

import {
  Card,
  MetricRow,
  PARENT_COLORS as C,
  RoutineIcon,
  TrafficLightBadge,
} from '@/components/parent/ParentUi';
import {
  PET_STATUS_LABELS,
  breedLabel,
  petStatus,
  type FamilyChild,
  type FamilyPet,
} from '@/modules/family/family';
import {
  illnessesText,
  missedLabel,
  missedWhenText,
  progressShare,
  progressText,
  reasonText,
  scoreRoutinesText,
  weekdayShort,
  type DayRow,
} from '@/modules/family/scoring';
import PetThumbnail from '@/components/parent/PetThumbnail';
import PlanBadge from '@/components/parent/PlanBadge';
import ChallengeBuyButton from '@/components/parent/ChallengeBuyButton';
import { canBuyChallenge } from '@/modules/plan/purchaseEntry';
import { normalizePetMedia } from '@/modules/petMedia/petMedia';
import { PARENT_BEHAVIOUR_STRINGS, parentBehaviourLines } from '@/modules/behaviour/behaviour';
import { fonts, palette, tightTracking } from '@/theme';
import { t } from '@/i18n';
import { strings } from '@/i18n/strings';

/** All user-visible strings of the card (`parent:childCard`, M1-18). */
export const CHILD_CARD_STRINGS = strings('parent', 'childCard', {
  illnessPenalty: (n: number) => t('parent:childCard.illnessPenalty', { illnesses: illnessesText(n), points: n * 10 }),
  todayDone: (n: number) => t('parent:childCard.todayDone', { n }),
  todayOwn: (name: string, n: number) => t('parent:childCard.todayOwn', { name, n }),
  todayPending: (n: number) => t('parent:childCard.todayPending', { n }),
  todayMissed: (n: number) => t('parent:childCard.todayMissed', { n }),
  awaitingContract: (name: string) => t('parent:childCard.awaitingContract', { name }),
  detailsA11y: (name: string) => t('parent:childCard.detailsA11y', { name }),
  behaviourStats: (days: number, text: string) => `${PARENT_BEHAVIOUR_STRINGS.lastDays(days)}: ${text}`,
});

const S = CHILD_CARD_STRINGS;

interface ChildOverviewCardProps {
  child: FamilyChild;
  pet: FamilyPet | null;
  timezone: string;
  onOpen: (child: FamilyChild) => void;
  onChildPin: (child: FamilyChild) => void;
  /** M3-09: the plan badge of an unpaid challenge opens the paywall (pay any time, QA PR #67 m6). */
  onOpenChallenge?: () => void;
}

function WeekBars({ days }: { days: DayRow[] }) {
  const max = Math.max(1, ...days.map((d) => d.done + d.missed));
  return (
    <View style={styles.weekRow} accessibilityLabel={S.week}>
      {days.map((d) => (
        <View key={d.date} style={styles.weekCol} testID={`week-day-${d.date}`}>
          <View style={styles.weekTrack}>
            <View style={[styles.weekMissed, { height: `${(d.missed / max) * 100}%` }]} />
            <View style={[styles.weekDone, { height: `${(d.done / max) * 100}%` }]} />
          </View>
          <Text style={styles.weekLabel}>{weekdayShort(d.date)}</Text>
        </View>
      ))}
    </View>
  );
}

export default function ChildOverviewCard({ child, pet, timezone, onOpen, onChildPin, onOpenChallenge }: ChildOverviewCardProps) {
  const id = child.id;
  const status = pet ? petStatus(pet) : null;
  const waitsForContract = pet !== null && (pet.awaiting_contract || !child.contract_signed);
  const score = child.care_score;
  const today = child.today;
  const progress = progressText(child.progress);
  const behaviourLines = pet ? parentBehaviourLines(pet.behaviour, timezone) : [];
  const behaviourStats = PARENT_BEHAVIOUR_STRINGS.stats(child.stats.taken_out, child.stats.chewing_resolved);

  return (
    <Card testID={`child-card-${id}`}>
      <View style={styles.header}>
        <View style={styles.avatar}>
          <Text style={styles.avatarText}>{child.name.slice(0, 1).toUpperCase()}</Text>
        </View>
        <View style={styles.flex}>
          <Text style={styles.name}>{child.name}</Text>
          {pet && <Text style={styles.muted}>{breedLabel(pet.breed_type)}</Text>}
          {pet && !pet.is_game_over && (
            <PlanBadge
              plan={pet.plan}
              onPress={pet.plan.type === 'challenge' && pet.plan.status !== 'paid' ? onOpenChallenge : undefined}
              testID={`child-plan-${id}`}
            />
          )}
        </View>
        <TrafficLightBadge color={child.traffic_light.color} testID={`child-light-${id}-${child.traffic_light.color}`} />
      </View>

      {/* M5-F01: the badge alone was overlooked on a device — a clear entry until the challenge is paid. */}
      {onOpenChallenge && canBuyChallenge(pet) && (
        <ChallengeBuyButton onPress={onOpenChallenge} childName={child.name} testID={`child-buy-${id}`} />
      )}

      {child.traffic_light.reasons.length > 0 && (
        <View style={styles.reasons}>
          {child.traffic_light.reasons.map((r) => (
            <Text key={r} style={styles.reason} testID={`child-reason-${id}-${r}`}>
              • {reasonText(r, today.missed_count)}
            </Text>
          ))}
        </View>
      )}

      {pet === null ? (
        <View style={styles.block}>
          <Text style={styles.muted} testID={`child-no-pet-${id}`}>
            {S.noPet}
          </Text>
          <Pressable
            style={({ pressed }) => [styles.secondaryButton, pressed && styles.pressed]}
            onPress={() => onChildPin(child)}
            accessibilityRole="button"
            testID={`child-card-pin-${id}`}
          >
            <KeyRound color={C.accent} size={15} />
            <Text style={styles.secondaryText}>{S.createPin}</Text>
          </Pressable>
        </View>
      ) : waitsForContract && !pet.is_game_over ? (
        <Text style={styles.muted} testID={`child-awaiting-${id}`}>
          {S.awaitingContract(child.name)}
        </Text>
      ) : (
        <>
          {/* Care Score */}
          <View style={styles.scoreRow}>
            <View style={styles.flex}>
              <Text style={styles.label}>{S.scoreTitle}</Text>
              {score.score === null ? (
                <>
                  <Text style={styles.noScore} testID={`child-score-${id}`}>
                    {S.noScore}
                  </Text>
                  <Text style={styles.muted}>{S.noScoreHint}</Text>
                </>
              ) : (
                <>
                  <Text style={styles.score} testID={`child-score-${id}`}>
                    {score.score}
                  </Text>
                  <Text style={styles.muted} testID={`child-score-routines-${id}`}>
                    {scoreRoutinesText(score)}
                  </Text>
                </>
              )}
              {score.illnesses > 0 && (
                <Text style={styles.penalty} testID={`child-illnesses-${id}`}>
                  {S.illnessPenalty(score.illnesses)}
                </Text>
              )}
            </View>
            {progress && (
              <View style={styles.progressCol}>
                <Text style={styles.progressText} testID={`child-progress-${id}`}>
                  {progress}
                </Text>
                <View style={styles.progressTrack}>
                  <View style={[styles.progressFill, { width: `${progressShare(child.progress) * 100}%` }]} />
                </View>
              </View>
            )}
          </View>

          {/* Today */}
          <View style={styles.block}>
            <Text style={styles.label}>{S.today}</Text>
            {today.expected === 0 ? (
              <Text style={styles.muted}>{S.todayNothing}</Text>
            ) : (
              <View style={styles.chips} testID={`child-today-${id}`}>
                <Text style={[styles.chip, styles.chipDone]}>
                  {S.todayDone(today.done)}
                  {today.done_by_child !== null && today.done_by_child !== today.done
                    ? ` (${S.todayOwn(child.name, today.done_by_child)})`
                    : ''}
                </Text>
                {today.pending > 0 && <Text style={styles.chip}>{S.todayPending(today.pending)}</Text>}
                {today.missed_count > 0 && (
                  <Text style={[styles.chip, styles.chipMissed]}>{S.todayMissed(today.missed_count)}</Text>
                )}
              </View>
            )}
            {behaviourStats !== '' && (
              <Text style={styles.muted} testID={`child-behaviour-stats-${id}`}>
                {S.behaviourStats(child.stats.days, behaviourStats)}
              </Text>
            )}
            {today.missed.map((m, i) => (
              <View key={`${m.type}-${m.due_at}-${i}`} style={styles.missedRow} testID={`child-missed-${id}-${i}`}>
                <RoutineIcon type={m.type} color={C.redText} />
                <Text style={styles.missedLabel}>{missedLabel(m)}</Text>
                <Text style={styles.muted}>{missedWhenText(m, timezone, today.date)}</Text>
              </View>
            ))}
          </View>

          {child.last_7_days.length > 0 && (
            <View style={styles.block}>
              <View style={styles.legendRow}>
                <Text style={styles.label}>{S.week}</Text>
                <View style={styles.legend}>
                  <View style={[styles.legendDot, { backgroundColor: C.chartDone }]} />
                  <Text style={styles.legendText}>{S.legendDone}</Text>
                  <View style={[styles.legendDot, { backgroundColor: C.chartMissed }]} />
                  <Text style={styles.legendText}>{S.legendMissed}</Text>
                </View>
              </View>
              <WeekBars days={child.last_7_days} />
            </View>
          )}
        </>
      )}

      {pet !== null && (
        <View style={styles.block} testID={`child-pet-${id}`}>
          <View style={styles.petHeader}>
            <PetThumbnail media={normalizePetMedia(pet.media)} size={56} testID={`child-pet-thumb-${id}`} />
            <View style={styles.flex}>
              <Text style={styles.label}>{S.pet}</Text>
              {status && (
                <Text style={[styles.status, status === 'game_over' && styles.statusRed]} testID={`child-pet-status-${id}`}>
                  {PET_STATUS_LABELS[status]}
                </Text>
              )}
            </View>
          </View>
          <MetricRow label={S.metrics.hunger} value={pet.metrics.hunger} />
          <MetricRow label={S.metrics.thirst} value={pet.metrics.thirst} />
          <MetricRow label={S.metrics.energy} value={pet.metrics.energy} />
          <MetricRow label={S.metrics.hygiene} value={pet.metrics.hygiene} testID={`child-pet-hygiene-${id}`} />
          {behaviourLines.length > 0 && (
            <View style={styles.behaviour} testID={`child-pet-behaviour-${id}`}>
              {behaviourLines.map((line) => (
                <Text key={line} style={styles.behaviourLine}>
                  • {line}
                </Text>
              ))}
            </View>
          )}
        </View>
      )}

      <Pressable
        style={({ pressed }) => [styles.detailsButton, pressed && styles.pressed]}
        onPress={() => onOpen(child)}
        accessibilityRole="button"
        accessibilityLabel={S.detailsA11y(child.name)}
        testID={`child-details-${id}`}
      >
        <Text style={styles.detailsText}>{S.details}</Text>
        <ChevronRight color={C.accent} size={16} />
      </Pressable>
    </Card>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  header: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  avatar: {
    width: 42,
    height: 42,
    borderRadius: 21,
    backgroundColor: C.accentSoft,
    alignItems: 'center',
    justifyContent: 'center',
  },
  avatarText: { fontSize: 18, letterSpacing: tightTracking(18), fontFamily: fonts.displayBold, color: C.accent },
  name: { fontSize: 17, letterSpacing: tightTracking(17), fontFamily: fonts.displayBold, color: C.text },
  muted: { fontSize: 13, color: C.muted, lineHeight: 18 },
  reasons: { gap: 2 },
  reason: { fontSize: 13, color: C.text, lineHeight: 18 },
  block: { gap: 8, paddingTop: 12, borderTopWidth: 1, borderTopColor: C.divider },
  label: { fontSize: 12, fontWeight: '700', color: C.muted, textTransform: 'uppercase', letterSpacing: 0.4 },
  scoreRow: { flexDirection: 'row', gap: 16, alignItems: 'flex-start' },
  score: { fontSize: 44, fontWeight: '800', color: C.text, fontVariant: ['tabular-nums'], lineHeight: 50 },
  noScore: { fontSize: 17, fontWeight: '700', color: C.text, marginTop: 4 },
  penalty: { fontSize: 12, color: C.redText, marginTop: 2 },
  progressCol: { width: 120, gap: 6, paddingTop: 18 },
  progressText: { fontSize: 13, fontWeight: '700', color: C.text, textAlign: 'right' },
  progressTrack: { height: 6, borderRadius: 3, backgroundColor: C.track, overflow: 'hidden' },
  progressFill: { height: '100%', backgroundColor: palette.mint, borderRadius: 3 },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 6 },
  chip: {
    fontSize: 12,
    fontWeight: '600',
    color: C.text,
    backgroundColor: C.divider,
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: 999,
    overflow: 'hidden',
  },
  chipDone: { backgroundColor: C.greenSoft, color: C.greenText },
  chipMissed: { backgroundColor: C.redSoft, color: C.redText },
  missedRow: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  missedLabel: { fontSize: 14, fontWeight: '600', color: C.text, minWidth: 70 },
  behaviour: { gap: 2, paddingTop: 4 },
  behaviourLine: { fontSize: 13, color: C.text, lineHeight: 18 },
  legendRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  legend: { flexDirection: 'row', alignItems: 'center', gap: 4 },
  legendDot: { width: 8, height: 8, borderRadius: 4, marginLeft: 6 },
  legendText: { fontSize: 11, color: C.muted },
  weekRow: { flexDirection: 'row', gap: 6, height: 74 },
  weekCol: { flex: 1, alignItems: 'center', gap: 4 },
  weekTrack: {
    flex: 1,
    width: '70%',
    borderRadius: 6,
    backgroundColor: C.divider,
    overflow: 'hidden',
    justifyContent: 'flex-end',
  },
  weekDone: { width: '100%', backgroundColor: C.chartDone },
  weekMissed: { width: '100%', backgroundColor: C.chartMissed },
  weekLabel: { fontSize: 10, fontWeight: '600', color: C.muted },
  status: { fontSize: 13, fontWeight: '600', color: C.yellowText },
  statusRed: { color: C.redText },
  petHeader: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  secondaryButton: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    alignSelf: 'flex-start',
    minHeight: 40,
    paddingHorizontal: 12,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: C.border,
    backgroundColor: C.bg,
  },
  secondaryText: { fontSize: 13, fontWeight: '700', color: C.accent },
  detailsButton: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 4,
    minHeight: 42,
    borderRadius: 12,
    backgroundColor: C.accentSoft,
  },
  detailsText: { fontSize: 14, fontWeight: '700', color: C.link },
  pressed: { opacity: 0.8 },
});
