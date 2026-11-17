<?php

namespace App\Models;

use Database\Factories\EmailContactFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One e-mail address left on petprep.si (M6-S09, David 2026-11-17: all e-mails in ONE segmented
 * store). Unique, lower-case. Written only by EmailSignupService; list / detail / CSV / delete in
 * /admin (superadmin). Never deleted automatically (David 2026-11-15): a contact — and its
 * signups, ON DELETE CASCADE — goes only when the person asks.
 *
 * `provider_synced_at` is the marker of the PLANNED job that pushes contacts to Klaviyo (or another
 * provider); nothing writes it yet. A contact is "changed since the last push" when `last_seen_at`
 * is later than it.
 *
 * @property int $id
 * @property string $email lower-case
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property int $signups_count
 * @property string $consent_version
 * @property Carbon $consented_at
 * @property Carbon|null $unsubscribed_at
 * @property Carbon|null $provider_synced_at
 * @property Carbon $created_at
 * @property-read Collection<int, EmailSignup> $signups
 */
class EmailContact extends Model
{
    /** @use HasFactory<EmailContactFactory> */
    use HasFactory;

    /**
     * Versions of the consent sentence the website may show (text in THREEJS_OPPORTUNITIES T9),
     * OLDEST FIRST. A new wording is appended here; a stored consent is only ever replaced by a
     * later entry.
     *
     * @var list<string>
     */
    public const CONSENT_VERSIONS = ['2026-11'];

    /** @var list<string> */
    protected $fillable = [
        'email', 'first_seen_at', 'last_seen_at', 'signups_count', 'consent_version', 'consented_at',
        'unsubscribed_at', 'provider_synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'consented_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
            'provider_synced_at' => 'datetime',
            'signups_count' => 'integer',
        ];
    }

    /**
     * Oldest first, so `->last()` of a loaded relation is the latest signup.
     *
     * @return HasMany<EmailSignup, $this>
     */
    public function signups(): HasMany
    {
        return $this->hasMany(EmailSignup::class)->orderBy('id');
    }

    /** The latest signup of a contact whose `signups` are loaded (null without any). */
    public function latestSignup(): ?EmailSignup
    {
        return $this->signups->last();
    }
}
