/**
 * Shared test fixtures (not shipped: only imported from __tests__).
 */

import type { ChildPetState, PetGrowthResponse } from '@/api/client';
import type { FamilyChild, FamilyPetRaw } from '@/modules/family/family';
import type { Pet, PetMedia, PetUpdatedBroadcast } from '@/types';

/**
 * `profile` of a pet (M5-R01): a bought mutt puppy of 2 months, 4 meals (11–12 by the parent).
 * A pet created before M5-R01 has `legacy: true` and null origin / ages / stage — see makeLegacyPetProfile().
 */
export function makePetProfile(
  overrides: Partial<ChildPetState['pet']['profile']> = {},
): ChildPetState['pet']['profile'] {
  return {
    legacy: false,
    origin: 'bought',
    arrival_age_months: 2,
    age_months: 2,
    life_stage: 'puppy',
    next_stage: { life_stage: 'young', from_date: '2026-11-24' },
    // Since 2026-10-05 every imported value is confirmed (PRODUCT_SPEC §5) → verified.
    data_verified: true,
    unverified: [],
    // M5-R02: behaviour events only for pets created with generate-pin features ["behaviour_events"].
    behaviour_enabled: false,
    today: {
      date: '2026-10-04',
      meals_per_day: 4,
      meals_by_child: 3,
      meals_by_parent: 1,
      // 4 puppy meals, 2-hour windows (David 2026-10-05, PR #38).
      feed_windows: [
        { start: '07:00', end: '09:00', parent_covered: false, fed: false },
        { start: '11:00', end: '13:00', parent_covered: true, fed: false },
        { start: '15:00', end: '17:00', parent_covered: false, fed: false },
        { start: '19:00', end: '21:00', parent_covered: false, fed: false },
      ],
      step_goal: 2000,
      exercise_minutes: 20,
      sleep_hours: { min: 15, max: 20 },
    },
    ...overrides,
  };
}

/** `profile` of a legacy pet (created before M5-R01): pre-M5 rules — breed windows, breed step goal. */
export function makeLegacyPetProfile(
  overrides: Partial<ChildPetState['pet']['profile']> = {},
): ChildPetState['pet']['profile'] {
  return makePetProfile({
    legacy: true,
    origin: null,
    arrival_age_months: null,
    age_months: null,
    life_stage: null,
    next_stage: null,
    data_verified: false,
    unverified: [],
    today: {
      date: '2026-10-04',
      meals_per_day: 2,
      meals_by_child: 2,
      meals_by_parent: 0,
      feed_windows: [
        { start: '06:00', end: '10:00', parent_covered: false, fed: false },
        { start: '17:00', end: '21:00', parent_covered: false, fed: false },
      ],
      step_goal: 4000,
      exercise_minutes: null,
      sleep_hours: null,
    },
    ...overrides,
  });
}

/** `media` of a pet (M4-05): nothing stored yet. */
export function makeMedia(overrides: Partial<PetMedia> = {}): PetMedia {
  return {
    status: 'disabled',
    reference_image_url: null,
    videos: {},
    current_video_url: null,
    states: ['idle', 'sleeping'],
    expires_at: null,
    ...overrides,
  };
}

/** `pet.updated` payload for pet 7 (UTC instants, like the backend's broadcast). */
export function makeBroadcast(overrides: Partial<PetUpdatedBroadcast> = {}): PetUpdatedBroadcast {
  return {
    pet_id: 7,
    breed_type: 'mutt',
    hunger_level: 55,
    thirst_level: 45,
    energy_level: 30,
    hygiene_level: 80,
    is_active: true,
    pet_state: 'idle',
    escalation_level: 0,
    is_ill: false,
    illness_until: null,
    is_game_over: false,
    is_hard_stopped: false,
    awaiting_contract: false,
    born_at: '2026-10-04T09:00:00+00:00',
    virtual_age_months: 0,
    current_video_url: null,
    reference_image_url: null,
    event_type: 'metric_changed',
    updated_at: '2026-10-04T10:00:05+00:00',
    emitted_at: '2026-10-04T10:00:05.250+00:00',
    ...overrides,
  };
}

export function makePet(overrides: Partial<Pet> = {}): Pet {
  return {
    id: 7,
    user_id: 2,
    breed_type: 'mutt',
    pet_dna: null,
    current_video_url: null,
    hunger_level: 90,
    thirst_level: 80,
    energy_level: 70,
    hygiene_level: 60,
    daily_step_count: 1200,
    born_at: '2026-10-01T08:00:00Z',
    is_active: true,
    pet_state: 'idle',
    illness_until: null,
    escalation_level: 0,
    is_game_over: false,
    certificate_eligible: false,
    ...overrides,
  };
}

/** Child pet state as returned by `GET /api/child/pet` and in action responses (M1-07). */
export function makeChildState(
  petOverrides: Partial<ChildPetState['pet']> = {},
): ChildPetState {
  return {
    pet: {
      id: 7,
      breed_type: 'mutt',
      born_at: '2026-10-04T09:00:00+00:00',
      awaiting_contract: false,
      caretakers_count: 1,
      virtual_age_months: 0,
      age_months: 2,
      origin: 'bought',
      life_stage: 'puppy',
      profile: makePetProfile(),
      hunger_level: 100,
      thirst_level: 100,
      energy_level: 100,
      hygiene_level: 100,
      pet_state: 'idle',
      escalation_level: 0,
      needs_cleaning: false,
      is_active: true,
      is_hard_stopped: false,
      is_ill: false,
      illness_until: null,
      is_game_over: false,
      certificate_eligible: false,
      // M3-11 (additive): plan + payment status — a grandfathered paid challenge.
      plan: { type: 'challenge', status: 'paid', trial_ends_at: '2026-10-11T09:00:00+00:00', paid_at: '2026-10-04T09:00:00+00:00', payments_enforced: true },
      current_video_url: null,
      media_status: 'disabled',
      reference_image_url: null,
      media: makeMedia(),
      ...petOverrides,
    },
    lock: { is_locked: false, reason: null, until: null },
    timezone: 'Europe/Ljubljana',
    server_time: '2026-10-04T09:00:00+00:00',
    feeding: {
      windows: [],
      current_window: null,
      fed_in_current_window: false,
      can_feed: 'false',
      next_feed_window: null,
      last_fed_at: '',
    },
    water: {
      times_per_day: 0,
      min_gap_minutes: 0,
      used_today: 0,
      remaining_today: 0,
      last_watered_at: '',
      can_water: 'false',
      next_allowed_at: '',
    },
    steps: { steps_today: 0, my_steps_today: 0, goal: 5000, energy_level: 100 },
    contract: { signed: true, signed_at: '2026-10-04T09:00:00+00:00' },
    // M5-R02 behaviour events: no bladder clock, nothing open (a legacy-like pet).
    behaviour: { take_out: null, active_events: [], scene: null, can_take_out: false, can_resolve_chewing: false },
    // M5-R03 training: off (pet created by an app without the `training` feature).
    training: makeTrainingState(),
  };
}

/** M5-R03 `training` of the child state; default = training off (legacy / older app). */
export function makeTrainingState(overrides: Partial<ChildPetState['training']> = {}): ChildPetState['training'] {
  return {
    enabled: false,
    commands: [],
    today_done: false,
    session: null,
    session_seconds: 50,
    daily_budget_seconds: 0,
    daily_budget_left_seconds: 0,
    children_sharing: 1,
    my_share_seconds: 0,
    my_seconds_left: 0,
    can_start: false,
    ...overrides,
  };
}

type RawState = ChildPetState;

export interface LiveStateOverrides {
  pet?: Partial<RawState['pet']>;
  lock?: Partial<RawState['lock']>;
  feeding?: Partial<Record<keyof RawState['feeding'], unknown>>;
  water?: Partial<Record<keyof RawState['water'], unknown>>;
  steps?: Partial<RawState['steps']>;
  /** M5-R02 `behaviour` (raw, may be malformed on purpose); `null` drops the key (older server). */
  behaviour?: Partial<Record<keyof RawState['behaviour'], unknown>> | null;
  /** M5-R03 `training` (raw); `null` drops the key (older server). */
  training?: Partial<Record<keyof RawState['training'], unknown>> | null;
  timezone?: string;
  server_time?: string;
}

/**
 * A realistic `GET /api/child/pet` body as the backend sends it (booleans and numbers,
 * not the loose strings `schema.ts` declares): Ljubljana family, 2026-10-04 12:00
 * local, mutt windows 06–10 / 17–21, fed this morning, water 1 of 3 used.
 */
export function makeLiveChildState(o: LiveStateOverrides = {}): ChildPetState {
  const base = makeChildState({ hunger_level: 60, thirst_level: 50, energy_level: 30, hygiene_level: 80, ...o.pet });
  const raw = {
    ...base,
    lock: { ...base.lock, ...o.lock },
    timezone: o.timezone ?? 'Europe/Ljubljana',
    server_time: o.server_time ?? '2026-10-04T12:00:00+02:00',
    feeding: {
      windows: [
        { start: '06:00', end: '10:00' },
        { start: '17:00', end: '21:00' },
      ],
      current_window: null,
      fed_in_current_window: false,
      can_feed: false,
      next_feed_window: { start: '2026-10-04T17:00:00+02:00', end: '2026-10-04T21:00:00+02:00' },
      last_fed_at: '2026-10-04T07:10:00+02:00',
      ...o.feeding,
    },
    water: {
      times_per_day: 3,
      min_gap_minutes: 180,
      used_today: 1,
      remaining_today: 2,
      last_watered_at: '2026-10-04T08:00:00+02:00',
      can_water: true,
      next_allowed_at: null,
      ...o.water,
    },
    steps: { steps_today: 1250, my_steps_today: 1250, goal: 4000, energy_level: 30, ...o.steps },
    behaviour: o.behaviour === null ? undefined : { ...base.behaviour, ...o.behaviour },
    training: o.training === null ? undefined : { ...base.training, ...o.training },
  };
  return raw as unknown as ChildPetState;
}

/** A child of `family.children` in `GET /api/parent/dashboard` (M2-01 / M2-02). */
export function makeFamilyChild(overrides: Partial<FamilyChild> = {}): FamilyChild {
  return {
    id: 5,
    name: 'Maja',
    birth_year: 2016,
    login: 'pin',
    devices: 0,
    pet_id: null,
    contract_signed: false,
    stats: {
      days: 7,
      fed: 0,
      watered: 0,
      cleaned: 0,
      walk_goals: 0,
      taken_out: 0,
      chewing_resolved: 0,
      actions_total: 0,
      steps: 0,
      active_step_days: 0,
    },
    // M2-06 defaults: a child without a pet (green, no score, no progress).
    traffic_light: { color: 'green', reasons: [] },
    care_score: { score: null, done: 0, expected: 0, routines: null, illnesses: 0, since: null },
    today: { date: '2026-10-04', expected: 0, done: 0, done_by_child: 0, pending: 0, missed_count: 0, missed: [] },
    last_7_days: [],
    progress: null,
    ...overrides,
  };
}

/** A `last_7_days` / report `daily` row. */
export function makeDayRow(date: string, overrides: Partial<FamilyChild['last_7_days'][number]> = {}) {
  return {
    date,
    expected: 6,
    fair_expected: 6,
    done: 5,
    done_by_child: 5,
    missed: 1,
    pending: 0,
    walk_steps: 4200,
    walk_goal: 4000,
    walk_done: true,
    ...overrides,
  };
}

/** The 7 days 2026-09-28 … 2026-10-04 (Ljubljana family "today" = 2026-10-04). */
export function makeLast7Days(): FamilyChild['last_7_days'] {
  return ['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04'].map((d) =>
    makeDayRow(d),
  );
}

/**
 * A child caring for pet 7 with real M2-06 data, exactly as the backend serialises it
 * (`CareScoreService::childSummary`): ISO instants with the family offset (+02:00).
 */
export function makeScoredChild(overrides: Partial<FamilyChild> = {}): FamilyChild {
  return makeFamilyChild({
    id: 2,
    name: 'Luka',
    pet_id: 7,
    devices: 1,
    contract_signed: true,
    traffic_light: { color: 'green', reasons: [] },
    care_score: { score: 86, done: 31, expected: 36, routines: 36, illnesses: 0, since: '2026-09-21T16:00:00+02:00' },
    today: { date: '2026-10-04', expected: 4, done: 3, done_by_child: 3, pending: 1, missed_count: 0, missed: [] },
    last_7_days: makeLast7Days(),
    progress: { started_at: '2026-09-21T16:00:00+02:00', days_elapsed: 13, week: 2, weeks_total: 12, completed: false },
    ...overrides,
  });
}

/** A missed routine as in `today.missed[]` (family offset); `kind` = the mess of a missed clean (M5-R02). */
export function makeMissed(
  type: 'feed' | 'water' | 'clean' | 'walk' | 'training',
  opensAt: string,
  dueAt: string,
  date = '2026-10-04',
  kind: 'poop' | 'accident' | 'chewing' | null = null,
) {
  return { type, kind, date, opens_at: opensAt, due_at: dueAt };
}

/** `GET /api/parent/dashboard` for a family with children and pets (M2-05 shape). */
export function makeScoredDashboard(children: FamilyChild[], pets: FamilyPetRaw[]) {
  return {
    timezone: 'Europe/Ljubljana',
    pet: { id: pets[0]?.id ?? 7, breed_type: 'mutt', escalation_level: 0 },
    child: children[0] ? { id: children[0].id, name: children[0].name } : null,
    traffic_light: 'green',
    quiet_hours: null,
    recent_activities: [],
    weekly_performance: [],
    family: { id: 1, timezone: 'Europe/Ljubljana', parents: [{ id: 1, name: 'Starš', is_me: true }], children, pets },
  };
}

/** A pet of `family.pets`. */
export function makeFamilyPet(overrides: Partial<FamilyPetRaw> = {}): FamilyPetRaw {
  return {
    id: 7,
    breed_type: 'mutt',
    born_at: '2026-10-01T08:00:00+00:00',
    awaiting_contract: false,
    is_active: true,
    is_game_over: false,
    is_hard_stopped: false,
    is_ill: false,
    escalation_level: 0,
    // M3-11 (additive): plan + payment status — a grandfathered paid challenge.
    plan: { type: 'challenge', status: 'paid', trial_ends_at: '2026-10-08T10:00:00+02:00', paid_at: '2026-10-01T10:00:00+02:00', payments_enforced: true },
    profile: makePetProfile(),
    caretakers: [],
    // M2-05 / M2-06: spec traffic light, metrics, Care Score, today, timeline.
    metrics: { hunger: 100, thirst: 100, energy: 100, hygiene: 100 },
    timeline: [],
    // M5-R02 behaviour events: nothing open.
    behaviour: { take_out: null, active_events: [], scene: null },
    // M5-R03 training: off.
    training: { enabled: false, commands: [], today_done: false, session_active: false },
    media: makeMedia(),
    traffic_light: { color: 'green', reasons: [] },
    care_score: { score: null, done: 0, expected: 0, illnesses: 0, since: '2026-10-01T08:00:00+00:00' },
    today: {
      date: '2026-10-04',
      expected: 0,
      done: 0,
      done_by_child: null,
      pending: 0,
      missed_count: 0,
      missed: [],
    },
    ...overrides,
  };
}

/** `GET /api/parent/dashboard` for a family without an active legacy pet. */
export function makeFamilyDashboard(children: FamilyChild[], pets: FamilyPetRaw[] = []) {
  return {
    message: children.length > 0 ? 'No active pet session found.' : 'No child profile paired yet.',
    timezone: 'Europe/Ljubljana',
    pet: null,
    traffic_light: 'green',
    quiet_hours: null,
    recent_activities: [],
    family: { id: 1, timezone: 'Europe/Ljubljana', parents: [{ id: 1, name: 'Starš', is_me: true }], children, pets },
  };
}

/** A puppy's bladder clock (M5-R02): 2 h hold, started 11:00, due 13:00 Ljubljana on 2026-10-04. */
export function makeTakeOut(overrides: Partial<NonNullable<RawState['behaviour']['take_out']>> = {}) {
  return {
    hold_hours: 2,
    clock_started_at: '2026-10-04T11:00:00+02:00',
    next_due_at: '2026-10-04T13:00:00+02:00',
    last_taken_out_at: '2026-10-04T11:00:00+02:00',
    ...overrides,
  };
}

/** An open mess (M5-R02) with its 2-hour deadline (Ljubljana offset). */
export function makeBehaviourEvent(
  kind: 'poop' | 'accident' | 'chewing',
  overrides: Partial<RawState['behaviour']['active_events'][number]> = {},
) {
  return {
    id: 1,
    kind,
    started_at: '2026-10-04T11:30:00+02:00',
    due_at: '2026-10-04T13:30:00+02:00',
    ...overrides,
  };
}

// ── M5-R03 training ──────────────────────────────────────────

/** All four commands; `progress` per command (default 0). */
export function makeTrainingCommands(progress: Partial<Record<'sit' | 'come' | 'place' | 'potty', number>> = {}) {
  return (['sit', 'come', 'place', 'potty'] as const).map((command) => {
    const p = progress[command] ?? 0;
    return { command, progress: p, learned: p >= 100, last_practised_at: p > 0 ? '2026-10-03T17:00:00+02:00' : null };
  });
}

/** `training` of a pet with training: nothing done today, 6 sessions left, may start. */
export function makeEnabledTraining(overrides: Partial<ChildPetState['training']> = {}): ChildPetState['training'] {
  return makeTrainingState({
    enabled: true,
    commands: makeTrainingCommands({ sit: 40, come: 100 }),
    daily_budget_seconds: 300,
    daily_budget_left_seconds: 300,
    children_sharing: 1,
    my_share_seconds: 300,
    my_seconds_left: 300,
    can_start: true,
    ...overrides,
  });
}

/** Obeys pattern of `makeTrainingSessionPayload` (8 cues, 2 s lead-in, every 6 s). */
export const TRAINING_OBEYS = [true, false, true, true, false, true, true, false] as const;
/** The dog obeys 1 000 ms after each obeyed cue; the praise window is 1 500 ms. */
export const TRAINING_OBEY_DELAY_MS = 1_000;

/** `session` of a `training/start` 200 (instants Ljubljana, 2026-10-04 12:00). */
export function makeTrainingSessionPayload(overrides: Record<string, unknown> = {}) {
  return {
    id: '7b0d7a8e-3c1f-4f7e-9d65-0a6a9c0b1e11',
    command: 'sit',
    started_at: '2026-10-04T12:00:00+02:00',
    ends_at: '2026-10-04T12:00:50+02:00',
    expires_at: '2026-10-04T12:01:50+02:00',
    duration_ms: 50_000,
    praise_window_ms: 1_500,
    min_reaction_ms: 150,
    trials: TRAINING_OBEYS.map((obeys, index) => {
      const cue = 2_000 + index * 6_000;
      return {
        index,
        cue_at_ms: cue,
        obeys,
        obey_at_ms: obeys ? cue + TRAINING_OBEY_DELAY_MS : null,
        window_end_ms: obeys ? cue + TRAINING_OBEY_DELAY_MS + 1_500 : null,
      };
    }),
    ...overrides,
  };
}

/** `result` of a `training/finish` 200. */
export function makeTrainingResult(overrides: Record<string, unknown> = {}) {
  return {
    session_id: '7b0d7a8e-3c1f-4f7e-9d65-0a6a9c0b1e11',
    command: 'sit',
    successes: 3,
    obeyed: 5,
    trials: TRAINING_OBEYS.map((obeys, index) => ({
      index,
      obeys,
      outcome: obeys ? (index < 4 ? 'in_time' : 'too_late') : 'waited',
      tap_ms: obeys ? 2_000 + index * 6_000 + 1_200 : null,
    })),
    progress_before: 40,
    progress_after: 43,
    progress_gain: 3,
    ...overrides,
  };
}

/**
 * `training.session` of the child state (PR #53): a sibling's session carries no schedule;
 * the child's own (`mine: true`) carries the whole schedule for a resume.
 */
export function makeRunningSession(
  overrides: Partial<NonNullable<ChildPetState['training']['session']>> = {},
): NonNullable<ChildPetState['training']['session']> {
  const mine = overrides.mine ?? false;
  const schedule = makeTrainingSessionPayload();
  return {
    id: 's1',
    command: 'come',
    started_at: '2026-10-04T11:59:40+02:00',
    ends_at: '2026-10-04T12:00:30+02:00',
    expires_at: '2026-10-04T12:01:30+02:00',
    mine,
    duration_ms: mine ? schedule.duration_ms : null,
    praise_window_ms: mine ? schedule.praise_window_ms : null,
    min_reaction_ms: mine ? schedule.min_reaction_ms : null,
    trials: mine ? schedule.trials : null,
    ...overrides,
  };
}

type GrowthEntryRaw = PetGrowthResponse['growth'][number];

/** One picture of `GET …/growth` (M5-R04 "Album rasti"): a 2-month puppy, taken 4. 10. 2026. */
export function makeGrowthEntry(overrides: Partial<GrowthEntryRaw> = {}): GrowthEntryRaw {
  const generation = overrides.generation ?? 1;
  return {
    generation,
    life_stage: 'puppy',
    age_months: 2,
    taken_at: '2026-10-04T09:00:00Z',
    is_current: false,
    image_url: `https://api.petprep.si/api/media/history/${generation}?expires=1&v=g${generation}&signature=a`,
    ...overrides,
  };
}

/** `GET /api/child/pet/growth` / `GET /api/parent/pets/7/growth` body. */
export function makeGrowth(
  growth: GrowthEntryRaw[] = [],
  expiresAt: string | null = '2026-10-04T10:00:00Z',
): PetGrowthResponse {
  return { pet_id: 7, growth, expires_at: expiresAt };
}

/** A puppy that became a young dog: two pictures, the second current. */
export function makeTwoStageGrowth(expiresAt: string | null = '2026-10-04T10:00:00Z'): PetGrowthResponse {
  return makeGrowth(
    [
      makeGrowthEntry({ generation: 1 }),
      makeGrowthEntry({ generation: 2, life_stage: 'young', age_months: 9, taken_at: '2026-11-24T08:00:00Z', is_current: true }),
    ],
    expiresAt,
  );
}
