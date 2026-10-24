/**
 * Child detail for the parent (M2-05): the pet's idle video on top (M4-03, one player,
 * released when the screen closes), then `GET /api/parent/children/{child}/report`
 * for 7 / 30 / 84 days — score of the period, totals per routine type, one row per
 * day (done / expected, walk steps vs goal), missed routines, illnesses — and the
 * activity timeline of the child's pet (`/api/parent/activities?pet_id=`, paginated)
 * with the nicknames of the children who acted. M5-R04: the dog's stage and age
 * ("Mladiček · 3 mesece"), origin, next stage and today's meals — nothing for a
 * legacy pet. M5-T05b: »Kako skrbim za: [ime]« opens the child's care guide (same numbers)
 * in the parent's wording. M5-R02: the puppy's bladder clock and open messes (luža, pregrizen copat)
 * with deadlines, missed cleans named by their mess, new timeline rows. M5-R03: "Šola" —
 * "Kuža zna: sedi ✓, pridi 60 % …", whether today's session is done, the training routine
 * in the totals (only for a pet with training). M5-F01: "12-week challenge — buy" on top
 * while the dog's challenge can be bought. Light parent theme (ADR-007).
 * M5-R06-08b: for a cat the routine rows are food, water, cleaning, litter, play, the weekly
 * litter change and (Maine Coon) brushing; a day shows its wand games instead of steps; the
 * timeline names the cat's actions; a "good to know" card about cats that stop eating (C24).
 * M2-01d: the certificate / report card on top once the child's challenge ended.
 * M5-R17: a puppy's weekly "Nova izkušnja" row under the routine totals (weeks done / missed /
 * open, "ne vpliva na oceno skrbi") and its timeline entry ("Nova izkušnja: nov zvok · Mia").
 */

import { useCallback, useMemo, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { ActivityIndicator, Platform, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { Text } from '@/components/ui/Text';
import { Check, ChevronLeft, Heart, Sparkles, X } from 'lucide-react-native';

import { ApiError } from '@/api/client';
import { useServerOutage } from '@/store/serverStatusStore';
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
import { isSpecies } from '@/modules/species/species';
import { reportSocialisationLine } from '@/modules/socialisation/socialisation';
import { parentPreparationTitle, preparationBody, readPreparation } from '@/modules/preparation/preparation';
import { parentBirthdayLine, readBirthday } from '@/modules/birthday/birthday';
import PetMediaView from '@/components/PetMediaView';
import ChallengeBuyButton from '@/components/parent/ChallengeBuyButton';
import CertificateCard from '@/components/parent/CertificateCard';
import LogWalkCard from '@/components/parent/LogWalkCard';
import CareRemindersCard, { reminderDate } from '@/components/parent/CareRemindersCard';
import { careReminderTitle } from '@/modules/careTasks/careTasks';
import { canBuyChallenge } from '@/modules/plan/purchaseEntry';
import { parentPetGrowthKey, useGrowthRefresh, useParentPetGrowth } from '@/hooks/queries/usePetGrowth';
import PetAlbum from '@/components/PetAlbum';
import PetNameRow from '@/components/parent/PetNameRow';
import { readPetName } from '@/modules/petName/petName';
import { hasAlbum } from '@/modules/petMedia/album';
import {
  REPORT_PERIODS,
  ROUTINE_LABELS,
  missedLabel,
  reportRoutineTypes,
  activityText,
  activityWhenText,
  dayLabel,
  illnessesText,
  missedWhenText,
  progressText,
  readTimeline,
  reasonText,
  scoreRoutinesText,
  type DayRow,
  type ReportDays,
} from '@/modules/family/scoring';
import { clockText, localParts } from '@/modules/childPet/familyTime';
import { mealsLine, nextStageLine, originLine, readPetProfile, stageLine } from '@/modules/petProfile/petProfile';
import { PARENT_BEHAVIOUR_STRINGS, parentBehaviourLines } from '@/modules/behaviour/behaviour';
import { careGuideTitle, readCareGuide } from '@/modules/careGuide/careGuide';
import CareGuideSheet from '@/modules/careGuide/CareGuideSheet';
import { PARENT_TRAINING_STRINGS, parentTrainingLines } from '@/modules/training/training';
import { playKindOfActivity } from '@/modules/play/play';
import { fonts, palette, tightTracking } from '@/theme';
import { t, tSpecies } from '@/i18n';
import { formatThousands } from '@/i18n/format';
import { strings } from '@/i18n/strings';

/** All user-visible strings of this screen (`parent:childDetail`, M1-18). */
export const CHILD_DETAIL_STRINGS = strings('parent', 'childDetail', {
  periodA11y: (label: string) => t('parent:childDetail.periodA11y', { label }),
  totalScore: (n: number | null) => t('parent:childDetail.totalScore', { score: n === null ? '—' : String(n) }),
  typeLine: (own: number, expected: number) => t('parent:childDetail.typeLine', { own, expected }),
  typeMissed: (n: number) => t('parent:childDetail.typeMissed', { n }),
  typePending: (n: number) => t('parent:childDetail.typePending', { n }),
  /** M5-T12: "od tega 30 min vpisanih" — walk minutes a parent logged that day. */
  walkLogged: (minutes: number) => t('parent:childDetail.walkLogged', { minutes }),
  walk: (steps: string, goal: string | null, pushes = false) =>
    pushes
      ? goal
        ? t('parent:childDetail.walkGoalPushes', { steps, goal })
        : t('parent:childDetail.walkPushes', { steps })
      : goal
        ? t('parent:childDetail.walkGoal', { steps, goal })
        : t('parent:childDetail.walk', { steps }),
  /** M5-R06-08b: "Igra 1/2" — the cat's wand games of the day. */
  playDay: (done: number, goal: number) => t('cat:parent.playDay', { done, goal }),
  illnessRow: (from: string, to: string | null) =>
    to ? t('parent:childDetail.illnessRange', { from, to }) : t('parent:childDetail.illnessOngoing', { from }),
});

const S = CHILD_DETAIL_STRINGS;

interface ChildDetailScreenProps {
  child: FamilyChild;
  family: FamilyOverview;
  onBack: () => void;
  /** M5-F01: opens the challenge paywall; the button shows only while `canBuyChallenge(pet)`. */
  onOpenChallenge?: () => void;
}

/** 12500 → "12.500" (sl) / "12,500" (en) — `common:format.thousands` (M1-18). */
export function formatSteps(n: number): string {
  return formatThousands(Math.round(n));
}

function instantText(iso: string | null, timezone: string): string {
  if (!iso) return '';
  const p = localParts(iso, timezone);
  return p ? t('family:date.dateTime', { date: dayLabel(p.date), time: clockText(p.time) }) : iso;
}

/** A cat's day: "Igra 1/2" (null without a play routine that day). */
export function dayPlayText(day: Pick<DayRow, 'play_sessions' | 'play_goal'>): string | null {
  if (day.play_goal === null || day.play_goal <= 0) return null;
  return S.playDay(day.play_sessions ?? 0, day.play_goal);
}

function Timeline({ petId, family, species }: { petId: number; family: FamilyOverview; species: string | null }) {
  const activities = usePetActivities(petId);
  const items = (activities.data?.pages ?? []).flatMap((page) => readTimeline(page.data));
  // M5-F13: during a deploy wait (spinner) instead of an error; it loads once the server is back.
  const waitingForServer = useServerOutage() && activities.isError && items.length === 0;
  const today = localParts(new Date().toISOString(), family.timezone)?.date ?? null;

  return (
    <Card testID="timeline">
      <SectionTitle>{S.timeline}</SectionTitle>
      {activities.isPending || waitingForServer ? (
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
                {/* M5-R05: play / cuddles get a heart (no points — not a care tick). */}
                {playKindOfActivity(item.activity_type) !== null ? (
                  <Heart color={palette.white} fill={palette.white} size={11} />
                ) : item.is_positive ? (
                  <Check color={palette.white} size={12} />
                ) : (
                  <X color={palette.white} size={12} />
                )}
              </View>
              <Text style={styles.timelineText}>
                {activityText({ activity_type: item.activity_type, actor_nickname: nickname, value: item.value }, species)}
              </Text>
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

export default function ChildDetailScreen({ child, family, onBack, onOpenChallenge }: ChildDetailScreenProps) {
  const [days, setDays] = useState<ReportDays>(7);
  const report = useChildReport(child.id, days);
  const data = report.data;
  const tz = data?.timezone ?? family.timezone;
  const petId = data?.pet_id ?? child.pet_id;
  const offline = report.isError && !(report.error instanceof ApiError);
  // M5-F13: the deploy banner (on the dashboard root) explains it; the last report stays.
  const serverUpdating = useServerOutage();
  const pet = petOfChild(child, family);
  const queryClient = useQueryClient();
  const [albumOpen, setAlbumOpen] = useState(false);
  // Memoised: a new object per render would make the album's expiry timer / items churn.
  const hasPet = pet !== null;
  const petMediaRaw = pet?.media;
  const media = useMemo(() => (hasPet ? normalizePetMedia(petMediaRaw) : null), [hasPet, petMediaRaw]);
  const albumAvailable = media !== null && hasAlbum(media);
  const preparation = readPreparation((pet as { preparation?: unknown } | null)?.preparation);
  // M5-T13: the pet's first birthday — one line on the day.
  const birthday = readBirthday((pet as { birthday?: unknown } | null)?.birthday);
  const petProfileRaw: unknown = pet?.profile;
  const profile = useMemo(() => readPetProfile(petProfileRaw), [petProfileRaw]);
  const showAlbum = albumOpen && albumAvailable;
  // M5-T05b: the child's care guide (same server numbers), worded for the parent; null from an older server.
  const careGuideRaw: unknown = (pet as { care_guide?: unknown } | null)?.care_guide;
  const careGuide = useMemo(() => readCareGuide(careGuideRaw), [careGuideRaw]);
  const [careGuideOpen, setCareGuideOpen] = useState(false);
  const behaviourLines = pet ? parentBehaviourLines(pet.behaviour, family.timezone) : [];
  const trainingLines = pet ? parentTrainingLines(pet.training) : [];
  const trainingEnabled = pet?.training.enabled ?? false;
  // M5-R06-08b: species-specific report rows and texts.
  const species = pet !== null && isSpecies(pet.species) ? pet.species : null;
  const isCat = species === 'cat';
  // A signed media URL failed (likely expired): refresh the dashboard once for new URLs.
  const onMediaExpired = useCallback(() => {
    void queryClient.invalidateQueries({ queryKey: parentDashboardKey });
  }, [queryClient]);
  // M5-R04 "Album rasti" (view-only): only while the album is open; an error hides the section.
  const growthPetId = pet?.id ?? null;
  const growthQuery = useParentPetGrowth(growthPetId, showAlbum);
  const growth = growthQuery.isError ? null : (growthQuery.data ?? null);
  const onGrowthExpired = useGrowthRefresh(parentPetGrowthKey(growthPetId ?? 0));

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
          {/* M2-01d: the finished challenge first — certificate or report (→ the 84-day period). */}
          <CertificateCard
            certificate={child.certificate}
            childName={child.name}
            onOpenReport={() => setDays(84)}
            testID="detail-certificate"
          />
          {/* M5-T12: in adapted mode the parent can log walk minutes (no Apple Watch needed). */}
          {child.adapted_mode && pet !== null && !isCat && (
            <LogWalkCard childId={child.id} timezone={family.timezone} testID="detail-log-walk" />
          )}
          {onOpenChallenge && canBuyChallenge(pet) && (
            <ChallengeBuyButton onPress={onOpenChallenge} childName={child.name} species={pet?.species ?? null} testID="detail-buy-challenge" />
          )}
          {pet !== null && media !== null && (
            <PetMediaView
              media={media}
              // One player at a time: paused while the album is open.
              active={!showAlbum}
              petState="idle"
              // A locked pet (vet, hard stop, game over, inactive) is shown as a still image.
              videoEnabled={pet.is_active && !pet.is_ill && !pet.is_hard_stopped && !pet.is_game_over}
              breed={toBreedType(pet.breed_type)}
              species={isSpecies(pet.species) ? pet.species : null}
              onMediaExpired={onMediaExpired}
              variant="card"
              style={styles.petMedia}
              testID="detail-pet-media"
            />
          )}
          {/* M5-T06: while the pet's first things are on their way — the same neutral words the child sees. */}
          {pet !== null && preparation.preparing && (
            <View style={styles.preparing} testID="detail-pet-preparing" accessible accessibilityRole="text">
              <Text style={styles.strong}>{parentPreparationTitle(pet.name, pet.species)}</Text>
              <Text style={styles.muted}>{preparationBody()}</Text>
            </View>
          )}
          {pet !== null && birthday?.isToday === true && (
            <View style={styles.preparing} testID="detail-pet-birthday" accessible accessibilityRole="text">
              <Text style={styles.strong}>{parentBirthdayLine(pet.name, pet.species)}</Text>
            </View>
          )}
          {albumAvailable && (
            <Pressable
              onPress={() => setAlbumOpen(true)}
              accessibilityRole="button"
              testID="detail-album-open"
              style={({ pressed }) => [styles.moreButton, pressed && styles.pressed]}
            >
              <Text style={styles.moreText}>{tSpecies('parent:childDetail.album', species)}</Text>
            </Pressable>
          )}

          {/* M5-R18 / R20: groomer visit / bath reminders of breeds with a sourced frequency (never scored). */}
          {pet !== null && (
            <CareRemindersCard petId={pet.id} reminders={(pet as { care_reminders?: unknown }).care_reminders} timezone={family.timezone} />
          )}

          {/* M5-R08: the parent sets / changes / removes the pet's name here (a label only). */}
          {pet !== null && (
            <Card testID="detail-pet-name">
              <PetNameRow pet={pet} testID="detail-pet-name-row" />
            </Card>
          )}

          {profile !== null && (
            <Card testID="detail-pet-profile">
              <Text style={styles.strong} testID="detail-pet-stage">
                {stageLine(profile, species)}
              </Text>
              {[originLine(profile, species), nextStageLine(profile, 'parent', species), mealsLine(profile)]
                .filter((line): line is string => line !== null)
                .map((line) => (
                  <Text key={line} style={styles.muted}>
                    {line}
                  </Text>
                ))}
            </Card>
          )}

          {careGuide !== null && pet !== null && (
            <Pressable
              onPress={() => setCareGuideOpen(true)}
              accessibilityRole="button"
              accessibilityHint={t('careGuide:parent.openHint')}
              testID="detail-care-guide-open"
              style={({ pressed }) => [styles.moreButton, pressed && styles.pressed]}
            >
              <Text style={styles.moreText}>{careGuideTitle(careGuide.species, pet.name, 'parent')}</Text>
            </Pressable>
          )}

          {isCat && (
            <Card testID="detail-cat-first-aid">
              <SectionTitle>{t('cat:parent.firstAid.title')}</SectionTitle>
              <Text style={styles.body}>{t('cat:parent.firstAid.body')}</Text>
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

          {report.isError && !serverUpdating && (
            <ErrorBanner
              text={data ? S.offline : S.loadError}
              retryLabel={S.retry}
              onRetry={() => void report.refetch()}
              offline={offline}
              testID="report-error"
            />
          )}

          {!data ? (
            // M5-F13: no report yet and the server is updating → wait (it loads on recovery), never blank.
            report.isPending || serverUpdating ? <LoadingBlock text={S.loading} testID="report-loading" /> : null
          ) : data.pet_id === null ? (
            <Card>
              <Text style={styles.muted} testID="report-no-pet">
                {S.noPet}
              </Text>
            </Card>
          ) : (
            <>
              {(data as { history_limited?: boolean }).history_limited === true && (
                <View style={styles.historyLimited} testID="report-history-limited">
                  <Text style={styles.body}>{t('paywall:history.limited')}</Text>
                </View>
              )}
              <Card testID={`report-${data.days}`}>
                {data.traffic_light.reasons.map((r) => (
                  <Text key={r} style={styles.body}>
                    • {reasonText(r, child.today.missed_count, species)}
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
                {reportRoutineTypes(species, pet?.breed_type, data.by_type, trainingEnabled).map((type) => {
                  // Training (M5-R03) only for a pet that has it, cat rows only for a cat (M5-R06-08b)
                  // — or a period in which the server counted one.
                  const totals = data.by_type[type];
                  return (
                    <View key={type} style={styles.typeRow} testID={`report-type-${type}`}>
                      <RoutineIcon type={type} />
                      <Text style={styles.typeLabel}>{ROUTINE_LABELS[type]}</Text>
                      <Text style={styles.strong}>{S.typeLine(totals.done_by_child, totals.expected)}</Text>
                      <Text style={[styles.muted, styles.flex, styles.right]}>
                        {[totals.missed > 0 ? S.typeMissed(totals.missed) : null, totals.pending > 0 ? S.typePending(totals.pending) : null]
                          .filter((x): x is string => x !== null)
                          .join(' · ')}
                      </Text>
                    </View>
                  );
                })}
                {/* M5-R17: the puppy's weekly "new experience" — its own row, never scored. */}
                {data.socialisation !== null && (
                  <>
                    <View style={styles.typeRow} testID="report-socialisation">
                      <Sparkles color={C.accent} size={16} />
                      <Text style={styles.typeLabel}>{t('socialisation:parent.label')}</Text>
                      <Text style={styles.strong}>{reportSocialisationLine(data.socialisation).count}</Text>
                      <Text style={[styles.muted, styles.flex, styles.right]}>{reportSocialisationLine(data.socialisation).detail}</Text>
                    </View>
                    <Text style={styles.muted} testID="report-socialisation-note">
                      {t('socialisation:parent.notScored')}
                    </Text>
                  </>
                )}
                {/* M5-R18 / R20: the parent's groomer / bath reminders — shown, never scored. */}
                {data.care_reminders.length > 0 && (
                  <>
                    <Text style={styles.strong}>{t('careTasks:parent.reportTitle')}</Text>
                    {data.care_reminders.map((r) => (
                      <View key={`${r.task}-${r.period_started_at ?? ''}`} style={styles.typeRow} testID={`report-reminder-${r.task}-${r.status}`}>
                        <Check color={C.accent} size={16} />
                        <Text style={styles.typeLabel}>{careReminderTitle(r.task)}</Text>
                        <Text style={styles.strong}>{t(`careTasks:parent.status.${r.status}`)}</Text>
                        <Text style={[styles.muted, styles.flex, styles.right]}>
                          {`${reminderDate(r.period_started_at, tz)} – ${reminderDate(r.period_ends_at, tz)}`}
                        </Text>
                      </View>
                    ))}
                    <Text style={styles.muted}>{t('careTasks:parent.note')}</Text>
                  </>
                )}
              </Card>

              <Card>
                <SectionTitle>{S.daily}</SectionTitle>
                {[...data.daily].reverse().map((d) => (
                  <View key={d.date} style={styles.dayRow} testID={`report-day-${d.date}`}>
                    <Text style={styles.dayLabel}>{dayLabel(d.date)}</Text>
                    <Text style={[styles.strong, styles.dayCount]}>
                      {d.expected === 0 ? S.noRoutines : `${d.done}/${d.expected}`}
                    </Text>
                    {isCat ? (
                      // M5-R06-08b: a cat has no steps — its wand games of the day instead.
                      <Text style={[styles.muted, styles.flex, styles.right]} testID={`report-day-play-${d.date}`}>
                        {dayPlayText(d) ?? ''}
                      </Text>
                    ) : (
                      <Text style={[styles.muted, styles.flex, styles.right]}>
                        {S.walk(formatSteps(d.walk_steps), d.walk_goal === null ? null : formatSteps(d.walk_goal), d.walk_unit === 'pushes')}
                        {d.walk_logged_minutes > 0 ? ` · ${S.walkLogged(d.walk_logged_minutes)}` : ''}
                      </Text>
                    )}
                    {(isCat ? d.play_done : d.walk_done) === true && <Check color={C.green} size={14} />}
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

          {petId !== null && <Timeline petId={petId} family={family} species={species} />}
        </ScrollView>
      </View>

      {/* Read-only album (same viewer as the child), over the whole screen; Android back closes it. */}
      {showAlbum && media !== null && (
        <PetAlbum
          media={media}
          title={readPetName(pet?.name) ?? tSpecies('pet:album.parentTitle', species)}
          species={species}
          onClose={() => setAlbumOpen(false)}
          onMediaExpired={onMediaExpired}
          growth={growth}
          timeZone={family.timezone}
          onGrowthExpired={onGrowthExpired}
          testID="detail-album"
        />
      )}
      {/* M5-T05b: the same care-guide sheet the child sees, worded for the parent (»vi«). */}
      {careGuide !== null && (
        <CareGuideSheet
          visible={careGuideOpen && !showAlbum}
          guide={careGuide}
          petName={pet?.name ?? null}
          audience="parent"
          pushes={child.adapted_mode}
          onClose={() => setCareGuideOpen(false)}
          testID="detail-care-guide"
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  historyLimited: { padding: 12, borderRadius: 12, backgroundColor: palette.mintSoft, borderWidth: 1, borderColor: palette.mintBorder },
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
  headerTitle: { flex: 1, fontSize: 20, letterSpacing: tightTracking(20), fontFamily: fonts.display, color: C.text },
  content: { padding: 16, gap: 14, paddingBottom: 32 },
  petMedia: { height: 220 },
  preparing: { alignItems: 'center', gap: 2, marginTop: -4 },
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
  moreText: { fontSize: 14, fontWeight: '700', color: C.link },
  pressed: { opacity: 0.8 },
});
