<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One distinct mobile app error (M5-05). Written only by
 * App\Services\AppErrors\AppErrorReportService (upsert); pruned after 30
 * days. Carries no user, family or pet — see the create migration.
 *
 * @property string $fingerprint
 * @property string $scope
 * @property string $error_name
 * @property string $message
 * @property string|null $first_frame
 * @property int $occurrences
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $last_alerted_at
 * @property int|null $window_count Occurrences in the admin's time window (select alias).
 */
class AppErrorFingerprint extends Model
{
    protected $primaryKey = 'fingerprint';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurrences' => 'integer',
            'window_count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_alerted_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<AppErrorReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(AppErrorReport::class, 'fingerprint', 'fingerprint');
    }
}
