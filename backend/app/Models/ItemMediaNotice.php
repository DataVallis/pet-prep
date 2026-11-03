<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * M6-W03: the content of a pet's item became ready (or fell back) for one life stage — the
 * guard that sends the `item_media_ready` PetUpdated and the `item_ready` push exactly once
 * (ItemMediaService::settle). `outcome` content | fallback | no_media. No personal data.
 *
 * @property int $id
 * @property int $pet_id
 * @property string $item_key
 * @property string $design
 * @property string $life_stage
 * @property string $outcome
 * @property Carbon|null $created_at
 */
class ItemMediaNotice extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['pet_id', 'item_key', 'design', 'life_stage', 'outcome'];
}
