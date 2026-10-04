<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Second-parent invite code (M2-01): 8 characters, valid 24 h, single use.
 *
 * @property int $family_id
 * @property string $code
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 */
class FamilyInvite extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['family_id', 'created_by', 'code', 'expires_at', 'used_at', 'used_by'];

    /**
     * @var list<string>
     */
    protected $hidden = ['code'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }
}
