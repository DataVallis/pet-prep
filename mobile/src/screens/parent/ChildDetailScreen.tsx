/**
 * Child detail for the parent (M2-05): the pet's idle video on top (M4-03, one player,
 * released when the screen closes), then `GET /api/parent/children/{child}/report`
 * for 7 / 30 / 84 days — score of the period, totals per routine type, one row per
 * day (done / expected, walk steps vs goal), missed routines, illnesses — and the
 * activity timeline of the child's pet (`/api/parent/activities?pet_id=`, paginated)
 * with the nicknames of the children who acted. M5-R04: the dog's stage and age
 * ("Mladiček · 3 mesece"), origin, next stage and today's meals — nothing for a
 * legacy pet. M5-R02: the puppy's bladder clock and open messes (luža, pregrizen copat)
 * with deadlines, missed cleans named by their mess, new timeline rows. M5-R03: "Šola" —
 * "Kuža zna: sedi ✓, pridi 60 % …", whether today's session is done, the training routine
 * in the totals (only for a pet with training). Light parent theme (ADR-007).
 */

import { useCallback, useMemo, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { ActivityIndicator, Platform, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Check, ChevronLeft, X } from 'lucide-react-native';

import { ApiError } from '@/api/client';
import { useChildReport, usePetActivities } from '@/hooks/queries/useParentQueries';
import {
  Card,
  ErrorBanner,
  LoadingBlock,
  PARENT_COLORS as C,
  RoutineIcon,
  SectionTitle,
  Segmented,
  TrafficLightBadge,
} from '@/components/parent/ParentUi';
import { nicknameOf, petOfChild, type FamilyChild, type FamilyOverview } from '@/modules/family/family';
import { parentDashboardKey } from '@/modules/family/live';
import { normalizePetMedia, toBreedType } from '@/modules/petMedia/petMedia';
import PetMediaView from '@/components/PetMediaView';
import PetAlbum from '@/components/PetAlbum';
import { ALBUM_STRINGS, hasAlbum } from '@/modules/petMedia/album';
import {
  REPORT_PERIODS,
  ROUTINE_LABELS,
  ROUTINE_TYPES,
  missedLabel,
  activityText,
  activityWhenText,
  dayLabel,
  illnessesText,
  missedWhenText,
  progressText,
  readTimeline,
  reasonText,
  scoreRoutinesText,
  type ReportDays,
} from '@/modules/family/scoring';
import { localParts } from '@/modules/childPet/familyTime';
import { mealsLine, nextStageLine, originLine, readPetProfile, stageLine } from '@/modules/petProfile/petProfile';
import { PARENT_BEHAVIOUR_STRINGS, parentBehaviourLines } from '@/modules/behaviour/behaviour';
import { PARENT_TRAINING_STRINGS, parentTrainingLines } from '@/modules/training/training';

export const CHILD_DETAIL_STRINGS = {
  back: 'Nazaj',
  album: 'Vsi posnetki kužka',
  periods: { 7: '7 dni', 30: '30 dni', 84: '12 tednov' } satisfies Record<ReportDays, string>,
  periodA11y: (label: string) => `Obdobje: ${label}`,
  loading: 'Nalagam poročilo …',
  loadError: 'Poročila ni bilo mogoče naložiti.',
  offline: 'Ni povezave. Prikazani so zadnji naloženi podatki.',
  retry: 'Poskusi znova',
  periodScore: 'Ocena v obdobju',
  totalScore: (n: number | null) => `Skupaj od začetka: ${n === null ? '—' : n}`,
  noScore: 'Še ni dovolj podatkov',
  byType: 'Po rutinah',
  typeLine: (own: number, expected: number) => `${own} od ${expected}`,
  typeMissed: (n: number) => `zamujeno ${n}`,
  typePending: (n: number) => `odprto ${n}`,
  daily: 'Po dnevih',
  walk: (steps: string, goal: string | null) => (goal ? `${steps} / ${goal} korakov` : `${steps} korakov`),
  noRoutines: 'brez rutin',
  missed: 'Zamujene rutine',
  noMissed: 'V tem obdobju ni zamujene rutine.',
  illnesses: 'Bolezni',
  illnessRow: (from: string, to: string | null) => (to ? `${from} – ${to}` : `${from} – še traja`),
  timeline: 'Časovnica',
  timelineEmpty: 'Še ni dejavnosti.',
  timelineError: 'Časovnice ni bilo mogoče naložiti.',
  loadMore: 'Naloži več',
  noPet: 'Otrok še nima psa — poročilo bo na voljo po podpisu pogodbe.',
} as const;

const S = CHILD_DETAIL_STRINGS;

interface ChildDetailScreenProps {
  child: FamilyChild;
  family: FamilyOverview;
  onBack: () => void;
}

/** 12500 → "12.500" (Slovenian thousands separator, no Intl needed). */
export function formatSteps(n: number): string {
  return String(Math.max(0, Math.round(n))).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

function instantText(iso: string | null, timezone: string): string {
  if (!iso) return '';
  const p = localParts(iso, timezone);
  return p ? `${dayLabel(p.date)} ${p.time}` : iso;
}

function Timeline({ petId, family }: { petId: number; family: FamilyOverview }) {
  const activities = usePetActivities(petId);
  const items = (activities.data?.pages ?? []).flatMap((page) => readTimeline(page.data));
  const today = localParts(new Date().toISOString(), family.timezone)?.date ?? null;

  return (
    <Card testID="timeline">
      <SectionTitle>{S.timeline}</SectionTitle>
      {activities.isPending ? (
        <ActivityIndicator color={C.accent} />
      ) : activities.isError && items.length === 0 ? (
        <ErrorBanner text={S.timelineError} retryLabel={S.retry} onRetry={() => void activities.refetch()} testID="timeline-error" />
      ) : items.length === 0 ? (
        <Text style={styles.muted}>{S.timelineEmpty}</Text>
      ) : (
        items.map((item) => {
          const nickname = item.actor_nickname ?? nicknameOf(item.actor_user_id, family);
          return (
            <View key={item.id} style={styles.timelineRow} testID={`timeline-item-${item.id}`}>
              <View style={[styles.timelineBadge, { backgroundColor: item.is_positive ? C.green : C.red }]}>
                {item.is_positive ? <Check color="#ffffff" size={12} /> : <X color="#ffffff" size={12} />}
              </View>
              <Text style={styles.timelineText}>{activityText({ activity_type: item.activity_type, actor_nickname: nickname })}</Text>
              <Text style={styles.muted}>{activityWhenText(item.created_at, family.timezone, today)}</Text>
            </View>
          );
        })
      )}
      {activities.hasNextPage && (
        <Pressable
          style={({ pressed }) => [styles.moreButton, pressed && styles.pressed]}
          onPress={() => void activities.fetchNextPage()}
          disabled={activities.isFetchingNextPage}
          accessibilityRole="button"
          testID="timeline-more"
        >
          {activities.isFetchingNextPage ? (
            <ActivityIndicator color={C.accent} />
          ) : (
            <Text style={styles.moreText}>{S.loadMore}</Text>
          )}
        </Pressable>
      )}
    </Card>
  );
}

export default function ChildDetailScreen({ child, family, onBack }: ChildDetailScreenProps) {
  const [days, setDays] = useState<ReportDays>(7);
  const report = useChildReport(child.id, days);
  const data = report.data;
  const tz = data?.timezone ?? family.timezone;
  const petId = data?.pet_id ?? child.pet_id;
  const offline = report.isError && !(report.error instanceof ApiError);
  const pet = petOfChild(child, family);
  const queryClient = useQueryClient();
  const [albumOpen, setAlbumOpen] = useState(false);
  // Memoised: a new object per render would make the album's expiry timer / items churn.
  const hasPet = pet !== null;
  const petMediaRaw = pet?.media;
  const media = useMemo(() => (hasPet ? normalizePetMedia(petMediaRaw) : null), [hasPet, petMediaRaw]);
  const albumAvailable = media !== null && hasAlbum(media);
  const petProfileRaw: unknown = pet?.profile;
  const profile = useMemo(() => readPetProfile(petProfileRaw), [petProfileRaw]);
  const showAlbum = albumOpen && albumAvailable;
  const behaviourLines = pet ? parentBehaviourLines(pet.behaviour, family.timezone) : [];
  const trainingLines = pet ? parentTrainingLines(pet.training) : [];
  const trainingEnabled = pet?.training.enabled ?? false;
  // A signed media URL failed (likely expired): refresh the dashboard once for new URLs.
  const onMediaExpired = useCallback(() => {
    void queryClient.invalidateQueries({ queryKey: parentDashboardKey });
  }, [queryClient]);

  return (
    <View style={styles.root}>
      {/* Android: TalkBack must not reach the screen under the album (iOS: accessibilityViewIsModal). */}
      <View
        style={styles.flex}
        testID="detail-content"
        importantForAccessibility={showAlbum ? 'no-hide-descendants' : 'auto'}
        accessibilityElementsHidden={showAlbum}
      >
        <View style={styles.header}>
          <Pressable onPress={onBack} hitSlop={10} accessibilityRole="button" accessibilityLabel={S.back} testID="detail-back">
            <ChevronLeft color={C.accent} size={26} />
          </Pressable>
          <Text style={styles.headerTitle}>{child.name}</Text>
          <TrafficLightBadge color={(data?.traffic_light ?? child.traffic_light).color} />
        </View>

        <ScrollView style={styles.flex} contentContainerStyle={styles.content}>
          {pet !== null && media !== null && (
            <PetMediaView
              media={media}
              // One player at a time: paused while the album is open.
              active={!showAlbum}
              petState="idle"
              // A locked pet (vet, hard stop, game over, inactive) is shown as a still image.
              videoEnabled={pet.is_active && !pet.is_ill && !pet.is_hard_stopped && !pet.is_game_over}
              breed={toBreedType(pet.breed_type)}
              onMediaExpired={onMediaExpired}
              variant="card"
              style={styles.petMedia}
              testID="detail-pet-media"
            />
          )}
          {albumAvailable && (
            <Pressable
              onPress={() => setAlbumOpen(true)}
              accessibilityRole="button"
              testID="detail-album-open"
              style={({ pressed }) => [styles.moreButton, pressed && styles.pressed]}
            >
              <Text style={styles.moreText}>{S.album}</Text>
            </Pressable>
          )}

          {profile !== null && (
            <Card testID="detail-pet-profile">
              <Text style={styles.strong} testID="detail-pet-stage">
                {stageLine(profile)}
              </Text>
              {[originLine(profile), nextStageLine(profile), mealsLine(profile)]
                .filter((line): line is string => line !== null)
                .map((line) => (
                  <Text key={line} style={styles.muted}>
                    {line}
                  </Text>
                ))}
            </Card>
          )}

          {behaviourLines.length > 0 && (
            <Card testID="detail-pet-behaviour">
              <SectionTitle>{PARENT_BEHAVIOUR_STRINGS.title}</SectionTitle>
              {behaviourLines.map((line) => (
                <Text key={line} style={styles.body}>
                  • {line}
                </Text>
              ))}
            </Card>
          )}

          {trainingLines.length > 0 && (
            <Card testID="detail-pet-training">
              <SectionTitle>{PARENT_TRAINING_STRINGS.title}</SectionTitle>
              {trainingLines.map((line, i) => (
                <Text key={line} style={i === 0 ? styles.strong : styles.body} testID={`detail-training-line-${i}`}>
                  {line}
                </Text>
              ))}
            </Card>
          )}

          <Segmented
            options={REPORT_PERIODS.map((p) => ({ value: p, label: S.periods[p] }))}
            value={days}
            onChange={setDays}
            label={(p) => S.periodA11y(S.periods[p])}
            testIDPrefix="period"
          />

          {report.isError && (
            <ErrorBanner
              text={data ? S.offline : S.loadError}
              retryLabel={S.retry}
              onRetry={() => void report.refetch()}
              offline={offline}
              testID="report-error"
            />
          )}

          {!data ? (
            report.isPending ? <LoadingBlock text={S.loading} testID="report-loading" /> : null
          ) : data.pet_id === null ? (
            <Card>
              <Text style={styles.muted} testID="report-no-pet">
                {S.noPet}
              </Text>
            </Card>
          ) : (
            <>
              <Card testID={`report-${data.days}`}>
                {data.traffic_light.reasons.map((r) => (
                  <Text key={r} style={styles.body}>
                    • {reasonText(r, child.today.missed_count)}
                  </Text>
                ))}
                <Text style={styles.label}>{S.periodScore}</Text>
                {data.period_score.score === null ? (
                  <Text style={styles.noScore} testID="report-period-score">
                    {S.noScore}
                  </Text>
                ) : (
                  <Text style={styles.score} testID="report-period-score">
                    {data.period_score.score}
                  </Text>
                )}
                <Text style={styles.muted}>
                  {scoreRoutinesText(data.period_score)}
                  {data.period_score.illnesses > 0 ? ` · ${illnessesText(data.period_score.illnesses)}` : ''}
                </Text>
                <Text style={styles.muted}>{S.totalScore(data.care_score.score)}</Text>
                {progressText(data.progress) && <Text style={styles.strong}>{progressText(data.progress)}</Text>}
                {report.isPlaceholderData && <ActivityIndicator color={C.accent} />}
              </Card>

              <Card>
                <SectionTitle>{S.byType}</SectionTitle>
                {ROUTINE_TYPES.map((type) => {
                  const t = data.by_type[type];
                  // Training (M5-R03) only for a pet that has it — or a period in which it counted.
                  if (type === 'training' && !trainingEnabled && t.expected === 0 && t.done === 0) return null;
                  return (
                    <View key={type} style={styles.typeRow} testID={`report-type-${type}`}>
                      <RoutineIcon type={type} />
                      <Text style={styles.typeLabel}>{ROUTINE_LABELS[type]}</Text>
                      <Text style={styles.strong}>{S.typeLine(t.done_by_child, t.expected)}</Text>
                      <Text style={[styles.muted, styles.flex, styles.right]}>
                        {[t.missed > 0 ? S.typeMissed(t.missed) : null, t.pending > 0 ? S.typePending(t.pending) : null]
                          .filter((x): x is string => x !== null)
                          .join(' · ')}
                      </Text>
                    </View>
                  );
                })}
              </Card>

              <Card>
                <SectionTitle>{S.daily}</SectionTitle>
                {[...data.daily].reverse().map((d) => (
                  <View key={d.date} style={styles.dayRow} testID={`report-day-${d.date}`}>
                    <Text style={styles.dayLabel}>{dayLabel(d.date)}</Text>
                    <Text style={[styles.strong, styles.dayCount]}>
                      {d.expected === 0 ? S.noRoutines : `${d.done}/${d.expected}`}
                    </Text>
                    <Text style={[styles.muted, styles.flex, styles.right]}>
                      {S.walk(formatSteps(d.walk_steps), d.walk_goal === null ? null : formatSteps(d.walk_goal))}
                    </Text>
                    {d.walk_done === true && <Check color={C.green} size={14} />}
                  </View>
                ))}
              </Card>

              <Card>
                <SectionTitle>{S.missed}</SectionTitle>
                {data.missed.length === 0 ? (
                  <Text style={styles.muted}>{S.noMissed}</Text>
                ) : (
                  data.missed.map((m, i) => (
                    <View key={`${m.type}-${m.due_at}-${i}`} style={styles.typeRow} testID={`report-missed-${i}`}>
                      <RoutineIcon type={m.type} color={C.redText} />
                      <Text style={[styles.typeLabel, styles.missedType]}>{missedLabel(m)}</Text>
                      <Text style={styles.muted}>
                        {dayLabel(m.date)} · {missedWhenText(m, tz)}
                      </Text>
                    </View>
                  ))
                )}
              </Card>

              {data.illnesses.length > 0 && (
                <Card testID="report-illnesses">
                  <SectionTitle>{S.illnesses}</SectionTitle>
                  {data.illnesses.map((p) => (
                    <Text key={p.started_at} style={styles.body}>
                      {S.illnessRow(instantText(p.started_at, tz), p.ended_at ? instantText(p.ended_at, tz) : null)}
                    </Text>
                  ))}
                </Card>
              )}
            </>
          )}

          {petId !== null && <Timeline petId={petId} family={family} />}
        </ScrollView>
      </View>

      {/* Read-only album (same viewer as the child), over the whole screen; Android back closes it. */}
      {showAlbum && media !== null && (
        <PetAlbum
          media={media}
          title={ALBUM_STRINGS.parentTitle}
          onClose={() => setAlbumOpen(false)}
          onMediaExpired={onMediaExpired}
          testID="detail-album"
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: C.bg },
  flex: { flex: 1 },
  right: { textAlign: 'right' },
  header: {
    paddingTop: Platform.OS === 'ios' ? 56 : 40,
    paddingHorizontal: 16,
    paddingBottom: 12,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    backgroundColor: C.card,
    borderBottomWidth: 1,
    borderBottomColor: C.border,
  },
  headerTitle: { flex: 1, fontSize: 20, fontWeight: '800', color: C.text },
  content: { padding: 16, gap: 14, paddingBottom: 32 },
  petMedia: { height: 220 },
  label: { fontSize: 12, fontWeight: '700', color: C.muted, textTransform: 'uppercase', letterSpacing: 0.4 },
  score: { fontSize: 48, fontWeight: '800', color: C.text, fontVariant: ['tabular-nums'] },
  noScore: { fontSize: 18, fontWeight: '700', color: C.text },
  muted: { fontSize: 13, color: C.muted },
  body: { fontSize: 14, color: C.text },
  strong: { fontSize: 14, fontWeight: '700', color: C.text },
  typeRow: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  typeLabel: { width: 74, fontSize: 14, fontWeight: '600', color: C.text },
  // "Pregrizen copat" (M5-R02) is longer than a routine type.
  missedType: { width: undefined, minWidth: 74 },
  dayRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    paddingVertical: 6,
    borderTopWidth: 1,
    borderTopColor: C.divider,
  },
  dayLabel: { width: 92, fontSize: 13, color: C.text },
  dayCount: { width: 74, fontVariant: ['tabular-nums'] },
  timelineRow: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  timelineBadge: { width: 22, height: 22, borderRadius: 11, alignItems: 'center', justifyContent: 'center' },
  timelineText: { flex: 1, fontSize: 14, color: C.text },
  moreButton: {
    alignItems: 'center',
    justifyContent: 'center',
    minHeight: 42,
    borderRadius: 12,
    backgroundColor: C.accentSoft,
  },
  moreText: { fontSize: 14, fontWeight: '700', color: C.accent },
  pressed: { opacity: 0.8 },
});
