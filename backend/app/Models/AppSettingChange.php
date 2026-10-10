<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit row of one product-switch change (M5-R06-09): who
 * (superadmin), when, old → new value.
 */
class AppSettingChange extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = ['key', 'user_id', 'old', 'new'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['old' => 'array', 'new' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
