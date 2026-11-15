<?php

namespace App\Models;

use Database\Factories\SummaryLeadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A parent's e-mail left on the petprep.si page »Koliko dela je pes« with
 * consent to PetPrep news (M6-S05). One row per e-mail. Written only by
 * SummaryLeadService; list + delete in /admin (superadmin); deleted
 * RETENTION_MONTHS after the last visit by `summary-leads:prune`.
 *
 * @property int $id
 * @property string $email lower-case
 * @property string $consent_version
 * @property Carbon $consented_at
 * @property string $source
 * @property string|null $locale
 * @property string|null $breed
 * @property string|null $stage
 * @property int $visits
 * @property Carbon $last_seen_at
 * @property Carbon $created_at
 */
class SummaryLead extends Model
{
    /** @use HasFactory<SummaryLeadFactory> */
    use HasFactory;

    /** Deleted this many months after the last visit (THREEJS_OPPORTUNITIES T9). */
    public const RETENTION_MONTHS = 24;

    /**
     * Versions of the consent sentence the page may show (text in
     * THREEJS_OPPORTUNITIES T9). A new wording gets a new version here.
     *
     * @var list<string>
     */
    public const CONSENT_VERSIONS = ['2026-11'];

    /** @var list<string> */
    public const SOURCES = ['real_cost'];

    /** @var list<string> */
    public const STAGES = ['adult', 'puppy'];

    /**
     * @var list<string>
     */
    protected $fillable = ['email', 'consent_version', 'consented_at', 'source', 'locale', 'breed', 'stage', 'visits', 'last_seen_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['consented_at' => 'datetime', 'last_seen_at' => 'datetime', 'visits' => 'integer'];
    }
}
