<?php

namespace App\Models;

use App\Enums\BreedType;
use App\Enums\PetStateEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        static::creating(function (Pet $pet): void {
            $pet->last_decay_at ??= now();
        });

        // Leaving a frozen state (hard stop lifted, session re-activated,
        // game over undone) restarts the decay clock, so the frozen period is
        // never applied as a catch-up burst (M1-02). Ticks also advance the
        // clock while frozen; this covers pets the scheduler doesn't load.
        static::updating(function (Pet $pet): void {
            $unfrozen = ($pet->isDirty('is_hard_stopped') && ! $pet->is_hard_stopped)
                || ($pet->isDirty('is_active') && $pet->is_active)
                || ($pet->isDirty('is_game_over') && ! $pet->is_game_over);

            if ($unfrozen && ! $pet->isDirty('last_decay_at')) {
                $pet->last_decay_at = now();
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
        'last_decay_at',
        'born_at',
        'is_active',
        'pet_state',
        'illness_until',
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
            'last_step_reset_at' => 'datetime',
            'last_decay_at' => 'datetime',
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
     * The child user who owns this pet.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The activity log entries for this pet.
     */
    public function activities(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
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
     * Get the quiet hours configuration for this pet's parent.
     */
    public function quietHours(): ?QuietHours
    {
        $parent = $this->user?->parent;

        return $parent?->quietHours;
    }
}
