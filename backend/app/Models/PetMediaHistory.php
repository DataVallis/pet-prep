<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A reference image of an earlier life stage (M5-R01). When the pet moves to
 * a new stage the current image is archived here before the new one is
 * generated; the file stays on the pet-media disk (deleted with the pet).
 * The growth album (M5-R04, PetGrowthService) reads these rows.
 *
 * @property int $id
 * @property int $pet_id
 * @property string $kind
 * @property string|null $life_stage
 * @property int $generation
 * @property string $storage_path
 * @property string|null $mime
 * @property Carbon|null $archived_at
 * @property Carbon|null $taken_at when the image was stored (null: archived before M5-R04)
 */
class PetMediaHistory extends Model
{
    protected $table = 'pet_media_history';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['pet_id', 'kind', 'life_stage', 'generation', 'storage_path', 'bytes', 'mime', 'archived_at', 'taken_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['archived_at' => 'datetime', 'taken_at' => 'datetime', 'generation' => 'integer'];
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }
}
