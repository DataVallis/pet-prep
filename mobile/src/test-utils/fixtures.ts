/**
 * Shared test fixtures (not shipped: only imported from __tests__).
 */

import type { BreedFact, BreedFactsResponse, ChildPetState, FactOfTheDayResponse, PetGrowthResponse } from '@/api/client';
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

/** `preparation` as the schema types it (M5-T06). */
export type PreparationRaw = ChildPetState['pet']['preparation'];

/** `media` of a pet (M4-05): nothing stored yet. */
export function makeMedia(overrides: Partial<PetMedia> = {}): PetMedia {
  return {
    status: 'disabled',
    reference_image_url: null,
    videos: {},
    current_video_url: null,
    states: ['idle', 'sleeping'],
    expires_at: null,
    // M5-T08 (additive): no key images / transitions, older rules.
    keyframes: {},
    transitions: {},
    loops: [],
    state_media: false,
    // M5-T09 / T10 (additive): no school / game phase clips.
    poses: {},
    phases: {},
    phase_groups: [],
    // David 2026-10-23 (additive): no stored phase clips → no lengths.
    phase_seconds: {},
    // GAMES_SPEC phase 1 (additive): nothing measured from the clips yet.
    phase_meta: {},
    ...overrides,
  };
}

/** `preparation` of a pet (M5-T06): ready, nothing shown. */
export function makePreparation(overrides: Partial<PreparationRaw> = {}): PreparationRaw {
  return { ready: true, pending: [], preparing: false, running: false, ready_at: null, announce: false, school_pending: false, ...overrides };
}

/** A pet whose first things are still on their way (the set-up screen shows). */
export function makePreparing(pending: PreparationRaw['pending'] = ['idle', 'sleeping']): PreparationRaw {
  return { ready: false, pending, preparing: true, running: true, ready_at: null, announce: false, school_pending: false };
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
      species: 'dog',
      // M5-R08: no name (the pre-M5-R08 label).
      name: null,
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
      plan: { type: 'challenge', status: 'paid', trial_ends_at: '2026-10-11T09:00:00+00:00', paid_at: '2026-10-04T09:00:00+00:00', payments_enforced: true, display_type: 'challenge' },
      current_video_url: null,
      media_status: 'disabled',
      reference_image_url: null,
      media: makeMedia(),
      // M5-T06 (additive): ready (no preparation shown).
      preparation: makePreparation(),
      // M5-T13 (additive): no birthday today.
      birthday: null,
      ...petOverrides,
    },
    lock: { is_locked: false, reason: null, until: null },
    timezone: 'Europe/Ljubljana',
    server_time: '2026-10-04T09:00:00+00:00',
    // M5-T02 (additive): awake.
    sleep: { asleep: false, until: null },
    feeding: {
      windows: [],
      current_window: null,
      fed_in_current_window: false,
      can_feed: 'false',
      // M3-12 (additive): emergency meal at ≤ 20 % hunger.
      feed_mode: null,
      emergency_threshold: 20,
      next_feed_window: null,
      last_fed_at: '',
      // M5-R16 (additive): no digestion warning.
      digesting_until: null,
    },
    water: {
      times_per_day: 0,
      min_gap_minutes: 0,
      used_today: 0,
      remaining_today: 0,
      // M5-F11 (additive): water allowed at displayed thirst ≤ this.
      refill_below: 60,
      last_watered_at: '',
      can_water: 'false',
      next_allowed_at: '',
      // David 2026-10-25 (additive): display time of the next refill.
      shown_at: null,
    },
    steps: { steps_today: 0, my_steps_today: 0, my_device_steps_today: 0, goal: 5000, energy_level: 100, adapted_mode: false },
    contract: { signed: true, signed_at: '2026-10-04T09:00:00+00:00' },
    // M5-T11: »Prikaz« default.
    // GAMES_SPEC phase 1 (additive): game sounds and haptics on by default.
    settings: { media_display: 'video', easy_gestures: false, game_feedback: true },
    // M5-R02 behaviour events: no bladder clock, nothing open (a legacy-like pet).
    behaviour: { take_out: null, active_events: [], scene: null, can_take_out: false, can_resolve_chewing: false },
    // M5-R03 training: off (pet created by an app without the `training` feature).
    training: makeTrainingState(),
    // M5-R05 play & cuddle: none (a mutt / legacy-like pet has no play).
    play: null,
    // M5-R06-04 cat wand play: null for a dog.
    wand: null,
    // M5-R06-05 cat litter / brushed-cat grooming / scratching: null for a dog.
    litter: null,
    grooming: null,
    scratching: null,
    // M2-01d: the challenge is still running.
    certificate: null,
    // M5-R17 the puppy's weekly "new experience": null = no task this week.
    socialisation: null,
    // M5-R14 / R15 / R19 / R20: no breed care task, no heat warning.
    care_tasks: [],
    walk_heat: null,
    // M5-T05: absent by default (like an older server) so HUD tests don't get the first-launch card;
    // pass `care_guide: makeCareGuide()` to `makeLiveChildState` to show it.
    care_guide: null as unknown as ChildPetState['care_guide'],
    // M6-W04: older servers send no `wishes` — the default fixture leaves it out (no »Želje»).
    wishes: undefined as unknown as ChildPetState['wishes'],
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
    // M5-T02 (additive): why a start is refused and until when.
    blocked_reason: null,
    next_allowed_at: null,
    // David 2026-10-28 (additive): every school clip is there (school locked while false).
    media_ready: true,
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
  /** M5-R05 `play` (raw); default null (no play), `'absent'` drops the key (older server). */
  play?: unknown;
  /** M5-R06-08a cat blocks (raw); omitted = absent (a dog / older server). */
  wand?: unknown;
  litter?: unknown;
  grooming?: unknown;
  scratching?: unknown;
  /** M5-R17 the puppy's weekly "new experience" (raw); omitted = absent (no task / older server). */
  socialisation?: unknown;
  /** M5-R14 / R15 / R19 / R20 `care_tasks` / `walk_heat` (raw); omitted = none. */
  care_tasks?: unknown;
  walk_heat?: unknown;
  /** M5-T02 `sleep` (raw); default awake, `'absent'` drops the key (older server). */
  sleep?: unknown;
  /** M5-T05 `care_guide` (raw); omitted = null (older server, no »?« button). */
  care_guide?: unknown;
  /** M5-T11 / M5-T17 child settings (merged into the default `video` / no easy gestures). */
  settings?: Partial<RawState['settings']>;
  timezone?: string;
  server_time?: string;
}

/**
 * M5-T05: the server's `care_guide` of an adult mutt (raw, as the backend sends it): 2 meals,
 * 4.000 steps, water at ≤ 60 % / 180 min, night 21:00–07:00. `overrides` replace top-level keys.
 */
export function makeCareGuide(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    species: 'dog',
    life_stage: 'adult',
    meals: {
      per_day: 2,
      by_child: 2,
      by_parent: 0,
      windows: [
        { start: '07:00', end: '09:00', parent_covered: false },
        { start: '17:00', end: '19:00', parent_covered: false },
      ],
    },
    steps: { goal: 4000, exercise_minutes: 40 },
    take_out: null,
    water: { refill_below: 60, min_gap_minutes: 180 },
    grooming: null,
    sleep: { night: { start: '21:00', end: '07:00' }, school: null },
    play: null,
    litter: null,
    scratching: false,
    clean_within_hours: 2,
    digestion_gap_minutes: null,
    ...overrides,
  };
}

/** M5-R17: the server's `socialisation` block of an open puppy week (raw, as the backend sends it). */
export function makeSocialisation(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    goal_per_week: 1,
    done_this_week: 0,
    done: false,
    week: 2,
    week_started_at: '2026-09-29T08:00:00+02:00',
    week_ends_at: '2026-10-06T08:00:00+02:00',
    experience: null,
    experiences: ['person', 'dog', 'sound', 'place'],
    next_allowed_at: null,
    blocked_reason: null,
    can_record: true,
    ...overrides,
  };
}

/**
 * A realistic `GET /api/child/pet` body as the backend sends it (booleans and numbers,
 * not the loose strings `schema.ts` declares): Ljubljana family, 2026-10-04 12:00
 * local, mutt windows 06–10 / 17–21, fed this morning, water 1 of 3 used.
 */
/** M5-R14 / R15 / R19 / R20: one `care_tasks` entry (raw); default = teeth, 7 per week, may start. */
export function makeCareTask(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    task: 'tooth_brushing',
    times: 7,
    period_days: 7,
    done_this_period: 0,
    period_started_at: '2026-10-01T08:00:00+02:00',
    period_ends_at: '2026-10-08T08:00:00+02:00',
    session_seconds: 30,
    next_allowed_at: null,
    blocked_reason: null,
    can_start: true,
    session: null,
    session_running: false,
    ...overrides,
  };
}

/** A care task session (raw) as returned by `care-task/start`. */
export function makeCareTaskSession(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: '7e1f0c2a-3b4d-4e5f-8a9b-0c1d2e3f4a5b',
    task: 'tooth_brushing',
    started_at: '2026-10-04T12:00:00+02:00',
    ends_at: '2026-10-04T12:00:30+02:00',
    expires_at: '2026-10-04T12:01:30+02:00',
    duration_ms: 30000,
    min_strokes: 10,
    segments: 3,
    min_stroke_interval_ms: 150,
    ...overrides,
  };
}

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
      feed_mode: null,
      emergency_threshold: 20,
      next_feed_window: { start: '2026-10-04T17:00:00+02:00', end: '2026-10-04T21:00:00+02:00' },
      last_fed_at: '2026-10-04T07:10:00+02:00',
      digesting_until: null,
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
    // M5-T12: the device part follows my_steps_today unless a test sets it (no parent-logged walk).
    steps: {
      steps_today: 1250,
      my_steps_today: 1250,
      goal: 4000,
      energy_level: 30,
      ...o.steps,
      my_device_steps_today: o.steps?.my_device_steps_today ?? o.steps?.my_steps_today ?? 1250,
    },
    behaviour: o.behaviour === null ? undefined : { ...base.behaviour, ...o.behaviour },
    training: o.training === null ? undefined : { ...base.training, ...o.training },
    play: o.play === 'absent' ? undefined : o.play === undefined ? base.play : o.play,
    ...(o.wand !== undefined ? { wand: o.wand } : {}),
    ...(o.litter !== undefined ? { litter: o.litter } : {}),
    ...(o.grooming !== undefined ? { grooming: o.grooming } : {}),
    ...(o.scratching !== undefined ? { scratching: o.scratching } : {}),
    ...(o.socialisation !== undefined ? { socialisation: o.socialisation } : {}),
    ...(o.care_tasks !== undefined ? { care_tasks: o.care_tasks } : {}),
    ...(o.walk_heat !== undefined ? { walk_heat: o.walk_heat } : {}),
    sleep: o.sleep === 'absent' ? undefined : o.sleep === undefined ? base.sleep : o.sleep,
    ...(o.care_guide !== undefined ? { care_guide: o.care_guide } : {}),
    settings: { ...base.settings, ...o.settings },
  };
  return raw as unknown as ChildPetState;
}

/**
 * M5-R05 `play` as the backend sends it: play possible now, no invitation, not happy.
 * `invitation` / `mood` replace the defaults.
 */
export function makePlayState(
  overrides: {
    can_play?: boolean;
    media_ready?: boolean;
    invitation?: { id: number; kind: 'play' | 'cuddle'; expires_at: string } | null;
    mood?: { happy_until: string | null; scene: 'playing' | null };
  } = {},
) {
  return {
    can_play: true,
    media_ready: true,
    invitation: null,
    mood: { happy_until: null, scene: null },
    ...overrides,
  };
}

/** A child of `family.children` in `GET /api/parent/dashboard` (M2-01 / M2-02). */
export function makeFamilyChild(overrides: Partial<FamilyChild> = {}): FamilyChild {
  return {
    id: 5,
    name: 'Maja',
    birth_year: 2016,
    login: 'pin',
    devices: 0,
    adapted_mode: false,
    media_display: 'video',
    easy_gestures: false,
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
    certificate: null,
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
    walk_unit: 'steps' as 'steps' | 'pushes',
    walk_logged_minutes: 0,
    // M5-R06-08b: a cat's play of the day (null for a dog).
    play_sessions: null,
    play_goal: null,
    play_done: null,
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
    species: 'dog',
    // M5-R08: no name (the pre-M5-R08 label).
    name: null,
    born_at: '2026-10-01T08:00:00+00:00',
    awaiting_contract: false,
    is_active: true,
    is_game_over: false,
    is_hard_stopped: false,
    is_ill: false,
    escalation_level: 0,
    // M3-11 (additive): plan + payment status — a grandfathered paid challenge.
    plan: { type: 'challenge', status: 'paid', trial_ends_at: '2026-10-08T10:00:00+02:00', paid_at: '2026-10-01T10:00:00+02:00', payments_enforced: true, display_type: 'challenge' },
    profile: makePetProfile(),
    caretakers: [],
    // M2-05 / M2-06: spec traffic light, metrics, Care Score, today, timeline.
    metrics: { hunger: 100, thirst: 100, energy: 100, hygiene: 100 },
    timeline: [],
    // M5-R02 behaviour events: nothing open.
    behaviour: { take_out: null, active_events: [], scene: null },
    // M5-R03 training: off.
    training: { enabled: false, commands: [], today_done: false, session_active: false },
    // M5-R05 play & cuddle: none (mutt).
    play_today: null,
    media: makeMedia(),
    // M5-T06: ready.
    preparation: makePreparation(),
    // M5-T05b: the care guide (same block as the child state).
    care_guide: makeCareGuide() as unknown as FamilyPetRaw['care_guide'],
    // M5-T13: no birthday.
    birthday: null,
    // M5-R18 / R20: no groomer / bath reminder.
    care_reminders: [],
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
  // M5-R06-08b: the cat's litter_accident / scratching too (the server's kind union).
  kind: 'poop' | 'accident' | 'chewing' | 'litter_accident' | 'scratching',
  overrides: Partial<Omit<RawState['behaviour']['active_events'][number], 'kind'>> = {},
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

/**
 * School smooth playback (2026-10-28): a 6-cue schedule with the server's `perform_at_ms` /
 * `release_at_ms` (think 500 ms, performing 3 s, release 2.5 s, 700 ms calm; ignored cue 4.5 s).
 * Obeys: true, false, true, true, false, true.
 */
export const SMOOTH_TRAINING_OBEYS = [true, false, true, true, false, true] as const;
export function makeSmoothTrainingSessionPayload(overrides: Record<string, unknown> = {}) {
  let cue = 2_000;
  const trials = SMOOTH_TRAINING_OBEYS.map((obeys, index) => {
    const at = cue;
    if (!obeys) {
      cue = at + 4_500;
      return { index, cue_at_ms: at, obeys, obey_at_ms: null, window_end_ms: null, perform_at_ms: null, release_at_ms: null };
    }
    const perform = at + 500;
    const obey = perform + 3_000;
    const windowEnd = obey + 1_500;
    cue = windowEnd + 2_500 + 700;
    return { index, cue_at_ms: at, obeys, obey_at_ms: obey, window_end_ms: windowEnd, perform_at_ms: perform, release_at_ms: windowEnd };
  });
  return makeTrainingSessionPayload({ duration_ms: cue, trials, ...overrides });
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

// ── M5-R06-08a cat care (raw payloads as the backend sends them) ──

/** `wand/start` `session`: 60 s, pounces at 15 / 30 / 45 s, Ljubljana 12:00. */
export function makeWandSession(overrides: Record<string, unknown> = {}) {
  return {
    id: '7d1c5c0e-1111-4a5b-9c2d-000000000001',
    started_at: '2026-10-04T12:00:00+02:00',
    ends_at: '2026-10-04T12:01:00+02:00',
    expires_at: '2026-10-04T12:02:00+02:00',
    duration_ms: 60_000,
    catch_at_ms: 60_000,
    pounces_ms: [15_000, 30_000, 45_000],
    min_away_moves: 8,
    segments: 4,
    min_move_interval_ms: 300,
    pounce_window_ms: 2_000,
    ...overrides,
  };
}

/** Child state `wand`: goal 2, none today, may start. */
export function makeWandState(overrides: Record<string, unknown> = {}) {
  return {
    goal: 2,
    sessions_today: 0,
    my_sessions_today: 0,
    min_gap_minutes: 120,
    next_allowed_at: null,
    blocked_reason: null,
    can_start: true,
    session: null,
    session_running: false,
    missed_yesterday: false,
    ...overrides,
  };
}

/** `grooming/start` / `litter-change/start` `session`: 30 s, 10 strokes in thirds. */
export function makeChoreSession(kind: 'grooming' | 'litter_change' = 'grooming', overrides: Record<string, unknown> = {}) {
  return {
    id: `7d1c5c0e-2222-4a5b-9c2d-00000000000${kind === 'grooming' ? '2' : '3'}`,
    kind,
    started_at: '2026-10-04T12:00:00+02:00',
    ends_at: '2026-10-04T12:00:30+02:00',
    expires_at: '2026-10-04T12:01:30+02:00',
    duration_ms: 30_000,
    min_strokes: 10,
    segments: 3,
    min_stroke_interval_ms: 150,
    matted: false,
    ...overrides,
  };
}

/** Child state `litter`: one open use due at 15:00, weekly change open. */
export function makeLitterState(overrides: Record<string, unknown> = {}, change: Record<string, unknown> | null = {}) {
  return {
    uses_per_day: 2,
    open_uses: [{ id: 11, used_at: '2026-10-04T11:00:00+02:00', due_at: '2026-10-04T15:00:00+02:00', expired: false }],
    next_due_at: '2026-10-04T15:00:00+02:00',
    scoop_deadline_hours: 4,
    can_scoop: true,
    change:
      change === null
        ? null
        : {
            week_started_at: '2026-10-01T09:30:00+02:00',
            due_at: '2026-10-08T09:30:00+02:00',
            done: false,
            overdue: false,
            blocked_reason: null,
            can_start: true,
            session: null,
            session_running: false,
            ...change,
          },
    ...overrides,
  };
}

/** Child state `grooming` (Maine Coon): 1 of 3 this week, may start. */
export function makeGroomingState(overrides: Record<string, unknown> = {}) {
  return {
    goal_per_week: 3,
    done_this_week: 1,
    week_started_at: '2026-10-01T09:30:00+02:00',
    week_ends_at: '2026-10-08T09:30:00+02:00',
    matted: false,
    matted_since: null,
    session_seconds: 30,
    next_allowed_at: null,
    blocked_reason: null,
    can_start: true,
    session: null,
    session_running: false,
    ...overrides,
  };
}

/** `scratching/start` `session`: lands at 1.2 s, 3 s window, 150 ms floor. */
export function makeScratchingSession(overrides: Record<string, unknown> = {}) {
  return {
    id: '7d1c5c0e-4444-4a5b-9c2d-000000000004',
    started_at: '2026-10-04T12:00:00+02:00',
    ends_at: '2026-10-04T12:00:04.200+02:00',
    expires_at: '2026-10-04T12:00:34+02:00',
    land_at_ms: 1_200,
    praise_window_ms: 3_000,
    min_reaction_ms: 150,
    ...overrides,
  };
}

/** Child state `scratching`: an open scratched sofa (due 14:00), may start. */
export function makeScratchingState(overrides: Record<string, unknown> = {}) {
  return {
    active: { id: 21, started_at: '2026-10-04T11:30:00+02:00', due_at: '2026-10-04T14:00:00+02:00' },
    blocked_reason: null,
    can_start: true,
    session: null,
    session_running: false,
    ...overrides,
  };
}

/**
 * M2-01d: a `CertificatePayload` (raw, as the server sends it — dashboard child `certificate`,
 * list item, generate body). Default: a certificate that waits for "Ustvari certifikat".
 */
export function makeCertificatePayload(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: 31,
    child_id: 2,
    child_nickname: 'Luka',
    pet_id: 7,
    species: 'dog',
    breed_type: 'border_collie',
    outcome: 'certificate',
    reason: null,
    care_score: 86,
    number: 'PP-2026-000031',
    started_at: '2026-07-20T16:00:00+02:00',
    completed_at: '2026-10-12T16:00:00+02:00',
    completed_local_date: '2026-10-12',
    generated: false,
    generated_at: null,
    child_name: null,
    pet_name: null,
    locale: null,
    defaults: { child_name: 'Luka', pet_name: 'Luna', locale: 'sl' },
    pdf_url: null,
    ...overrides,
  };
}

/** M2-01d: the same payload, generated (printed names, signed PDF URL). */
export function makeGeneratedCertificatePayload(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return makeCertificatePayload({
    generated: true,
    generated_at: '2026-10-12T18:00:00+02:00',
    child_name: 'Luka',
    pet_name: 'Luna',
    locale: 'sl',
    pdf_url: 'https://api.petprep.si/api/certificates/31/pdf?expires=1&v=abc&signature=sig',
    ...overrides,
  });
}

/** M2-01d: a report (no certificate): score below 70 by default. */
export function makeReportPayload(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return makeCertificatePayload({ outcome: 'report', reason: 'low_score', care_score: 58, number: null, ...overrides });
}

/** M5-R09c: one approved child fact as `GET /api/breeds/{breed}/facts` sends it (border collie, unread). */
export function makeBreedFact(overrides: Partial<BreedFact> = {}): BreedFact {
  return {
    id: 1,
    key: 'border_collie-001',
    breed: 'border_collie',
    category: 'exercise',
    audience: 'child',
    title: 'Border collieji so bili vzrejeni za delo z ovcami.',
    why: 'Zato tvoj ljubljenček potrebuje veliko gibanja.',
    source_name: 'The Royal Kennel Club',
    source_url: null,
    game_rule_key: 'daily_steps',
    not_vet_reviewed: false,
    read: false,
    read_at: null,
    read_by_children: [],
    ...overrides,
  };
}

/** M5-R09c: a breed's fact list (read count derived from the facts). */
export function makeBreedFacts(facts: BreedFact[], breed = 'border_collie'): BreedFactsResponse {
  return { breed, facts, read_count: facts.filter((f) => f.read).length, total_count: facts.length };
}

/** M5-R09c: `GET /api/child/fact-of-the-day` — a day the sheet may open by itself. */
export function makeFactOfTheDay(overrides: Partial<FactOfTheDayResponse> = {}): FactOfTheDayResponse {
  return {
    fact: makeBreedFact() as FactOfTheDayResponse['fact'],
    may_auto_show: true,
    reason: null,
    shown_today: false,
    shown_this_week: 0,
    program_week: 2,
    read_count: 0,
    total_count: 1,
    ...overrides,
  };
}

/** M5-T17: the same child view with »Lažje kretnje« on (hold / button mode in the games). */
export function withEasyGestures<V extends { settings: { media_display: 'video' | 'picture'; easy_gestures: boolean } }>(view: V, on = true): V {
  return { ...view, settings: { ...view.settings, easy_gestures: on } };
}
