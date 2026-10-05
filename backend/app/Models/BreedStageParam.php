<?php

namespace App\Models;

use App\Enums\StageParamKey;
use App\Services\LifeStageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

/**
 * One sourced life-stage value of a breed (M5-R01, `breed_stage_params`).
 *
 * Provenance per value: `source_id` (docs/research/dog-data/sources.md),
 * `confidence`, `verified` (false = UNSOURCED proposal — shown as such in
 * Filament, never presented to parents / children as fact). Read through
 * LifeStageService (cached per breed); every create / update / delete made
 * through Eloquent (Filament) is audited in `breed_stage_param_changes`
 * with the acting admin and the old / new values. The insert-only seeder
 * writes with the query builder and creates no audit rows.
 *
 * @property int $id
 * @property string $breed_slug
 * @property string $stage puppy|young|adult|senior|all
 * @property int $age_from_months
 * @property string $key
 * @property mixed $value
 * @property string|null $unit
 * @property string|null $source_id
 * @property string $confidence
 * @property bool $verified
 * @property string|null $quote
 * @property string|null $notes
 * @property int|null $updated_by
 */
class BreedStageParam extends Model
{
    public const STAGE_ALL = 'all';

    /** Fields whose change is audited. */
    public const AUDITED = ['value', 'unit', 'source_id', 'confidence', 'verified', 'quote', 'notes', 'stage', 'age_from_months', 'key'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'breed_slug', 'stage', 'age_from_months', 'key', 'value', 'unit',
        'source_id', 'confidence', 'verified', 'quote', 'notes', 'data_ref', 'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
            'verified' => 'boolean',
            'age_from_months' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (BreedStageParam $param): void {
            if (Auth::id() !== null) {
                $param->updated_by = Auth::id();
            }
        });

        static::created(fn (BreedStageParam $p) => $p->audit('created', null, $p->only(self::AUDITED)));

        static::updated(function (BreedStageParam $p): void {
            $changed = array_values(array_intersect(array_keys($p->getChanges()), self::AUDITED));
            if ($changed === []) {
                return;
            }

            $old = [];
            foreach ($changed as $field) {
                $old[$field] = $field === 'value' ? json_decode((string) $p->getRawOriginal('value'), true) : $p->getOriginal($field);
            }

            $p->audit('updated', $old, $p->only($changed));
        });

        static::deleted(fn (BreedStageParam $p) => $p->audit('deleted', $p->only(self::AUDITED), null));

        // Rules read the params through a per-breed cache.
        static::saved(fn (BreedStageParam $p) => LifeStageService::forgetBreed($p->breed_slug));
        static::deleted(fn (BreedStageParam $p) => LifeStageService::forgetBreed($p->breed_slug));
    }

    public function keyEnum(): ?StageParamKey
    {
        return StageParamKey::tryFrom($this->key);
    }

    public function breedConfig(): BelongsTo
    {
        return $this->belongsTo(BreedConfig::class, 'breed_slug', 'breed_slug');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function auditTrail(): HasMany
    {
        return $this->hasMany(BreedStageParamChange::class)->latest('id');
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function audit(string $action, ?array $old, ?array $new): void
    {
        BreedStageParamChange::create([
            'breed_stage_param_id' => $action === 'deleted' ? null : $this->id,
            'breed_slug' => $this->breed_slug,
            'stage' => $this->stage,
            'age_from_months' => (int) $this->age_from_months,
            'key' => $this->key,
            'action' => $action,
            'user_id' => Auth::id(),
            'old' => $old,
            'new' => $new,
        ]);
    }
}
