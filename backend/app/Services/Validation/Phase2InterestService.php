<?php

namespace App\Services\Validation;

use App\Enums\Phase2PriceChoice;
use App\Models\EmailContact;
use App\Models\EmailSignup;
use App\Models\Phase2Interest;
use App\Models\User;
use App\Services\EmailSignupService;
use App\Services\FamilyService;
use App\Support\WebsiteLocale;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Phase-2 fake door »Dobili smo pravo žival« (H7, VALIDATION_PLAN §4.4, contract
 * docs/engineering/VALIDATION_API.md §3, David 2026-11-21). Nothing is sold or promised: the
 * screen says »v pripravi«; the answers only measure demand. The only writer of `phase2_interests`
 * (one row per parent).
 *
 * The »Obvestite me« e-mail goes into the unified e-mail store (EmailSignupService, source
 * SIGNUP_SOURCE, purpose `early_access`, consent CONSENT_VERSION = the app checkbox) — never a
 * parallel store. The row links THIS parent's own signup (`email_signup_id`), so a withdrawal
 * (`notify: false`), the account deletion and the game-data reset remove exactly that signup: the
 * contact's counter is recounted and the contact goes only when no signup is left on it. Another
 * parent's or a website signup of the same address stays.
 */
class Phase2InterestService
{
    public const SIGNUP_SOURCE = 'app_phase2';

    public const SIGNUP_PURPOSE = 'early_access';

    /**
     * The app checkbox »Obvestite me, ko bo na voljo«. Deliberately NOT in
     * EmailContact::CONSENT_VERSIONS (the website may not send it): it ranks lowest, so it never
     * replaces a contact's website consent, and a later website consent replaces it.
     */
    public const CONSENT_VERSION = 'app-p2-2026-11';

    public function __construct(
        private readonly FamilyService $families,
        private readonly EmailSignupService $emails,
    ) {}

    /** POST /api/parent/phase2-interest/viewed: the first tap is kept, later taps move `last_viewed_at`. */
    public function viewed(User $parent, ?CarbonImmutable $now = null): Phase2Interest
    {
        $now = ($now ?? CarbonImmutable::now())->utc();

        return $this->withLockedRow($parent, function (Phase2Interest $row) use ($now): Phase2Interest {
            $row->first_viewed_at ??= $now;
            $row->last_viewed_at = $now;
            $row->save();

            return $row;
        });
    }

    /** May this parent send `{notify: false}` without a price (a withdrawal of an earlier answer)? */
    public function hasAnswer(User $parent): bool
    {
        return Phase2Interest::where('user_id', $parent->id)->whereNotNull('price_choice')->exists();
    }

    /**
     * POST /api/parent/phase2-interest: upserts the parent's answer. `notify` true needs an e-mail
     * (the request refuses it without one — 422 `email_required`). `$price` null = keep the stored
     * price (only a withdrawal `{notify: false}` of an existing answer may omit it — the request
     * enforces that).
     */
    public function submit(User $parent, ?Phase2PriceChoice $price, bool $notify, ?string $email, ?CarbonImmutable $now = null): Phase2Interest
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $email = $email !== null ? strtolower(trim($email)) : null;

        return $this->withLockedRow($parent, function (Phase2Interest $row) use ($price, $notify, $email, $now): Phase2Interest {
            $current = $row->email_signup_id !== null ? EmailSignup::with('contact')->find($row->email_signup_id) : null;

            if ($notify && $email !== null && $email !== '') {
                if ($current === null || $current->contact?->email !== $email) {
                    $this->forgetSignup($row->email_signup_id);
                    $signup = $this->emails->recordSignup([
                        'email' => $email,
                        'consent_version' => self::CONSENT_VERSION,
                        'purpose' => self::SIGNUP_PURPOSE,
                        'source' => self::SIGNUP_SOURCE,
                        'locale' => in_array(app()->getLocale(), WebsiteLocale::ALL, true) ? app()->getLocale() : null,
                    ]);
                    $row->email_signup_id = $signup->id;
                }
            } else {
                $this->forgetSignup($row->email_signup_id);
                $row->email_signup_id = null;
                $notify = false;
            }

            $row->first_viewed_at ??= $now;
            $row->last_viewed_at ??= $now;
            if ($price !== null) {
                $row->price_choice = $price;
            }
            $row->notify = $notify;
            $row->submitted_at = $now;
            $row->save();

            return $row;
        });
    }

    /**
     * GET /api/parent/dashboard `phase2_interest`: has this parent sent the form, with a live
     * »Obvestite me« consent (notifies()), and which price. Read only.
     *
     * @return array{submitted: bool, notify: bool, price_choice: 'free'|'monthly_2_99'|'monthly_5_99'|null}
     */
    public function dashboardFor(User $parent): array
    {
        $row = Phase2Interest::with('emailSignup')->where('user_id', $parent->id)->whereNotNull('submitted_at')->first();

        return [
            'submitted' => $row !== null,
            'notify' => $row !== null && $row->notifies(),
            'price_choice' => $row?->price_choice?->value,
        ];
    }

    /**
     * Account deletion / game-data reset (inside their transaction, before the user rows go): each
     * of these parents' OWN `app_phase2` signup. The rows themselves go with the users (FK cascade)
     * or the reset.
     *
     * @param  list<int>  $userIds
     */
    public function forgetEmailsOf(array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        Phase2Interest::whereIn('user_id', $userIds)->whereNotNull('email_signup_id')
            ->orderBy('id')->pluck('email_signup_id')
            ->each(fn ($id) => $this->forgetSignup((int) $id));
    }

    /**
     * The export's `phase2_interest` of one parent (null without a row).
     *
     * @return array{first_viewed_at: string|null, last_viewed_at: string|null, price_choice: string|null, notify: bool, notify_email: string|null, submitted_at: string|null}|null
     */
    public function exportFor(User $parent): ?array
    {
        $row = Phase2Interest::with('emailSignup.contact')->where('user_id', $parent->id)->first();
        if ($row === null) {
            return null;
        }
        $notifies = $row->notifies();

        return [
            'first_viewed_at' => $row->first_viewed_at?->utc()->toIso8601String(),
            'last_viewed_at' => $row->last_viewed_at?->utc()->toIso8601String(),
            'price_choice' => $row->price_choice?->value,
            'notify' => $notifies,
            'notify_email' => $notifies ? $row->emailSignup?->contact?->email : null,
            'submitted_at' => $row->submitted_at?->utc()->toIso8601String(),
        ];
    }

    /**
     * Removes ONE signup row; its contact's `signups_count` follows the remaining signups, and the
     * contact goes only when none is left. A missing row (admin deleted the contact) is a no-op.
     */
    private function forgetSignup(?int $signupId): void
    {
        if ($signupId === null) {
            return;
        }
        $contactId = EmailSignup::whereKey($signupId)->value('email_contact_id');
        if ($contactId === null) {
            return;
        }

        $contact = EmailContact::whereKey($contactId)->lockForUpdate()->first();
        if ($contact === null) {
            return;
        }

        EmailSignup::whereKey($signupId)->delete();
        $left = EmailSignup::where('email_contact_id', $contact->id)->count();
        if ($left === 0) {
            $contact->delete();
        } else {
            $contact->update(['signups_count' => $left]);
        }
    }

    /**
     * @template T
     *
     * @param  callable(Phase2Interest): T  $change
     * @return T
     */
    private function withLockedRow(User $parent, callable $change): mixed
    {
        $family = $this->families->ensureFamilyFor($parent);

        return DB::transaction(function () use ($parent, $family, $change) {
            try {
                // Savepoint: a lost race on the unique user_id must not abort the outer transaction.
                DB::transaction(fn () => Phase2Interest::firstOrCreate(['user_id' => $parent->id], ['family_id' => $family->id]));
            } catch (UniqueConstraintViolationException) {
                // A simultaneous first call created the row; it is locked below.
            }
            $row = Phase2Interest::where('user_id', $parent->id)->lockForUpdate()->firstOrFail();
            if ($row->family_id !== $family->id) {
                $row->family_id = $family->id;
            }

            return $change($row);
        });
    }
}
