<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user read a breed fact (M5-R09b): the child's collection »8 od 40« and
 * the parent's »Kar bere otrok« view. One row per (user, fact); cascades with
 * the user and the fact. No analytics — only this flag (spec §6).
 *
 * @property int $id
 * @property int $user_id
 * @property int $breed_fact_id
 * @property Carbon $read_at
 */
class BreedFactRead extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'breed_fact_id', 'read_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function fact(): BelongsTo
    {
        return $this->belongsTo(BreedFact::class, 'breed_fact_id');
    }
}
