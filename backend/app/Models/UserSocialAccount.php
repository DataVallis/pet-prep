<?php

namespace App\Models;

use App\Enums\SocialProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Google / Apple identity linked to a parent (M2-10c).
 *
 * @property int $id
 * @property int $user_id
 * @property SocialProvider $provider
 * @property string $subject
 * @property string|null $email
 */
class UserSocialAccount extends Model
{
    protected $fillable = ['user_id', 'provider', 'subject', 'email'];

    protected function casts(): array
    {
        return [
            'provider' => SocialProvider::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
