<?php

namespace App\Models;

use App\Enums\BreedType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One look of a free breed's shared appearance pool (M4-10, David
 * 2026-10-09): a DNA v2 trait combination plus its media (`pet_media` rows
 * with `pet_look_id`, one per kind / state / life stage). Every pool pet of
 * the look copies `dna` into `pets.pet_dna` and shows the look's files.
 * Contains no personal data (breed + traits only).
 *
 * @property int $id
 * @property BreedType $breed_type
 * @property int $pool_index
 * @property string $trait_fingerprint
 * @property array<string, mixed> $dna
 */
class PetLook extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'breed_type',
        'pool_index',
        'trait_fingerprint',
        'dna',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'breed_type' => BreedType::class,
            'pool_index' => 'integer',
            'dna' => 'array',
        ];
    }

    /** The look's media rows (all stages). */
    public function media(): HasMany
    {
        return $this->hasMany(PetMedia::class, 'pet_look_id');
    }

    /** Pets that show this look. */
    public function pets(): HasMany
    {
        return $this->hasMany(Pet::class, 'pet_look_id');
    }

    /**
     * @return array<string, string>
     */
    public function traits(): array
    {
        return is_array($this->dna['traits'] ?? null) ? $this->dna['traits'] : [];
    }

    public function seed(): int
    {
        return (int) ($this->dna['seed'] ?? 0);
    }
}
