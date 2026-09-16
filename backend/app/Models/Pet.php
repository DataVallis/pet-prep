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
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'breed_type',
        'pet_dna',
        'current_video_url',
        'hunger_level',
        'thirst_level',
        'energy_level',
        'hygiene_level',
        'daily_step_count',
        'last_step_reset_at',
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
