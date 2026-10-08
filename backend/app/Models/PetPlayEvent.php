<?php

namespace App\Models;

use App\Enums\PlayKind;
use App\Enums\PlaySource;
use App\Enums\PlayStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One play invitation or completed play / cuddle (M5-R05, PlayService).
 * Mood and video only: no routine, score, metric or illness reads this
 * table (PLAY_CUDDLE_SPEC §12.6). Deleted with the pet; `completed_by` is
 * nulled when the child profile is deleted.
 *
 * @property int $id
 * @property int $pet_id
 * @property PlayKind $kind
 * @property PlaySource $source
 * @property string $local_date Family-local day (of the invitation / of the play)
 * @property Carbon|null $scheduled_at Invitations only
 * @property Carbon|null $expires_at Invitations only: scheduled_at + open time, cut at the next quiet hours
 * @property PlayStatus $status
 * @property int|null $completed_by The child
 * @property Carbon|null $completed_at
 */
class PetPlayEvent extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'pet_id', 'kind', 'source', 'local_date', 'scheduled_at', 'expires_at', 'status', 'completed_by', 'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PlayKind::class,
            'source' => PlaySource::class,
            'status' => PlayStatus::class,
            'scheduled_at' => 'datetime',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Pet, $this>
     */
    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }
}
