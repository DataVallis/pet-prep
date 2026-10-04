<?php

namespace App\Models;

use App\Enums\FamilyRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Membership row (family_user). A user belongs to at most one family.
 *
 * @property int $family_id
 * @property int $user_id
 * @property FamilyRole $role
 */
class FamilyMember extends Model
{
    protected $table = 'family_user';

    /**
     * @var list<string>
     */
    protected $fillable = ['family_id', 'user_id', 'role'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['role' => FamilyRole::class];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
