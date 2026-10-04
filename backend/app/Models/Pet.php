<?php

namespace App\Models;

use App\Enums\BreedType;
use App\Enums\PetLockReason;
use App\Enums\PetStateEnum;
use App\Services\FamilyService;
use App\Services\PetStatusPeriodService;
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

            if ($pet->isUnborn()) {
                return;
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
        });

        // History of hard stops, illnesses and inactive periods for the
        // routine ledger (M2-06). Runs inside the writer's transaction.
        static::updated(function (Pet $pet): void {
            app(PetStatusPeriodService::class)->recordUpdated($pet);
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
                || ($pet->isDirty('is_hard_stopped') && ! $pet->is_hard_stopped);

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
        'pet_dna',
        'current_video_url',
        'media_status',
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
    ];

    /**
     * Routine-ledger bookkeeping (M2-06) never leaves the server.
     *
     * @var list<string>
     */
    protected $hidden = [
        'routines_closed_through',
        'routines_next_close_at',
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
            'pet_state' => PetStateEnum::class,
            'pet_dna' => 'array',
            'born_at' => 'datetime',
            'illness_until' => 'datetime',
            'walk_illness_due_at' => 'datetime',
            'last_step_reset_at' => 'datetime',
            'last_step_sync_at' => 'datetime',
            'last_decay_at' => 'datetime',
            'frozen_at' => 'datetime',
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
            ->withTimestamps()
            ->orderBy('pet_caretakers.id');
    }

    public function caretakerRows(): HasMany
    {
        return $this->hasMany(PetCaretaker::class)->orderBy('id');
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
     * Asynchronous fal.ai generation requests for this pet.
     */
    public function mediaJobs(): HasMany
    {
        return $this->hasMany(PetMediaJob::class);
    }

    // ──────────────────────────────────────────────────────────────
    //  Virtual Age (Time Asymmetry: 1 real week = 1 virtual month)
    // ──────────────────────────────────────────────────────────────

    /**
     * Calculate the virtual age in months.
     * 1 real week = 1 virtual month.
     */
    public function virtualAgeInMonths(): int
    {
        if (! $this->born_at) {
            return 0;
        }

        return (int) floor($this->born_at->diffInWeeks(now()));
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
     * Determine if the simulation has reached its end (12 real weeks = 12 virtual months).
     */
    public function hasReachedSimulationEnd(): bool
    {
        return $this->virtualAgeInMonths() >= 12;
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
     * Hard stop or illness: metrics AND neglect clocks (*_zero_since) are
     * frozen, and no escalation runs (PRODUCT_SPEC §5, M1-02).
     */
    public function isFrozen(): bool
    {
        return (bool) $this->is_hard_stopped || $this->isIll();
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
            'frozen_at' => null,
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
     * contract required › illness (the parent's pause wins over the contract
     * screen and the vet screen; an unborn pet can't be ill or game over).
     */
    public function actionLockReason(): ?PetLockReason
    {
        return match (true) {
            (bool) $this->is_game_over => PetLockReason::GameOver,
            ! $this->is_active => PetLockReason::Inactive,
            (bool) $this->is_hard_stopped => PetLockReason::HardStopped,
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
        $this->frozen_at = $this->getOriginal('is_hard_stopped') ? $at : null;

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
     * Get the breed config for this pet.
     */
    public function breedConfig(): ?BreedConfig
    {
        return BreedConfig::forBreed($this->breed_type);
    }

    /**
     * The family's quiet hours (M2-01: one configuration per family, any
     * parent edits it).
     */
    public function quietHours(): ?QuietHours
    {
        $quietHours = QuietHours::where('family_id', $this->family_id)->first();

        // QuietHours evaluates its windows in the family timezone; hand it
        // the family we already have.
        if ($quietHours !== null && $this->family !== null) {
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
