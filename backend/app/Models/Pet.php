<?php

namespace App\Models;

use App\Enums\BreedType;
use App\Enums\ChallengePaidSource;
use App\Enums\ChallengeStatus;
use App\Enums\HygieneEventKind;
use App\Enums\HygieneEventStatus;
use App\Enums\LifeStage;
use App\Enums\PetLockReason;
use App\Enums\PetOrigin;
use App\Enums\PetPlan;
use App\Enums\PetStateEnum;
use App\Enums\PetStatusPeriodKind;
use App\Enums\Species;
use App\Jobs\DeletePetMediaFiles;
use App\Services\FamilyService;
use App\Services\LifeStageService;
use App\Services\PairingService;
use App\Services\PetStatusPeriodService;
use App\Services\PlayService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Pet extends Model
{
    use HasFactory;

    /**
     * Metric columns. Stored as double precision (no rounding loss in the
     * decay engine); every API / broadcast payload exposes them as integers
     * 0–100 via displayMetric().
     */
    public const METRICS = ['hunger_level', 'thirst_level', 'energy_level', 'hygiene_level'];

    protected static function booted(): void
    {
        // Spend rows outlive the pet (M2-08; see AiSpendLedger::detachPets — the
        // FK cascade alone fails for a row linked to both the pet and its slot).
        static::deleting(function (Pet $pet): void {
            AiSpendLedger::detachPets([$pet->id]);
        });

        // AI media files (M4-05) go with the pet; the pet_media rows cascade in the DB.
        // Queued after commit (M2-08) — the same job AccountDeletionService uses
        // for its bulk deletes, which fire no model events.
        static::deleted(function (Pet $pet): void {
            DeletePetMediaFiles::dispatch([$pet->id])->afterCommit();
        });

        // Start the decay clock at creation so the first tick decays from birth.
        // An unborn pet (born_at null, waiting for the contract — M1-07b)
        // gets its clocks at birth instead (PetActivityService::signContract).
        static::creating(function (Pet $pet): void {
            // Family model (M2-01): a pet always belongs to a family. Code
            // that only sets the deprecated pets.user_id (seeders, admin,
            // old tests) gets the owner child's family.
            if ($pet->family_id === null) {
                $owner = $pet->user_id !== null ? User::find($pet->user_id) : null;
                $pet->family_id = $owner !== null
                    ? app(FamilyService::class)->ensureFamilyFor($owner)->id
                    : Family::create([])->id;
            }

            // M5-R06-01: no breed → the free dog breed (the mutt).
            $pet->breed_type ??= Species::Dog->freeBreed();
            $pet->species = $pet->breed_type->species();

            // M3-11: a pet's plan is fixed at creation; only a challenge has a trial.
            // PAYMENTS_SPEC P4 / M5-F03: the default follows the breed, and an unpaid
            // challenge of a breed without premium (`breed_configs.premium_unlock` —
            // the free breed of its species: mutt, domestic cat; M5-R06-01) is the
            // free plan — no creation path (admin, seeders, legacy pairing) makes a
            // lockable free-breed challenge.
            $pet->plan ??= PairingService::defaultPlanFor($pet->breed_type);
            if ($pet->plan === PetPlan::Challenge && $pet->challenge_paid_at === null
                && ! $pet->breed_type->isPremium()) {
                $pet->plan = PetPlan::Free;
                $pet->trial_ends_at = null;
                $pet->payment_locked_at = null;
            }

            if ($pet->isUnborn()) {
                return;
            }

            // Born at creation (factories, admin). M3-13 (David 2026-10-08): no free
            // trial any more — an unpaid challenge awaits payment from birth
            // (trial_ends_at = born_at; the tick locks it).
            if ($pet->plan === PetPlan::Challenge && $pet->trial_ends_at === null) {
                $pet->trial_ends_at = $pet->born_at;
            }

            $pet->last_decay_at ??= now();
            // Birth day = first step day; energy resets at the next local midnight (M1-04).
            $pet->last_step_reset_at ??= now();

            if ($pet->isFrozen()) {
                $pet->frozen_at ??= now();
            }
        });

        // The primary caretaker (deprecated pets.user_id) is always a
        // caretaker row too (M2-01). A pet born at creation (legacy /
        // factories) needs no contract, like the grandfathered pets.
        // Only a child owner becomes a caretaker ("only children are
        // caretakers"); an admin assigning a parent as owner gets none.
        static::created(function (Pet $pet): void {
            if ($pet->user_id !== null
                && User::whereKey($pet->user_id)->where('role', 'child')->exists()
                && ! PetCaretaker::where('pet_id', $pet->id)->where('user_id', $pet->user_id)->exists()) {
                PetCaretaker::create([
                    'pet_id' => $pet->id,
                    'user_id' => $pet->user_id,
                    'requires_contract' => $pet->isUnborn(),
                ]);
            }

            app(PetStatusPeriodService::class)->recordCreated($pet);
            $pet->forgetProgramPauses();
        });

        // History of hard stops, illnesses and inactive periods for the
        // routine ledger (M2-06). Runs inside the writer's transaction.
        static::updated(function (Pet $pet): void {
            app(PetStatusPeriodService::class)->recordUpdated($pet);
            // M5-R05: a pet that ends (game over, deactivated) drops its open
            // play invitations — the tick no longer processes it.
            if (($pet->wasChanged('is_game_over') && $pet->is_game_over)
                || ($pet->wasChanged('is_active') && ! $pet->is_active)) {
                app(PlayService::class)->skipPending($pet);
            }
            // A payment lock / payment just opened or closed a period (M3-11b).
            $pet->forgetProgramPauses();
        });

        // Freeze bookkeeping (M1-02). Hard stop and illness freeze both the
        // metrics and the neglect clocks (*_zero_since):
        //  - entering a freeze stamps `frozen_at`;
        //  - lifting a hard stop thaws immediately, shifting *_zero_since
        //    forward by the frozen duration and restarting the decay clock;
        //  - the end of an illness is a fresh start (recoverFromIllnessIfDue,
        //    David 2026-10-03): hygiene 100 %, neglect clocks restart.
        // Re-activating a pet (or undoing game over) restarts the decay clock.
        // These hooks work even when no scheduler tick ran during the freeze.
        // M5-R06-01 (plan T2): the species always follows the breed
        // (pets_species_breed_check) — also when an admin changes the breed.
        // Only on insert or a breed change (QA PR #91 m1): a model loaded without
        // the column (older migrations, select lists) must not write it.
        static::saving(function (Pet $pet): void {
            if ($pet->breed_type instanceof BreedType && (! $pet->exists || $pet->isDirty('breed_type'))) {
                $pet->species = $pet->breed_type->species();
            }
        });

        static::updating(function (Pet $pet): void {
            $now = now();

            // An illness that ended before this write: fresh start first.
            $pet->recoverFromIllnessIfDue($now);

            if ($pet->isFrozen()) {
                $pet->frozen_at ??= $now;
            } elseif ($pet->frozen_at !== null) {
                $pet->applyThaw($now);
            }

            $reactivated = ($pet->isDirty('is_active') && $pet->is_active)
                || ($pet->isDirty('is_game_over') && ! $pet->is_game_over)
                || ($pet->isDirty('is_hard_stopped') && ! $pet->is_hard_stopped)
                // M3-11: the payment lock lifted (challenge paid).
                || ($pet->isDirty('payment_locked_at') && $pet->payment_locked_at === null);

            if ($reactivated && ! $pet->isDirty('last_decay_at')) {
                $pet->last_decay_at = $now;
            }

            // A pet that comes back (re-activated, game over undone) never
            // inherits a walk illness planned before.
            if (($pet->isDirty('is_active') && $pet->is_active)
                || ($pet->isDirty('is_game_over') && ! $pet->is_game_over)) {
                $pet->walk_illness_due_at = null;
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'family_id',
        'breed_type',
        'species',
        'pet_dna',
        // M4-10: the shared look of a free pool pet (null = unique DNA).
        'pet_look_id',
        'current_video_url',
        'media_status',
        'media_error',
        'hunger_level',
        'thirst_level',
        'energy_level',
        'hygiene_level',
        'daily_step_count',
        'last_step_reset_at',
        'last_step_sync_at',
        'last_decay_at',
        'born_at',
        'is_active',
        'pet_state',
        'illness_until',
        'walk_illness_due_at',
        'escalation_level',
        'hunger_zero_since',
        'thirst_zero_since',
        'energy_zero_since',
        'hygiene_zero_since',
        'is_game_over',
        'is_hard_stopped',
        'certificate_eligible',
        // M5-R01 pet profile: parent's choice at creation; life_stage is the
        // stage of today's rules (LifeStageService::syncStage, decay tick).
        'origin',
        'arrival_age_months',
        'life_stage',
        // M5-R02 / PR #42: set once at creation from the app's generate-pin `features`.
        'behaviour_events_enabled',
        // M5-R03: set once at creation (generate-pin + pin-login `features: ["training"]`).
        'training_enabled',
        // M3-11: plan chosen at creation (generate-pin `plan`). Payment state
        // (trial_ends_at, challenge_paid_*, payment_locked_at) is written only
        // by ChallengeService / Pet::giveBirth via forceFill.
        'plan',
    ];

    /**
     * Routine-ledger bookkeeping (M2-06) and the AI media error reason
     * (M4-07, admin only) never leave the server.
     *
     * @var list<string>
     */
    protected $hidden = [
        'routines_closed_through',
        'routines_next_close_at',
        'media_error',
        // M5-R02 bookkeeping (the apps get BehaviourPayload instead).
        'potty_clock_started_at',
        'behaviour_scheduled_through',
        // M5-R03 bookkeeping (the apps get TrainingPayload instead).
        'training_learning_factor',
        'training_decayed_through',
        // M3-11 bookkeeping (the apps get PetPlanPayload instead).
        'trial_reminder_sent_at',
        // M5-F02: when an unpaid mutt challenge became the free plan (program clock bookkeeping).
        'converted_to_free_at',
        // M5-R05 bookkeeping (the apps get PlayPayload instead).
        'happy_until',
        'play_scheduled_through',
        // M5-R06-04 bookkeeping: last missed cat play day (the apps get WandPayload).
        'play_missed_on',
        // M5-R06-05 bookkeeping (the apps get GroomingPayload instead).
        'coat_matted_at',
        'grooming_weeks_checked',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'breed_type' => BreedType::class,
            'species' => Species::class,
            'pet_state' => PetStateEnum::class,
            'pet_dna' => 'array',
            'born_at' => 'datetime',
            'illness_until' => 'datetime',
            'walk_illness_due_at' => 'datetime',
            'last_step_reset_at' => 'datetime',
            'last_step_sync_at' => 'datetime',
            'last_decay_at' => 'datetime',
            'frozen_at' => 'datetime',
            'potty_clock_started_at' => 'datetime',
            'behaviour_events_enabled' => 'boolean',
            'training_enabled' => 'boolean',
            'training_learning_factor' => 'float',
            'hunger_level' => 'float',
            'thirst_level' => 'float',
            'energy_level' => 'float',
            'hygiene_level' => 'float',
            'hunger_zero_since' => 'datetime',
            'thirst_zero_since' => 'datetime',
            'energy_zero_since' => 'datetime',
            'hygiene_zero_since' => 'datetime',
            'is_active' => 'boolean',
            'is_game_over' => 'boolean',
            'is_hard_stopped' => 'boolean',
            'certificate_eligible' => 'boolean',
            'origin' => PetOrigin::class,
            'arrival_age_months' => 'integer',
            'life_stage' => LifeStage::class,
            'plan' => PetPlan::class,
            'trial_ends_at' => 'datetime',
            'challenge_paid_at' => 'datetime',
            'challenge_paid_source' => ChallengePaidSource::class,
            'payment_locked_at' => 'datetime',
            'trial_reminder_sent_at' => 'datetime',
            'converted_to_free_at' => 'datetime',
            'happy_until' => 'datetime',
            'coat_matted_at' => 'datetime',
            'grooming_weeks_checked' => 'integer',
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Metric display (integers for API / broadcast / admin)
    // ──────────────────────────────────────────────────────────────

    /**
     * Round a precise metric value for display: half up, clamped to 0–100.
     */
    public static function displayValue(float|int|string|null $value): int
    {
        if ($value === null) {
            return 0;
        }

        return (int) max(0, min(100, round((float) $value, 0, PHP_ROUND_HALF_UP)));
    }

    /**
     * The displayed (integer) value of one metric column.
     */
    public function displayMetric(string $metric): int
    {
        return self::displayValue($this->getAttribute($metric));
    }

    /**
     * Displayed integer values of all four metrics, keyed by column.
     *
     * @return array<string, int>
     */
    public function displayMetrics(): array
    {
        $values = [];
        foreach (self::METRICS as $metric) {
            $values[$metric] = $this->displayMetric($metric);
        }

        return $values;
    }

    /**
     * Serialize metrics as integers so any `response()->json($pet)` keeps
     * the integer contract the mobile app relies on.
     *
     * @return array<string, mixed>
     */
    public function attributesToArray()
    {
        $attributes = parent::attributesToArray();

        foreach (self::METRICS as $metric) {
            if (isset($attributes[$metric])) {
                $attributes[$metric] = self::displayValue($attributes[$metric]);
            }
        }

        return $attributes;
    }

    // ──────────────────────────────────────────────────────────────
    //  Relationships
    // ──────────────────────────────────────────────────────────────

    /**
     * @deprecated M2-01 — use caretakers(). The primary caretaker (the child
     * the pet was created for), kept for old app builds.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The family this pet belongs to (M2-01). The pet is the billing unit
     * (one 12-week challenge per pet, shared or not).
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * Children who care for this pet (one, or several for a shared pet).
     */
    public function caretakers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'pet_caretakers')
            ->withPivot(['requires_contract'])
            ->wherePivotNull('ended_at')
            ->withTimestamps()
            ->orderBy('pet_caretakers.id');
    }

    /**
     * Active caretaker rows (M2-08: a deleted child's tombstone is history only).
     */
    public function caretakerRows(): HasMany
    {
        return $this->hasMany(PetCaretaker::class)->active()->orderBy('id');
    }

    /**
     * Contracts, one per caretaker child (M2-01).
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(PetContract::class);
    }

    public function hasCaretaker(User $user): bool
    {
        return PetCaretaker::where('pet_id', $this->id)->where('user_id', $user->id)->exists();
    }

    /**
     * True when $child is a caretaker who still has to sign their own
     * contract before acting (M2-01: the pet is born at the first contract;
     * a child who joins a born pet signs too). Grandfathered caretaker rows
     * (requires_contract false) never need one.
     */
    public function caretakerNeedsContract(User $child): bool
    {
        $requires = PetCaretaker::where('pet_id', $this->id)
            ->where('user_id', $child->id)
            ->value('requires_contract');

        return (bool) $requires
            && ! PetContract::where('pet_id', $this->id)->where('user_id', $child->id)->exists();
    }

    /**
     * One child's own steps today on this pet (pet_daily_steps). Steps the
     * pet counts that no child row explains (from before M2-01) belong to
     * the primary caretaker.
     */
    public function stepsTodayOf(User $child, ?CarbonInterface $now = null): int
    {
        $rows = PetDailyStep::where('pet_id', $this->id)
            ->where('local_date', $this->localDate($now ?? now()))
            ->pluck('steps', 'user_id');

        $own = (int) ($rows[$child->id] ?? 0);

        if ((int) $this->user_id === (int) $child->id) {
            $own += max(0, (int) $this->daily_step_count - (int) $rows->sum());
        }

        return $own;
    }

    /**
     * The contract $child signed for this pet, if any.
     */
    public function contractOf(User $child): ?PetContract
    {
        return PetContract::where('pet_id', $this->id)->where('user_id', $child->id)->first();
    }

    /**
     * The activity log entries for this pet.
     */
    public function activities(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /**
     * Scheduled random hygiene events (M1-05).
     */
    public function hygieneEvents(): HasMany
    {
        return $this->hasMany(PetHygieneEvent::class);
    }

    /**
     * Closed days of the daily walk rule (one row per family-local day).
     */
    public function dailyWalks(): HasMany
    {
        return $this->hasMany(PetDailyWalk::class);
    }

    /**
     * Hard stops, illnesses and inactive periods (M2-06 routine ledger).
     */
    public function statusPeriods(): HasMany
    {
        return $this->hasMany(PetStatusPeriod::class);
    }

    /**
     * Payment-lock periods only (M3-11b program clock). Eager-load it in
     * list endpoints (`->with('paymentLockPeriods')`) so paymentLockSpans()
     * needs no query per pet.
     */
    public function paymentLockPeriods(): HasMany
    {
        return $this->hasMany(PetStatusPeriod::class)->where('kind', PetStatusPeriodKind::PaymentLock->value);
    }

    /**
     * Materialised routines of closed days (M2-06).
     */
    public function dailyRoutines(): HasMany
    {
        return $this->hasMany(PetDailyRoutine::class);
    }

    /**
     * The first responsibility contract signed for this pet (the one that
     * birthed it, M1-07b). Per-child contracts: contracts() / contractOf().
     */
    public function contract(): HasOne
    {
        return $this->hasOne(PetContract::class)->oldestOfMany('signed_at');
    }

    /**
     * AI media slots (reference image + state videos, M4-03 / M4-05).
     */
    public function media(): HasMany
    {
        return $this->hasMany(PetMedia::class);
    }

    /**
     * The shared look of a free pool pet (M4-10); null for a pet with its own DNA.
     */
    public function look(): BelongsTo
    {
        return $this->belongsTo(PetLook::class, 'pet_look_id');
    }

    public function usesLookPool(): bool
    {
        return $this->pet_look_id !== null;
    }

    /**
     * Training progress per command (M5-R03).
     */
    public function trainingSkills(): HasMany
    {
        return $this->hasMany(PetTrainingSkill::class);
    }

    /**
     * Training mini-game sessions (M5-R03).
     */
    public function trainingSessions(): HasMany
    {
        return $this->hasMany(PetTrainingSession::class);
    }

    /**
     * Play invitations and finished plays / cuddles (M5-R05, mood only).
     */
    public function playEvents(): HasMany
    {
        return $this->hasMany(PetPlayEvent::class);
    }

    /**
     * Server-driven cat care sessions (M5-R06-04: wand play; M5-R06-05:
     * grooming, litter change).
     */
    public function careSessions(): HasMany
    {
        return $this->hasMany(PetCareSession::class);
    }

    // ──────────────────────────────────────────────────────────────
    //  Virtual Age (Time Asymmetry: 1 program week = 1 virtual month)
    // ──────────────────────────────────────────────────────────────

    /**
     * Legacy profile (M5-R01 grandfathering, David 2026-10-05): a pet
     * created before M5-R01 or without a profile choice has no
     * `arrival_age_months`. It keeps exactly the pre-M5 rules (breed feed
     * windows + daily_steps_required, no parent-covered meals, no life
     * stage, no stage images) PERMANENTLY — also after its challenge ends.
     * New pets get a profile through the picker.
     */
    public function isLegacyProfile(): bool
    {
        return $this->arrival_age_months === null;
    }

    /**
     * Behaviour events (M5-R02: puppy accidents + take-out, chewing) apply
     * only to a profiled pet whose creating app build declared the
     * `behaviour_events` feature (PR #42 B1) — an old build has no UI to
     * resolve them. Fixed at creation; existing pets: off.
     */
    public function behaviourEventsEnabled(): bool
    {
        return (bool) $this->behaviour_events_enabled && ! $this->isLegacyProfile();
    }

    /**
     * Training (M5-R03, David 2026-10-06) applies only to a profiled pet whose
     * creating app builds (parent and child) declared the `training` feature.
     * Legacy-profile pets keep the pre-M5 rules: no training, no training
     * routine. Fixed at creation; existing pets: off.
     */
    public function trainingEnabled(): bool
    {
        // M5-R06-04: dog-only by species at runtime, not only by the stored flag
        // (CAT_SPEC Q10 — a cat has no training with commands in v1).
        return (bool) $this->training_enabled && ! $this->isLegacyProfile() && $this->isDog();
    }

    /** M5-R06: the dog rules (walk / steps, training, ball play) apply. */
    public function isDog(): bool
    {
        return $this->speciesValue() === Species::Dog;
    }

    /** M5-R06: the cat rules (wand play instead of steps, …) apply. */
    public function isCat(): bool
    {
        return $this->speciesValue() === Species::Cat;
    }

    /**
     * The dog's age in months (M5-R01): age at arrival (parent's choice) +
     * one month per week since birth (LifeStageService::ageMonthsAt). Null
     * for a legacy-profile pet (no age at arrival).
     */
    public function ageMonths(?CarbonInterface $at = null): ?int
    {
        return app(LifeStageService::class)->ageMonthsAt($this, $at ?? now());
    }

    /**
     * Months (= program weeks) since birth — the 12-week challenge clock
     * (certificate, `virtual_age_months` in the API). Since M5-R01 this is
     * not the dog's age any more: see ageMonths().
     * 1 program week (7 × 24 h of program time) = 1 virtual month; time in a
     * payment lock does not count (M3-11b, see programSecondsAt()).
     */
    public function virtualAgeInMonths(): int
    {
        if (! $this->born_at) {
            return 0;
        }

        return intdiv($this->programSecondsAt(now()), 7 * 86400);
    }

    // ──────────────────────────────────────────────────────────────
    //  Program clock (M3-11b, David 2026-10-07)
    // ──────────────────────────────────────────────────────────────
    //
    // Time the pet spends locked waiting for payment (`payment_lock` status
    // periods, lock reason `payment_required`) is not program time: the
    // challenge week does not advance and the dog does not age while locked;
    // after payment the clock resumes where it stopped. Hard stop, illness
    // and inactive periods still count. The `payment_lock` rows of
    // `pet_status_periods` (PetStatusPeriodService) are the source of truth,
    // so the pause before any instant is exact — an older date is never
    // shifted by a later lock. `born_at` itself is never changed.

    /**
     * Payment-lock spans of this pet as [start, end|null] UNIX timestamps,
     * clamped to the birth, oldest first. Loaded once per model instance
     * (or taken from an eager-loaded `paymentLockPeriods` relation); the Pet
     * hooks forget them after every save (a lock / payment writes a period).
     *
     * @var list<array{0: int, 1: int|null}>|null
     */
    private ?array $paymentLockSpans = null;

    /**
     * @return list<array{0: int, 1: int|null}>
     */
    public function paymentLockSpans(): array
    {
        if ($this->paymentLockSpans !== null) {
            return $this->paymentLockSpans;
        }
        // Free plan (never locked — unless it was an unpaid mutt challenge
        // converted by M5-F02, whose past locks still count), unborn or unsaved.
        if ($this->born_at === null || ! $this->exists
            || ($this->plan === PetPlan::Free && $this->converted_to_free_at === null)) {
            return [];
        }
        // An unpaid, unlocked challenge still inside its trial was provably never
        // locked: a lock only starts after the trial end (or at a refund after
        // it) and is only lifted by a payment. Not cached — it changes with time.
        if ($this->challenge_paid_at === null && $this->payment_locked_at === null
            && $this->trial_ends_at !== null && $this->trial_ends_at->isFuture()) {
            return [];
        }

        $periods = $this->relationLoaded('paymentLockPeriods')
            ? $this->paymentLockPeriods
            : $this->paymentLockPeriods()->get();

        $born = $this->born_at->getTimestamp();
        $spans = [];
        foreach ($periods->sortBy(fn (PetStatusPeriod $p) => $p->started_at->getTimestamp()) as $p) {
            $start = max($born, $p->started_at->getTimestamp());
            $end = $p->ended_at?->getTimestamp();
            if ($end !== null && $end <= $start) {
                continue;
            }
            $spans[] = [$start, $end];
        }

        return $this->paymentLockSpans = $spans;
    }

    /** Drop the memoised payment-lock spans (after a write that may add / close one). */
    public function forgetProgramPauses(): void
    {
        $this->paymentLockSpans = null;
        $this->unsetRelation('paymentLockPeriods');
    }

    /**
     * Seconds of payment lock in [$from, $to) — program time that did not run.
     */
    public function programSecondsPausedBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $a = $from->getTimestamp();
        $b = $to->getTimestamp();
        $paused = 0;
        foreach ($this->paymentLockSpans() as [$start, $end]) {
            $overlap = min($b, $end ?? PHP_INT_MAX) - max($a, $start);
            $paused += max(0, $overlap);
        }

        return $paused;
    }

    /**
     * Seconds of payment lock before $at (since birth).
     */
    public function programSecondsPausedBefore(CarbonInterface $at): int
    {
        if ($this->born_at === null) {
            return 0;
        }

        return $this->programSecondsPausedBetween($this->born_at, $at);
    }

    /**
     * The instant the program clock shows at $at: $at itself, or — when $at
     * lies inside a payment lock (open, or closed later) — the start of that
     * lock (the clock stands still while locked).
     */
    public function programInstantAt(CarbonInterface $at): CarbonImmutable
    {
        $t = $at->getTimestamp();
        foreach ($this->paymentLockSpans() as [$start, $end]) {
            if ($start <= $t && ($end === null || $t < $end)) {
                return CarbonImmutable::createFromTimestampUTC($start);
            }
        }

        return CarbonImmutable::instance($at)->utc();
    }

    /**
     * The effective birth for the program clock at $at: `born_at` shifted
     * forward by the payment-lock time before programInstantAt($at). Weeks /
     * dog age are counted from it up to programInstantAt($at). Null when
     * unborn. Equal to `born_at` for a pet that was never locked.
     *
     * DST: the shift is in absolute seconds, so when a DST change falls
     * between birth and the end of a pause, the weekly birthday's
     * family-local wall-clock hour moves by one hour from then on (the
     * 12-week clock in virtualAgeInMonths() counts absolute seconds and is
     * not affected).
     */
    public function programBirthAt(CarbonInterface $at): ?CarbonImmutable
    {
        if ($this->born_at === null) {
            return null;
        }

        $born = CarbonImmutable::instance($this->born_at)->utc();
        $paused = $this->programSecondsPausedBefore($this->programInstantAt($at));

        return $paused > 0 ? $born->addSeconds($paused) : $born;
    }

    /**
     * Program seconds elapsed since birth at $at (≥ 0): real time minus the
     * payment-lock time; constant while the pet is locked.
     */
    public function programSecondsAt(CarbonInterface $at): int
    {
        $birth = $this->programBirthAt($at);
        if ($birth === null) {
            return 0;
        }

        return max(0, $this->programInstantAt($at)->getTimestamp() - $birth->getTimestamp());
    }

    public function refresh()
    {
        $this->forgetProgramPauses();

        return parent::refresh();
    }

    /**
     * Get a human-readable virtual age label (e.g., "Age: 2 months").
     */
    public function virtualAgeLabel(): string
    {
        $months = $this->virtualAgeInMonths();

        return $months === 1 ? 'Age: 1 month' : "Age: {$months} months";
    }

    /**
     * Determine if the simulation has reached its end (12 program weeks = 12
     * virtual months; payment-lock time does not count — M3-11b).
     * A free-plan pet never completes (M3-11: no 12-week program, no certificate).
     */
    public function hasReachedSimulationEnd(): bool
    {
        return ! $this->isFreePlan() && $this->virtualAgeInMonths() >= 12;
    }

    // ──────────────────────────────────────────────────────────────
    //  Plan / trial / payment (M3-11, PAYMENTS_SPEC)
    // ──────────────────────────────────────────────────────────────

    /**
     * Length of the former free trial (M3-11 P2, removed by M3-13 — David
     * 2026-10-08). Only pets born before M3-13 still carry such a trial
     * (`trial_ends_at` after `born_at`); kept for those and for tests.
     */
    public const TRIAL_DAYS = 7;

    public function isFreePlan(): bool
    {
        return $this->plan === PetPlan::Free;
    }

    /**
     * End of a pre-M3-13 7-day trial for a birth at $birth: the same
     * family-local wall-clock time 7 days later (DST-safe), stored as UTC.
     * New births never get one (giveBirth: trial_ends_at = born_at).
     */
    public function trialEndFor(CarbonInterface $birth): CarbonInterface
    {
        return $birth->copy()->setTimezone($this->familyTimezone())->addDays(self::TRIAL_DAYS)->utc();
    }

    /**
     * Payment status of the pet — THE single derivation (M3-11, M3-13). Free
     * plan → null. Challenge: paid (credit, grandfathered or admin) › trial
     * (only a pre-M3-13 trial still running: now < `trial_ends_at`) ›
     * payment_required (also unborn: since M3-13 the challenge starts with a
     * purchase, so the parent can buy before the contract).
     *
     * Kill switch (`payments.enforced` false): nothing unpaid is ever
     * payment_required — the status stays `trial` (= "unpaid, playable").
     */
    public function challengeStatus(?CarbonInterface $now = null): ?ChallengeStatus
    {
        if ($this->plan !== PetPlan::Challenge) {
            return null;
        }
        if ($this->challenge_paid_at !== null) {
            return ChallengeStatus::Paid;
        }
        // A running pre-M3-13 trial keeps going until its end (no retroactive lock).
        if ($this->trial_ends_at !== null && $this->trial_ends_at->greaterThan($now ?? now())) {
            return ChallengeStatus::Trial;
        }
        // A born pet without `trial_ends_at` was written outside the model (raw
        // insert, old migration paths — no code path births one): never locked by
        // the tick, so it is not asked to pay either (pre-M3-13 behaviour kept).
        if ($this->trial_ends_at === null && ! $this->isUnborn()) {
            return ChallengeStatus::Trial;
        }

        // Kill switch (config/payments.php): until purchases are live nobody is
        // asked to pay — an unpaid challenge simply keeps playing.
        if (! self::paymentsEnforced()) {
            return ChallengeStatus::Trial;
        }

        return ChallengeStatus::PaymentRequired;
    }

    /**
     * Deleting this pet throws away a purchase (P5, David 2026-10-07): a
     * challenge paid by a store purchase (not grandfathered) that is neither
     * finished (12 weeks) nor ended by game over. The purchase stays used;
     * deletions ask for `acknowledge_paid_challenge`.
     */
    public function deletionLosesPurchase(): bool
    {
        return $this->plan === PetPlan::Challenge
            && $this->challenge_paid_source === ChallengePaidSource::Purchase
            && ! $this->is_game_over
            && ! $this->hasReachedSimulationEnd();
    }

    /**
     * The game-loop freeze of an unpaid challenge after its trial (set by the
     * tick, ChallengeService::processTrials; cleared on payment).
     */
    public static function paymentsEnforced(): bool
    {
        return (bool) config('payments.enforced', false);
    }

    public function isPaymentLocked(): bool
    {
        return $this->payment_locked_at !== null;
    }

    /**
     * Child actions wait for the parent: the lock is on, or the pet is born,
     * unpaid and payment_required but the tick has not locked it yet. An
     * unborn pet never waits here — the child must still be able to sign the
     * contract (M3-13: the lock starts at birth, PetActivityService::signContract).
     */
    public function awaitsPayment(): bool
    {
        return $this->isPaymentLocked()
            || (! $this->isUnborn() && $this->challengeStatus() === ChallengeStatus::PaymentRequired);
    }

    // ──────────────────────────────────────────────────────────────
    //  Game Loop Helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Determine if the pet is currently in illness lockout.
     */
    public function isIll(): bool
    {
        return $this->illness_until !== null && $this->illness_until->isFuture();
    }

    /**
     * Hard stop, payment lock (M3-11) or illness: metrics AND neglect clocks
     * (*_zero_since) are frozen, and no escalation runs (PRODUCT_SPEC §5,
     * M1-02). The payment lock reuses the hard-stop mechanism: `frozen_at`
     * on entry, applyThaw() on payment (pause time never counts).
     */
    public function isFrozen(): bool
    {
        return (bool) $this->is_hard_stopped || $this->isPaymentLocked() || $this->isIll();
    }

    /**
     * Contract before birth (David 2026-10-04, PRODUCT_SPEC §3, M1-07b): a
     * pet created at pairing has no `born_at` until the child signs the
     * contract. Until then the game loop ignores it (no decay, hygiene
     * events, daily-walk close, escalation) and child actions are locked
     * with `contract_required`. Pets created before M1-07b keep their
     * born_at (grandfathered as born, even without a contract row).
     */
    public function isUnborn(): bool
    {
        return $this->born_at === null;
    }

    /**
     * Query scope: pets the game loop ticks (born). Unborn pets are skipped
     * by the decay tick and escalation without being loaded.
     *
     * @param  Builder<Pet>  $query
     * @return Builder<Pet>
     */
    public function scopeBorn($query)
    {
        return $query->whereNotNull('born_at');
    }

    /**
     * Birth at the moment the contract is signed (attributes only; the
     * caller holds the row lock and saves). Metrics start at 100 %, every
     * clock starts now: decay (`last_decay_at`), the birth-day step grace
     * (`last_step_reset_at`), the hygiene schedule (from today, events
     * before birth never apply) and virtual age / certificate (`born_at`).
     */
    public function giveBirth(CarbonInterface $at): void
    {
        $this->forceFill([
            'born_at' => $at,
            'last_decay_at' => $at,
            'last_step_reset_at' => $at,
            'last_step_sync_at' => null,
            'daily_step_count' => 0,
            'hunger_level' => 100.0,
            'thirst_level' => 100.0,
            'energy_level' => 100.0,
            'hygiene_level' => 100.0,
            'hunger_zero_since' => null,
            'thirst_zero_since' => null,
            'energy_zero_since' => null,
            'hygiene_zero_since' => null,
            'escalation_level' => 0,
            'illness_until' => null,
            'walk_illness_due_at' => null,
            'hygiene_scheduled_through' => null,
            // M5-R02: the puppy's bladder clock starts at birth; chewing is
            // decided from the birth day on.
            'potty_clock_started_at' => $at,
            'behaviour_scheduled_through' => null,
            // M5-R03: "no practice → decay" is evaluated from the birth day on.
            'training_decayed_through' => null,
            // M5-R05: play invitations are decided from the birth day on.
            'play_scheduled_through' => null,
            'happy_until' => null,
            // M5-R06-04: no missed cat play day before the birth.
            'play_missed_on' => null,
            'frozen_at' => null,
            // M3-13 (David 2026-10-08): no free trial — a challenge's "trial" ends at
            // birth, so an unpaid challenge is payment_required from the contract on.
            'trial_ends_at' => $this->plan === PetPlan::Free ? null : $at,
        ]);
    }

    /**
     * Child actions (steps, clean, feed / water) are refused while the pet
     * is unborn, frozen (hard stop, illness), inactive or game over.
     */
    public function isActionLocked(): bool
    {
        return $this->actionLockReason() !== null;
    }

    /**
     * Why child actions are refused right now (HTTP 423, M1-07), or null.
     * Priority when several apply: game over › inactive › hard stop ›
     * payment required (M3-11) › contract required › illness (the parent's
     * pause wins over the contract screen and the vet screen; an unborn pet
     * can't be ill or game over, and is never payment-locked — the child
     * signs first, the lock starts at birth, M3-13).
     */
    public function actionLockReason(): ?PetLockReason
    {
        return match (true) {
            (bool) $this->is_game_over => PetLockReason::GameOver,
            ! $this->is_active => PetLockReason::Inactive,
            (bool) $this->is_hard_stopped => PetLockReason::HardStopped,
            $this->awaitsPayment() => PetLockReason::PaymentRequired,
            $this->isUnborn() => PetLockReason::ContractRequired,
            $this->isIll() => PetLockReason::Ill,
            default => null,
        };
    }

    /**
     * Lock reason for one child (M2-01). Like actionLockReason(), plus
     * `contract_required` when this caretaker has not signed their own
     * contract yet — on a shared pet the other children keep playing.
     * Same priority: game over › inactive › hard stop › contract › illness.
     * Without an actor this is the pet-level reason.
     */
    public function actionLockReasonFor(?User $actor): ?PetLockReason
    {
        if ($actor === null) {
            return $this->actionLockReason();
        }

        return match (true) {
            (bool) $this->is_game_over => PetLockReason::GameOver,
            ! $this->is_active => PetLockReason::Inactive,
            (bool) $this->is_hard_stopped => PetLockReason::HardStopped,
            $this->awaitsPayment() => PetLockReason::PaymentRequired,
            $this->isUnborn() || $this->caretakerNeedsContract($actor) => PetLockReason::ContractRequired,
            $this->isIll() => PetLockReason::Ill,
            default => null,
        };
    }

    /**
     * The family-local calendar date (Y-m-d) of an instant.
     */
    public function localDate(CarbonInterface $at): string
    {
        return $at->copy()->setTimezone($this->familyTimezone())->toDateString();
    }

    /**
     * Start of a new family-local day (M1-03, M1-04): once the local date has
     * changed since `last_step_reset_at`, the step count and with it the
     * energy go back to 0 ("reset na 0 % ob polnoči", PRODUCT_SPEC §5) and
     * the anti-cheat reference is cleared. Attributes only — the caller holds
     * the row lock and saves. Returns true if a reset happened.
     *
     * A pet that never had a reset (birth day) only gets the day stamped: it
     * keeps the energy it was born with until its first local midnight
     * (decision 2026-10-03, DECISIONS.md).
     */
    public function resetDailyStepsIfNewDay(CarbonInterface $now): bool
    {
        if ($this->last_step_reset_at === null) {
            $this->last_step_reset_at = $now;

            return false;
        }

        if ($this->localDate($this->last_step_reset_at) === $this->localDate($now)) {
            return false;
        }

        $this->daily_step_count = 0;
        $this->energy_level = 0.0;
        $this->last_step_reset_at = $now;
        $this->last_step_sync_at = null;

        return true;
    }

    /**
     * Columns tracking how long each metric has been at 0 %.
     */
    public const ZERO_SINCE_COLUMNS = ['hunger_zero_since', 'thirst_zero_since', 'energy_zero_since', 'hygiene_zero_since'];

    /**
     * End a freeze at $at (attributes only, caller saves): shift every
     * non-null *_zero_since forward by the frozen duration so neglect time
     * spent frozen doesn't count, and restart the decay clock at $at.
     */
    public function applyThaw(CarbonInterface $at): void
    {
        if ($this->frozen_at === null) {
            return;
        }

        $frozenSeconds = max(0, (int) $this->frozen_at->diffInSeconds($at, false));

        foreach (self::ZERO_SINCE_COLUMNS as $column) {
            $zeroSince = $this->getAttribute($column);
            if ($zeroSince !== null) {
                $shifted = $zeroSince->copy()->addSeconds($frozenSeconds);
                $this->setAttribute($column, $shifted->greaterThan($at) ? $at : $shifted);
            }
        }

        if ($this->last_decay_at === null || $this->last_decay_at->lessThan($at)) {
            $this->last_decay_at = $at;
        }

        $this->frozen_at = null;
    }

    /**
     * Apply what a freeze that ended without a model event left behind:
     * illness recovery (illness_until passed) and a stale `frozen_at`.
     * Writes quietly. Returns true if anything changed.
     */
    public function thawIfDue(?CarbonInterface $now = null): bool
    {
        $now ??= now();
        $changed = $this->recoverFromIllnessIfDue($now);

        if ($this->frozen_at !== null && ! $this->isFrozen()) {
            $this->applyThaw($now);
            $changed = true;
        }

        if ($changed) {
            $this->saveQuietly();
        }

        return $changed;
    }

    /**
     * Illness recovery = fresh start (David, 2026-10-03, PRODUCT_SPEC §7).
     * Once `illness_until` has passed, at that moment:
     *  - hygiene → 100 % (the vet cleaned the dog), hygiene clock cleared;
     *  - every other running neglect clock (*_zero_since: phase-3 alarm,
     *    illness, game over) restarts at the recovery moment;
     *  - escalation level back to 0 (new reminders for the new start);
     *  - hunger / thirst keep their values (the child can act again), energy
     *    stays step-based;
     *  - the decay clock resumes at the recovery moment.
     * A hard stop that is still on keeps the pet frozen from that moment.
     * Attributes only; the caller saves. Returns true if recovery applied.
     */
    public function recoverFromIllnessIfDue(CarbonInterface $now): bool
    {
        if ($this->illness_until === null || $this->illness_until->greaterThan($now)) {
            return false;
        }

        $at = $this->illness_until->copy();

        $this->hygiene_level = 100.0;
        $this->hygiene_zero_since = null;
        // M5-R02: the vet's clean dog closes every open mess (poop, accident,
        // chewing) at the recovery moment, so no event stays "active" on a
        // dog that shows 100 %. Their routines keep their outcome (resolved
        // after the 2 h deadline → missed, or excused by the illness).
        if ($this->exists) {
            PetHygieneEvent::query()
                ->where('pet_id', $this->id)
                ->where('status', HygieneEventStatus::Applied->value)
                // M5-R06-05: messes only — a cat's litter use is not a mess (its
                // scoop routine is excused by the illness, not done by the vet).
                ->whereIn('kind', HygieneEventKind::messes())
                ->whereNull('cleaned_at')
                ->where('scheduled_at', '<=', $at)
                ->update(['cleaned_at' => $at, 'updated_at' => now()]);
        }
        foreach (self::ZERO_SINCE_COLUMNS as $column) {
            if ($this->getAttribute($column) !== null) {
                $this->setAttribute($column, $at);
            }
        }
        $this->escalation_level = 0;
        $this->illness_until = null;

        if ($this->last_decay_at === null || $this->last_decay_at->lessThan($at)) {
            $this->last_decay_at = $at;
        }

        // Hard-stopped through the recovery (stored value; a hard stop being
        // switched on in this very write freezes from now via the hook): the
        // freeze continues from the recovery moment. Otherwise not frozen.
        // M3-11: the payment lock keeps the freeze going the same way.
        $this->frozen_at = ($this->getOriginal('is_hard_stopped') || $this->getOriginal('payment_locked_at') !== null) ? $at : null;

        return true;
    }

    /**
     * Determine if the pet is in game over / Virtual Shelter Protocol state.
     */
    public function isGameOver(): bool
    {
        return $this->is_game_over;
    }

    /**
     * The pet's species (M5-R06-01) — the column, or the breed's species when
     * the column was not selected (always equal: pets_species_breed_check).
     */
    public function speciesValue(): Species
    {
        $species = $this->getAttribute('species');

        return $species instanceof Species ? $species : $this->breed_type->species();
    }

    /**
     * Get the breed config for this pet.
     */
    public function breedConfig(): ?BreedConfig
    {
        return BreedConfig::forBreed($this->breed_type);
    }

    /**
     * The family's quiet hours (M2-01: one configuration per family, any
     * parent edits it). Never null: a family without a row (no parent to
     * own one, or a row lost to old code) gets QuietHours::DEFAULTS, so no
     * pet is ever without night quiet (fix/quiet-hours-default, 2026-10-08).
     * A parent who switched them off has a row with is_active = false.
     */
    public function quietHours(): QuietHours
    {
        $quietHours = QuietHours::where('family_id', $this->family_id)->first();
        if ($quietHours === null) {
            return QuietHours::defaultFor($this->family);
        }

        // QuietHours evaluates its windows in the family timezone; hand it
        // the family we already have.
        if ($this->family !== null) {
            $quietHours->setRelation('family', $this->family);
        }

        return $quietHours;
    }

    /**
     * The family timezone (families.timezone, M2-01), see User::familyTimezone().
     */
    public function familyTimezone(): string
    {
        return $this->family?->timezone
            ?? $this->user?->familyTimezone()
            ?? User::DEFAULT_TIMEZONE;
    }
}
