<?php

namespace App\Models;

use App\Enums\Phase2PriceChoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One parent's answer to the Phase-2 fake door »Dobili smo pravo žival« (H7, VALIDATION_PLAN §4.4,
 * David 2026-11-21). One row per parent, written only by Phase2InterestService. `email_signup_id`
 * = this parent's own signup row in the unified e-mail store (source `app_phase2`) holding the
 * »Obvestite me« address; null while `notify` is false. The consent counts only while that row
 * still exists (notifies()). Exported with the parent; that signup goes with a withdrawal, the
 * account and the game-data reset.
 *
 * @property int $id
 * @property int $user_id
 * @property int $family_id
 * @property Carbon|null $first_viewed_at
 * @property Carbon|null $last_viewed_at
 * @property Phase2PriceChoice|null $price_choice
 * @property bool $notify
 * @property Carbon|null $submitted_at
 * @property int|null $email_signup_id
 * @property-read EmailSignup|null $emailSignup
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
     * @return BelongsTo<EmailSignup, $this>
     */
    public function emailSignup(): BelongsTo
    {
        return $this->belongsTo(EmailSignup::class);
    }

    /**
     * »Obvestite me« as exposed everywhere (dashboard, export, funnel H7): ticked AND the linked
     * signup still exists (an admin may have deleted the contact on request).
     */
    public function notifies(): bool
    {
        return $this->notify && $this->email_signup_id !== null && $this->emailSignup !== null;
    }
}
