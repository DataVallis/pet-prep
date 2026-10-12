<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\CertificateOutcome;
use App\Enums\CertificateReason;
use App\Enums\PetPlan;
use App\Enums\PetStatusPeriodKind;
use App\Exceptions\CertificateException;
use App\Models\ActivityLog;
use App\Models\ChallengeCertificate;
use App\Models\Pet;
use App\Models\PetCaretaker;
use App\Models\PetContract;
use App\Models\PetStatusPeriod;
use App\Models\User;
use App\Services\Media\PetMediaService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Responsibility Certificate per child (M2-01d, CERTIFICATE_SPEC, David
 * 2026-10-12).
 *
 * Completion (tick, `processCompletions()` at the end of `pets:process-decay`):
 * a child's challenge ends 84 PROGRAM days after their start (their contract,
 * or the birth for grandfathered caretakers — CareScoreService::board), the
 * payment-lock time not counted (M3-11b) — the exact instant where
 * CareScoreService::progress() turns `completed`. Only a challenge-plan pet
 * (never the free plan, M3-11), only ACTIVE caretakers (a deleted child's
 * tombstone gets nothing). For each such (pet, child) without a row:
 *  - the row is written once the family-local END DAY is closed by the
 *    routine ledger (`pets.routines_closed_through` ≥ that date — QA M2):
 *    final score = the child's fair-share Care Score of every routine up to
 *    AND INCLUDING the end day (CareScoreService::childScore `$toDate`),
 *    deterministic, never recomputed;
 *  - outcome `certificate` when score ≥ CERTIFICATE_MIN_SCORE (70), else
 *    `report` / `low_score`; no scored routine at all (score null) → no row;
 *  - game over BEFORE the end → `report` / `game_over`, written at the first
 *    tick after the game over for every caretaker who had started by then
 *    (completed_at = the game over; score = closed days up to the game-over
 *    day, null when none is closed yet). A game over after the 84 days does
 *    not change an ended challenge;
 *  - only ends at / after `config('certificates.launched_at')` (QA M3: the
 *    first deploy never backfills history);
 *  - a pet switched off by an admin (inactive, no game over) is skipped.
 * One row per (pet, child) (unique + re-check under the pet row lock), one
 * push `challenge_completed` to the parents in the same transaction
 * (`notified_at`, queued after commit).
 *
 * Scan (QA M1): SQL keeps only pets that can end now — active, not waiting
 * for payment, with a caretaker who started ≥ 84 days ago; or game over after
 * the launch — ordered by `pets.certificate_next_check_at` (null first). After
 * a pet is looked at, that column is set to the earliest moment something can
 * change (a caretaker's end, a retry), else far in the future; a new caretaker
 * / contract or a game over resets it. So pets that cannot finish never starve
 * the others.
 *
 * Generation (`generate()`, parent): outcome `certificate` only; renders the
 * PDF locally (CertificatePdfRenderer, no external HTTP) into the private
 * `certificates` disk under the row lock and stores names + language;
 * number, score and dates never change on a regenerate.
 */
class ChallengeCertificateService
{
    /** Final Care Score from which the child gets the certificate (David 2026-10-12, CERTIFICATE_SPEC §1). */
    public const CERTIFICATE_MIN_SCORE = 70;

    /** 12 weeks of program time. */
    public const CHALLENGE_DAYS = CareScoreService::CHALLENGE_WEEKS * 7;

    /** Candidate pets looked at per tick (next check first, then oldest id). */
    public const MAX_PETS_PER_TICK = 200;

    /** Longest name printed on the certificate (child and pet). */
    public const MAX_NAME_LENGTH = 30;

    public const DISK = 'certificates';

    /** `certificate_next_check_at` of a pet with nothing left to finish (reset by new caretakers / contracts / game over). */
    public const NEVER = '2999-12-31 00:00:00';

    /** A pet whose end day is not closed yet (or that failed) is looked at again this much later. */
    public const RETRY_MINUTES = 5;

    /** A pet whose end cannot be computed now (open payment lock) is looked at again this much later. */
    public const LOCKED_RECHECK_HOURS = 6;

    public function __construct(
        private readonly CareScoreService $scores,
        private readonly NotificationService $notifications,
        private readonly CertificatePdfRenderer $renderer,
        private readonly PetMediaService $media,
    ) {}

    public function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    /** Feature launch (config/certificates.php): earlier ends get no row. */
    public static function launchedAt(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) config('certificates.launched_at', '2026-10-12T00:00:00Z'))->utc();
    }

    // ──────────────────────────────────────────────────────────────
    //  Completion (tick)
    // ──────────────────────────────────────────────────────────────

    /**
     * @return array{pets: int, created: int}
     */
    public function processCompletions(?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();
        $cutoff = $now->subDays(self::CHALLENGE_DAYS);
        $launch = self::launchedAt();

        // Lower bound of a caretaker's start (completion ≥ start + 84 days).
        $start = 'COALESCE(c.started_at, (SELECT MIN(k.signed_at) FROM pet_contracts k WHERE k.pet_id = c.pet_id AND k.user_id = c.user_id), '
            .'CASE WHEN c.requires_contract THEN NULL ELSE pets.born_at END)';
        $pending = fn ($q) => $q->selectRaw('1')
            ->from('pet_caretakers as c')
            ->whereColumn('c.pet_id', 'pets.id')
            ->whereNotNull('c.user_id')
            ->whereNull('c.ended_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('challenge_certificates as cc')
                ->whereColumn('cc.pet_id', 'c.pet_id')->whereColumn('cc.child_user_id', 'c.user_id'));

        $pets = Pet::born()
            ->where('plan', PetPlan::Challenge->value)
            ->where(fn ($q) => $q->whereNull('certificate_next_check_at')->orWhere('certificate_next_check_at', '<=', $now))
            ->where(fn ($q) => $q
                // Ending by time: active, not waiting for payment, a caretaker 84+ days in.
                ->where(fn ($q) => $q->where('is_active', true)->whereNull('payment_locked_at')
                    ->whereExists(fn ($q) => $pending($q)->whereRaw("{$start} <= ?", [$cutoff])))
                // Ending by a game over after the launch, with a caretaker who had started.
                ->orWhere(fn ($q) => $q->where('is_game_over', true)
                    ->whereExists(fn ($q) => $q->selectRaw('1')->from('activities_log as g')
                        ->whereColumn('g.pet_id', 'pets.id')
                        ->where('g.activity_type', ActivityType::IgnoredWarning->value)
                        ->where('g.value', -2)
                        ->where('g.created_at', '>=', $launch))
                    ->whereExists(fn ($q) => $pending($q)->whereRaw("{$start} IS NOT NULL"))))
            ->orderByRaw('certificate_next_check_at ASC NULLS FIRST')
            ->orderBy('id')
            ->limit(self::MAX_PETS_PER_TICK)
            ->with(['family', 'paymentLockPeriods'])
            ->get();

        $created = 0;
        foreach ($pets as $pet) {
            try {
                $created += $this->processPet($pet, $now);
            } catch (Throwable $e) {
                // One pet never stops the tick; it moves to the back and is retried later.
                Log::error('ChallengeCertificateService: completion failed', ['pet_id' => $pet->id, 'error' => $e->getMessage()]);
                report($e);
                $this->setNextCheck($pet, $now->addMinutes(self::RETRY_MINUTES));
            }
        }

        return ['pets' => $pets->count(), 'created' => $created];
    }

    /**
     * Rows created for one pet (0 when nobody finished yet). Always moves the
     * pet's `certificate_next_check_at`.
     */
    public function processPet(Pet $pet, CarbonImmutable $now): int
    {
        if ($pet->isFreePlan() || $pet->isUnborn() || (! $pet->is_active && ! $pet->is_game_over)) {
            $this->setNextCheck($pet, null);

            return 0;
        }

        $tz = $pet->familyTimezone();
        $launch = self::launchedAt();
        $closedThrough = $pet->routines_closed_through !== null ? substr((string) $pet->routines_closed_through, 0, 10) : null;
        $caretakers = PetCaretaker::where('pet_id', $pet->id)->orderBy('id')->get();
        $contracts = PetContract::where('pet_id', $pet->id)->get(['pet_id', 'user_id', 'signed_at']);
        $existing = ChallengeCertificate::where('pet_id', $pet->id)->pluck('child_user_id')->map(fn ($id) => (int) $id)->all();
        $gameOverAt = $pet->is_game_over ? $this->gameOverAt($pet) : null;

        $next = null;
        $bump = function (CarbonImmutable $at) use (&$next): void {
            $next = $next === null || $at->lessThan($next) ? $at : $next;
        };
        $retry = $now->addMinutes(self::RETRY_MINUTES);

        // [child id, start, end, game over?, score up to (Y-m-d|null)]
        $due = [];
        foreach ($caretakers as $c) {
            if ($c->isTombstone() || in_array((int) $c->user_id, $existing, true)) {
                continue;
            }
            $start = $this->startOf($pet, $c, $contracts);
            if ($start === null) {
                continue; // signing the contract resets the next check
            }
            $completion = $this->completionAt($pet, $start);

            if ($gameOverAt !== null) {
                if ($gameOverAt->lessThan($launch) || $start->greaterThanOrEqualTo($gameOverAt)) {
                    continue; // before the launch / never started: nothing, ever
                }
                if ($completion === null || $completion->greaterThan($gameOverAt)) {
                    $goDate = $gameOverAt->setTimezone($tz)->toDateString();
                    $scoreTo = $closedThrough === null ? null : min($closedThrough, $goDate);
                    $due[] = [(int) $c->user_id, $start, $gameOverAt, true, $scoreTo];

                    continue;
                }
                // Ended before the game over: a normal end (below).
            }

            if ($completion === null) {
                $bump($now->addHours(self::LOCKED_RECHECK_HOURS)); // open payment lock

                continue;
            }
            if ($completion->lessThan($launch)) {
                continue; // ended before the feature existed: no backfill
            }
            if ($completion->greaterThan($now)) {
                $bump($completion);

                continue;
            }
            $endDate = $completion->setTimezone($tz)->toDateString();
            if ($closedThrough === null || $closedThrough < $endDate) {
                $bump($retry); // wait until the ledger closed the end day

                continue;
            }
            $due[] = [(int) $c->user_id, $start, $completion, false, $endDate];
        }

        $created = 0;
        if ($due !== []) {
            $board = $this->scores->board(collect([$pet]), $tz, $caretakers, $contracts, $now);

            foreach ($due as [$childId, $start, $endAt, $gameOver, $scoreTo]) {
                $score = $scoreTo === null ? null : $this->scores->childScore($board, $childId, $pet, null, $scoreTo)['score'];

                if (! $gameOver && $score === null) {
                    // No scored routine in the whole challenge: nothing to certify or report.
                    Log::warning('Challenge ended without any scored routine — no certificate row', ['pet_id' => $pet->id]);

                    continue;
                }

                [$outcome, $reason] = match (true) {
                    $gameOver => [CertificateOutcome::Report, CertificateReason::GameOver],
                    $score >= self::CERTIFICATE_MIN_SCORE => [CertificateOutcome::Certificate, null],
                    default => [CertificateOutcome::Report, CertificateReason::LowScore],
                };

                $localDate = $endAt->setTimezone($tz)->toDateString();
                if ($this->record($pet, $childId, $start, $endAt, $localDate, $score, $outcome, $reason) !== null) {
                    $created++;
                }
            }
        }

        $this->setNextCheck($pet, $next);

        return $created;
    }

    /** Null = nothing left to wait for (far future; reset by new caretakers / contracts / game over). */
    private function setNextCheck(Pet $pet, ?CarbonImmutable $at): void
    {
        DB::table('pets')->where('id', $pet->id)->update([
            'certificate_next_check_at' => $at?->format('Y-m-d H:i:s') ?? self::NEVER,
        ]);
    }

    /**
     * The instant a challenge that started at $start reaches 84 program days
     * (payment-lock time does not count), or null while an open payment lock
     * keeps the clock standing still before that point.
     */
    public function completionAt(Pet $pet, CarbonImmutable $start): ?CarbonImmutable
    {
        $target = self::CHALLENGE_DAYS * 86400;
        $t = $start->addSeconds($target);

        foreach ($pet->paymentLockSpans() as [$from, $to]) {
            if ($to === null && $from < $t->getTimestamp()) {
                return null; // locked now, before the end — the clock stands still
            }
        }

        // Each lock inside [start, t) pushes t further; at most one step per span.
        for ($i = 0; $i <= count($pet->paymentLockSpans()) + 1; $i++) {
            $next = $start->addSeconds($target + $pet->programSecondsPausedBetween($start, $t));
            if ($next->equalTo($t)) {
                return $t;
            }
            $t = $next;
            foreach ($pet->paymentLockSpans() as [$from, $to]) {
                if ($to === null && $from < $t->getTimestamp()) {
                    return null;
                }
            }
        }

        return $t;
    }

    /**
     * When the child started caring (same rule as CareScoreService::board):
     * the stored start, their contract, or the birth for grandfathered
     * caretakers; never before the birth.
     *
     * @param  Collection<int, PetContract>  $contracts
     */
    public function startOf(Pet $pet, PetCaretaker $c, Collection $contracts): ?CarbonImmutable
    {
        if ($pet->born_at === null) {
            return null;
        }
        $bornAt = CarbonImmutable::instance($pet->born_at)->utc();

        if ($c->started_at !== null) {
            $start = CarbonImmutable::instance($c->started_at)->utc();
        } elseif (! $c->requires_contract) {
            $start = $bornAt;
        } else {
            $signed = $contracts->first(fn ($k) => (int) $k->pet_id === $pet->id && (int) $k->user_id === (int) $c->user_id);
            $start = $signed?->signed_at !== null ? CarbonImmutable::instance($signed->signed_at)->utc() : null;
        }

        return $start !== null && $start->lessThan($bornAt) ? $bornAt : $start;
    }

    /**
     * When the pet went to the shelter: its game-over activity row
     * (EscalationService, value −2), else the start of its last inactive
     * period, else its last update.
     */
    public function gameOverAt(Pet $pet): CarbonImmutable
    {
        $at = ActivityLog::where('pet_id', $pet->id)
            ->where('activity_type', ActivityType::IgnoredWarning->value)
            ->where('value', -2)
            ->max('created_at')
            ?? PetStatusPeriod::where('pet_id', $pet->id)->where('kind', PetStatusPeriodKind::Inactive->value)->max('started_at')
            ?? $pet->updated_at
            ?? now();

        return CarbonImmutable::parse($at, 'UTC')->utc();
    }

    /**
     * Insert the row (+ number + push) once, under the pet row lock.
     */
    private function record(Pet $pet, int $childId, CarbonImmutable $start, CarbonImmutable $endAt, string $localDate, ?int $score, CertificateOutcome $outcome, ?CertificateReason $reason): ?ChallengeCertificate
    {
        return DB::transaction(function () use ($pet, $childId, $start, $endAt, $localDate, $score, $outcome, $reason): ?ChallengeCertificate {
            $locked = Pet::whereKey($pet->id)->lockForUpdate()->first();
            if ($locked === null
                || ChallengeCertificate::where('pet_id', $pet->id)->where('child_user_id', $childId)->exists()) {
                return null;
            }

            $certificate = ChallengeCertificate::create([
                'family_id' => $locked->family_id,
                'pet_id' => $locked->id,
                'child_user_id' => $childId,
                'number' => $outcome === CertificateOutcome::Certificate ? $this->allocateNumber((int) substr($localDate, 0, 4)) : null,
                'outcome' => $outcome,
                'reason' => $reason,
                'care_score' => $score,
                'started_at' => $start,
                'completed_at' => $endAt,
                'completed_local_date' => $localDate,
                'species' => $locked->speciesValue(),
                'breed_type' => $locked->breed_type,
                'default_pet_name' => $locked->name,
                // The push decision is made now (sent after commit, held over quiet hours).
                'notified_at' => now(),
            ]);

            $this->notifications->challengeCompleted($locked, $outcome->value);

            Log::info('Challenge ended', [
                'certificate_id' => $certificate->id,
                'pet_id' => $locked->id,
                'outcome' => $outcome->value,
                'reason' => $reason?->value,
            ]);

            return $certificate;
        });
    }

    /**
     * Next certificate number of a year: PP-YYYY-NNNNNN. Atomic upsert, so
     * parallel ticks never get the same number (the column is unique too).
     */
    public function allocateNumber(int $year): string
    {
        $row = DB::selectOne(
            'INSERT INTO challenge_certificate_numbers (year, last_number) VALUES (?, 1)
             ON CONFLICT (year) DO UPDATE SET last_number = challenge_certificate_numbers.last_number + 1
             RETURNING last_number',
            [$year],
        );

        return sprintf('PP-%04d-%06d', $year, (int) $row->last_number);
    }

    // ──────────────────────────────────────────────────────────────
    //  Generation (parent)
    // ──────────────────────────────────────────────────────────────

    /**
     * Render (or re-render) the PDF with the parent's names and language.
     *
     * @throws CertificateException not_eligible (409) for a report
     */
    public function generate(ChallengeCertificate $certificate, string $locale, string $childName, string $petName, User $parent): ChallengeCertificate
    {
        if (! $certificate->isCertificate()) {
            throw new CertificateException('not_eligible', 'This challenge ended without a certificate.', 409);
        }

        return DB::transaction(function () use ($certificate, $locale, $childName, $petName, $parent): ChallengeCertificate {
            /** @var ChallengeCertificate $locked */
            $locked = ChallengeCertificate::whereKey($certificate->id)->lockForUpdate()->firstOrFail();

            $pdf = $this->renderer->render([
                'locale' => $locale,
                'child_name' => $childName,
                'pet_name' => $petName,
                'breed_label' => self::breedLabel($locked->breed_type->value, $locale),
                'care_score' => (int) $locked->care_score,
                'number' => (string) $locked->number,
                'completed_local_date' => $locked->localDate(),
            ]);

            // Same path on every regenerate: written to a temp file, then renamed over
            // the old one (atomic on the local disk — a reader never sees half a PDF).
            $path = self::pathFor($locked);
            $tmp = $path.'.tmp-'.bin2hex(random_bytes(6));
            $this->disk()->put($tmp, $pdf);
            $this->disk()->move($tmp, $path);

            $locked->forceFill([
                'child_name' => $childName,
                'pet_name' => $petName,
                'locale' => $locale,
                'pdf_path' => $path,
                'generated_at' => now(),
                'generated_by_user_id' => $parent->id,
            ])->save();

            return $locked;
        });
    }

    public static function pathFor(ChallengeCertificate $certificate): string
    {
        return self::pathOf($certificate->pet_id, $certificate->id);
    }

    /** `{pet_id}/certificate-{id}.pdf` on the `certificates` disk. */
    public static function pathOf(int $petId, int $certificateId): string
    {
        return $petId.'/certificate-'.$certificateId.'.pdf';
    }

    public static function breedLabel(string $breedType, string $locale): string
    {
        $key = 'breeds.'.$breedType;
        $label = trans($key, [], $locale);

        return is_string($label) && $label !== $key ? $label : $breedType;
    }

    // ──────────────────────────────────────────────────────────────
    //  Payloads
    // ──────────────────────────────────────────────────────────────

    /**
     * Signed, expiring URL of the PDF (same expiry buckets as pet media);
     * `v` changes with every (re)generation.
     */
    public function signedPdfUrl(ChallengeCertificate $certificate, ?CarbonImmutable $expires = null): ?string
    {
        if (! $certificate->isGenerated()) {
            return null;
        }

        $path = URL::temporarySignedRoute(
            'certificates.pdf',
            $expires ?? $this->media->urlExpiry(),
            ['certificate' => $certificate->id, 'v' => substr(sha1($certificate->pdf_path.'|'.$certificate->generated_at?->getTimestamp()), 0, 10)],
            absolute: false,
        );

        return rtrim((string) config('app.url'), '/').$path;
    }

    public function payload(ChallengeCertificate $certificate, string $timezone, ?string $requestLocale = null, ?CarbonImmutable $expires = null): CertificatePayload
    {
        $locale = in_array($requestLocale ?? app()->getLocale(), ['en', 'sl'], true) ? ($requestLocale ?? app()->getLocale()) : 'en';
        $iso = fn (?CarbonInterface $at): ?string => $at?->copy()->setTimezone($timezone)->toIso8601String();
        $nickname = $certificate->relationLoaded('child') ? $certificate->child?->name : $certificate->child()->value('name');
        $petName = $certificate->relationLoaded('pet') ? $certificate->pet?->name : $certificate->pet()->value('name');

        /** @var 'en'|'sl' $locale */
        // Printable or "" (the parent then types one): CertificateNames rules.
        $names = app(CertificateNames::class);
        $defaults = [
            'child_name' => $names->printableDefault('child_name', $nickname),
            'pet_name' => $names->printableDefault('pet_name', $petName ?? $certificate->default_pet_name ?? self::breedLabel($certificate->breed_type->value, $locale)),
            'locale' => $locale,
        ];

        /** @var 'certificate'|'report' $outcome */
        $outcome = $certificate->outcome->value;
        /** @var 'low_score'|'game_over'|null $reason */
        $reason = $certificate->reason?->value;
        /** @var 'en'|'sl'|null $savedLocale */
        $savedLocale = $certificate->locale;

        return new CertificatePayload(
            id: $certificate->id,
            childId: $certificate->child_user_id,
            childNickname: $nickname,
            petId: $certificate->pet_id,
            species: $certificate->species->value,
            breedType: $certificate->breed_type->value,
            outcome: $outcome,
            reason: $reason,
            careScore: $certificate->care_score,
            number: $certificate->number,
            startedAt: (string) $iso($certificate->started_at),
            completedAt: (string) $iso($certificate->completed_at),
            completedLocalDate: $certificate->localDate(),
            generated: $certificate->isGenerated(),
            generatedAt: $iso($certificate->generated_at),
            childName: $certificate->child_name,
            petName: $certificate->pet_name,
            locale: $savedLocale,
            defaults: $defaults,
            pdfUrl: $this->signedPdfUrl($certificate, $expires),
        );
    }

    /**
     * The requesting child's view (ChildPetStateResource `certificate`). A
     * report shows only that the challenge ended (no score, no reason — no
     * shaming; the parent talks about it, David 2026-10-12 "otrok vidi
     * certifikat"); a certificate shows its details, the PDF once generated.
     */
    public function childPayload(Pet $pet, User $child, string $timezone): ?ChildCertificatePayload
    {
        $certificate = ChallengeCertificate::where('pet_id', $pet->id)->where('child_user_id', $child->id)->first();
        if ($certificate === null) {
            return null;
        }

        if (! $certificate->isCertificate()) {
            return new ChildCertificatePayload('report', false, null, null, null, null, null, null, null);
        }

        /** @var 'en'|'sl'|null $locale */
        $locale = $certificate->locale;

        return new ChildCertificatePayload(
            outcome: 'certificate',
            generated: $certificate->isGenerated(),
            childName: $certificate->child_name,
            petName: $certificate->pet_name,
            careScore: $certificate->care_score,
            number: $certificate->number,
            completedAt: $certificate->completed_at->copy()->setTimezone($timezone)->toIso8601String(),
            locale: $locale,
            pdfUrl: $this->signedPdfUrl($certificate),
        );
    }
}
