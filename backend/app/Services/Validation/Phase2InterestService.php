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
 * parallel store. The row links the contact (`email_contact_id`) so the GDPR export shows the
 * address and unticking »Obvestite me« / deleting the account removes the `app_phase2` signups
 * (and the contact when nothing else is left on it). Website signups of the same address stay.
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

    /**
     * POST /api/parent/phase2-interest: upserts the parent's answer. `notify` true needs an e-mail
     * (the request refuses it without one — 422 `email_required`).
     */
    public function submit(User $parent, Phase2PriceChoice $price, bool $notify, ?string $email, ?CarbonImmutable $now = null): Phase2Interest
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $email = $email !== null ? strtolower(trim($email)) : null;

        return $this->withLockedRow($parent, function (Phase2Interest $row) use ($price, $notify, $email, $now): Phase2Interest {
            $linked = $row->email_contact_id !== null ? EmailContact::find($row->email_contact_id) : null;

            if ($notify && $email !== null && $email !== '') {
                if ($linked === null || $linked->email !== $email) {
                    if ($linked !== null) {
                        $this->forgetContact($linked->id);
                    }
                    $contact = $this->emails->record([
                        'email' => $email,
                        'consent_version' => self::CONSENT_VERSION,
                        'purpose' => self::SIGNUP_PURPOSE,
                        'source' => self::SIGNUP_SOURCE,
                        'locale' => in_array(app()->getLocale(), WebsiteLocale::ALL, true) ? app()->getLocale() : null,
                    ]);
                    $row->email_contact_id = $contact->id;
                }
            } else {
                if ($linked !== null) {
                    $this->forgetContact($linked->id);
                }
                $row->email_contact_id = null;
                $notify = false;
            }

            $row->first_viewed_at ??= $now;
            $row->last_viewed_at ??= $now;
            $row->price_choice = $price;
            $row->notify = $notify;
            $row->submitted_at = $now;
            $row->save();

            return $row;
        });
    }

    /**
     * GET /api/parent/dashboard `phase2_interest`: has this parent sent the form, and with
     * »Obvestite me« (false while not submitted). Read only.
     *
     * @return array{submitted: bool, notify: bool}
     */
    public function dashboardFor(User $parent): array
    {
        $row = Phase2Interest::where('user_id', $parent->id)->whereNotNull('submitted_at')->first(['notify']);

        return ['submitted' => $row !== null, 'notify' => $row !== null && $row->notify];
    }

    /**
     * Account deletion (inside its transaction, before the user rows go): the `app_phase2` signups
     * of these parents' linked contacts. The rows themselves go with the users (FK cascade).
     *
     * @param  list<int>  $userIds
     */
    public function forgetEmailsOf(array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $contactIds = Phase2Interest::whereIn('user_id', $userIds)->whereNotNull('email_contact_id')
            ->pluck('email_contact_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        foreach ($contactIds as $contactId) {
            $this->forgetContact($contactId);
        }
    }

    /**
     * The export's `phase2_interest` of one parent (null without a row).
     *
     * @return array{first_viewed_at: string|null, last_viewed_at: string|null, price_choice: string|null, notify: bool, notify_email: string|null, submitted_at: string|null}|null
     */
    public function exportFor(User $parent): ?array
    {
        $row = Phase2Interest::with('emailContact')->where('user_id', $parent->id)->first();
        if ($row === null) {
            return null;
        }

        return [
            'first_viewed_at' => $row->first_viewed_at?->utc()->toIso8601String(),
            'last_viewed_at' => $row->last_viewed_at?->utc()->toIso8601String(),
            'price_choice' => $row->price_choice?->value,
            'notify' => $row->notify,
            'notify_email' => $row->emailContact?->email,
            'submitted_at' => $row->submitted_at?->utc()->toIso8601String(),
        ];
    }

    /**
     * Removes the `app_phase2` signups of a contact; the contact goes too when no other signup is
     * left on it, otherwise its counter follows the remaining signups.
     */
    private function forgetContact(int $contactId): void
    {
        $contact = EmailContact::whereKey($contactId)->lockForUpdate()->first();
        if ($contact === null) {
            return;
        }

        EmailSignup::where('email_contact_id', $contact->id)->where('source', self::SIGNUP_SOURCE)->delete();
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
