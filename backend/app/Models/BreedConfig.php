<?php

namespace App\Models;

use App\Enums\BreedType;
use Illuminate\Database\Eloquent\Model;

class BreedConfig extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'breed_slug',
        'daily_steps_required',
        'hunger_decay_rate',
        'premium_unlock',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'premium_unlock' => 'boolean',
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Lookup Helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Get the breed configuration by breed type enum.
     */
    public static function forBreed(BreedType $breed): ?self
    {
        return static::where('breed_slug', $breed->slug())->first();
    }
}
