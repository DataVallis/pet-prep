<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Who looked at / re-sent what in the admin shop (M6-S09, spec §3.7): »Show code«,
 * »Send code again«, »Change e-mail«. Never stores the code or the e-mail itself.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $action reveal_code | resend_email | change_email | cancel_email | upload_file
 * @property string $subject_type gift_voucher | shop_order | outbound_email | shop_product
 * @property int $subject_id
 * @property array<string, mixed>|null $meta
 * @property Carbon $created_at
 */
class ShopAuditLog extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['user_id', 'action', 'subject_type', 'subject_id', 'meta'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    public static function record(?User $user, string $action, string $subjectType, int $subjectId, array $meta = []): self
    {
        return self::create([
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
