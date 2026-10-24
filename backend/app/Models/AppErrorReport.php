<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Occurrences of one app error for one (app version, platform) (M5-05):
 * counts per role and the latest sample payload. Upserted by
 * App\Services\AppErrors\AppErrorReportService — never one row per occurrence.
 *
 * @property int $id
 * @property string $fingerprint
 * @property string $app_version
 * @property string $platform
 * @property int $occurrences
 * @property int $parent_count
 * @property int $child_count
 * @property int $anonymous_count
 * @property array<string, mixed> $sample
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 */
class AppErrorReport extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurrences' => 'integer',
            'parent_count' => 'integer',
            'child_count' => 'integer',
            'anonymous_count' => 'integer',
            'sample' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AppErrorFingerprint, $this>
     */
    public function errorFingerprint(): BelongsTo
    {
        return $this->belongsTo(AppErrorFingerprint::class, 'fingerprint', 'fingerprint');
    }
}
