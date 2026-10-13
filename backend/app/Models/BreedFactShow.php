<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One automatic »Ali si vedel?« sheet shown to a child (M5-R09b, spec §3.2):
 * at most 2 per program week, ≥ 2 family-local days apart, across all the
 * child's devices. One row per (child, pet, family-local date).
 *
 * @property int $id
 * @property int $user_id
 * @property int $pet_id
 * @property int|null $breed_fact_id
 * @property int $program_week 1 = the program week from birth
 * @property string $local_date
 * @property Carbon $shown_at
 */
class BreedFactShow extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'pet_id', 'breed_fact_id', 'program_week', 'local_date', 'shown_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['program_week' => 'integer', 'shown_at' => 'datetime'];
    }

    /** Family-local date (Y-m-d), whatever the DB driver returns. */
    public function localDate(): string
    {
        return substr((string) $this->getRawOriginal('local_date', $this->local_date), 0, 10);
    }
}
