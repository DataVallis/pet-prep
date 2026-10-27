<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One treat a child gave (M6-W01): max 2 per pet and family-local day. Never
 * changes needs, Care Score or the certificate (S1).
 *
 * @property int $id
 * @property int $pet_id
 * @property int|null $child_user_id
 * @property int $pet_item_id
 * @property Carbon $given_at
 * @property string $local_date
 */
class PetTreatEvent extends Model
{
    protected $fillable = ['pet_id', 'child_user_id', 'pet_item_id', 'given_at', 'local_date'];

    protected function casts(): array
    {
        return ['given_at' => 'datetime'];
    }
}
