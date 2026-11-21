<?php

namespace App\Models;

use App\Enums\Phase2PriceChoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One parent's answer to the Phase-2 fake door »Dobili smo pravo žival« (H7, VALIDATION_PLAN §4.4,
 * David 2026-11-21). One row per parent, written only by Phase2InterestService. `email_contact_id`
 * = the contact in the unified e-mail store that holds the »Obvestite me« address (source
 * `app_phase2`); null while `notify` is false. Exported with the parent; deleted with the account
 * (the `app_phase2` signups of its contact too).
 *
 * @property int $id
 * @property int $user_id
 * @property int $family_id
 * @property Carbon|null $first_viewed_at
 * @property Carbon|null $last_viewed_at
 * @property Phase2PriceChoice|null $price_choice
 * @property bool $notify
 * @property Carbon|null $submitted_at
 * @property int|null $email_contact_id
 * @property-read EmailContact|null $emailContact
 */
class Phase2Interest extends Model
{
    /** @var array<string, mixed> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_viewed_at' => 'datetime',
            'last_viewed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'notify' => 'boolean',
            'price_choice' => Phase2PriceChoice::class,
        ];
    }

    /**
     * @return BelongsTo<EmailContact, $this>
     */
    public function emailContact(): BelongsTo
    {
        return $this->belongsTo(EmailContact::class);
    }
}
