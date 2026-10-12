<?php

use App\Enums\BreedType;
use App\Enums\CertificateOutcome;
use App\Enums\CertificateReason;
use App\Enums\PushType;
use App\Jobs\DeleteCertificateFiles;
use App\Models\ChallengeCertificate;
use App\Models\Pet;
use App\Models\PetContract;
use App\Models\PetDailyRoutine;
use App\Models\PetStatusPeriod;
use App\Models\PushNotification;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\AccountExportService;
use App\Services\CareScoreService;
use App\Services\CertificatePdfRenderer;
use App\Services\ChallengeCertificateService;
use App\Services\FamilyService;
use App\Services\Push\PushCopy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M2-01d — Responsibility Certificate per child (CERTIFICATE_SPEC, David 2026-10-12)
|--------------------------------------------------------------------------
| Family in Europe/Ljubljana. The pet is born 2026-10-05 08:00 UTC; a child who
| cares from the birth completes 84 program days later: 2026-12-28 08:00 UTC
| (09:00 CET). Scores come from planted, closed routine rows (100 feed routines,
| two per day from 2026-10-06), so "done of 100" is the Care Score.
*/

const CERT_BORN = '2026-10-05 08:00:00';
const CERT_DONE = '2026-12-28 08:00:00';

beforeEach(function () {
    Queue::fake();
    Storage::fake('certificates');
    seedBreedConfigs();
});

function certAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

/**
 * @return array{0: User, 1: User, 2: Pet}
 */
function certFamily(string $factoryState = 'labradorRetriever', array $attributes = []): array
{
    certAt(CERT_BORN);
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Maja']);
    $factory = Pet::factory();
    foreach (explode(',', $factoryState) as $state) {
        $factory = $factory->{$state}();
    }
    $pet = disableHygieneEvents($factory->create(['user_id' => $child->id] + $attributes));
    // Closed far ahead: only the planted rows count, the tick's ledger leaves the pet alone.
    DB::table('pets')->where('id', $pet->id)->update(['routines_closed_through' => '2027-03-31', 'routines_next_close_at' => null]);

    return [$parent, $child, $pet->fresh()];
}

/** $done done + $missed missed feed routines (two a day from $from), by $child. */
function certPlant(Pet $pet, ?User $child, int $done, int $missed, string $from = '2026-10-06'): void
{
    $day = Carbon::parse($from, 'UTC');
    $rows = array_merge(array_fill(0, $done, 'done'), array_fill(0, $missed, 'missed'));
    foreach (array_chunk($rows, 2) as $pair) {
        foreach ($pair as $slot => $status) {
            $opens = $day->copy()->setTime(7 + 4 * $slot, 0);
            PetDailyRoutine::create([
                'pet_id' => $pet->id, 'local_date' => $day->toDateString(), 'routine_type' => 'feed', 'slot' => $slot,
                'opens_at' => $opens, 'due_at' => $opens->copy()->addHours(2), 'status' => $status,
                'done_at' => $status === 'done' ? $opens->copy()->addMinutes(30) : null,
                'actor_user_id' => $status === 'done' ? $child?->id : null,
            ]);
        }
        $day->addDay();
    }
}

function certTick(?string $utc = null): array
{
    if ($utc !== null) {
        certAt($utc);
    }

    return app(ChallengeCertificateService::class)->processCompletions();
}

function certRow(Pet $pet, User $child): ?ChallengeCertificate
{
    return ChallengeCertificate::where('pet_id', $pet->id)->where('child_user_id', $child->id)->first();
}

function certSignedPath(ChallengeCertificate $c): string
{
    $parts = parse_url((string) app(ChallengeCertificateService::class)->signedPdfUrl($c->fresh()));

    return $parts['path'].'?'.$parts['query'];
}

function certGenerate(User $parent, ChallengeCertificate $c, array $body = [])
{
    actingAsRole($parent);

    return postJson("/api/parent/certificates/{$c->id}/generate", $body + ['locale' => 'sl', 'child_name' => 'Maja', 'pet_name' => 'Luna']);
}

describe('completion', function () {
    it('ends the challenge exactly 84 program days after the start, with a numbered certificate', function () {
        [$parent, $child, $pet] = certFamily();
        certPlant($pet, $child, 86, 14);

        certTick('2026-12-28 07:59:00');
        expect(ChallengeCertificate::count())->toBe(0);

        certTick(CERT_DONE);
        $row = certRow($pet, $child);
        expect($row)->not->toBeNull()
            ->and($row->outcome)->toBe(CertificateOutcome::Certificate)
            ->and($row->reason)->toBeNull()
            ->and($row->care_score)->toBe(86)
            ->and($row->number)->toBe('PP-2026-000001')
            ->and($row->completed_at->utc()->toDateTimeString())->toBe(CERT_DONE)
            ->and($row->started_at->utc()->toDateTimeString())->toBe(CERT_BORN)
            ->and($row->localDate())->toBe('2026-12-28')
            ->and($row->breed_type)->toBe(BreedType::LabradorRetriever)
            ->and($row->notified_at)->not->toBeNull()
            ->and($row->generated_at)->toBeNull();

        // Same instant as the dashboard's 12-week progress turning `completed`.
        $scores = app(CareScoreService::class);
        $board = $scores->board(collect([$pet->fresh()]), 'Europe/Ljubljana');
        expect($scores->childSummary($board, $child->id, $pet->fresh())['progress']['completed'])->toBeTrue();
    });

    it('shifts the end by the payment-lock time and never ends while locked (M3-11b)', function () {
        [, $child, $pet] = certFamily();
        certPlant($pet, $child, 80, 20);
        PetStatusPeriod::create(['pet_id' => $pet->id, 'kind' => 'payment_lock',
            'started_at' => Carbon::parse('2026-11-01 08:00:00', 'UTC'), 'ended_at' => Carbon::parse('2026-11-11 08:00:00', 'UTC')]);

        certTick(CERT_DONE);
        expect(ChallengeCertificate::count())->toBe(0);
        certTick('2027-01-07 07:59:00');
        expect(ChallengeCertificate::count())->toBe(0);
        certTick('2027-01-07 08:00:00');
        expect(certRow($pet, $child)->completed_at->utc()->toDateTimeString())->toBe('2027-01-07 08:00:00');

        // An open lock before the end keeps the clock standing still.
        [, $child2, $pet2] = certFamily('borderCollie');
        PetStatusPeriod::create(['pet_id' => $pet2->id, 'kind' => 'payment_lock',
            'started_at' => Carbon::parse('2026-11-01 08:00:00', 'UTC'), 'ended_at' => null]);
        certTick('2027-06-01 08:00:00');
        expect(certRow($pet2, $child2))->toBeNull();
    });

    it('never ends a free-plan challenge (M3-11)', function () {
        [, $child, $pet] = certFamily('mutt,freePlan');
        expect($pet->isFreePlan())->toBeTrue();
        certPlant($pet, $child, 100, 0);

        certTick('2027-02-01 08:00:00');
        expect(ChallengeCertificate::count())->toBe(0);
    });

    it('gives a report below 70 and a certificate at 70', function () {
        [, $child, $pet] = certFamily();
        certPlant($pet, $child, 69, 31);
        [, $child70, $pet70] = certFamily('borderCollie');
        certPlant($pet70, $child70, 70, 30);

        certTick(CERT_DONE);

        $low = certRow($pet, $child);
        expect($low->outcome)->toBe(CertificateOutcome::Report)
            ->and($low->reason)->toBe(CertificateReason::LowScore)
            ->and($low->care_score)->toBe(69)
            ->and($low->number)->toBeNull();

        $ok = certRow($pet70, $child70);
        expect(ChallengeCertificateService::CERTIFICATE_MIN_SCORE)->toBe(70)
            ->and($ok->outcome)->toBe(CertificateOutcome::Certificate)
            ->and($ok->care_score)->toBe(70)
            ->and($ok->number)->toBe('PP-2026-000001');
    });

    it('records a game over before the end as a report at the next tick', function () {
        [, $child, $pet] = certFamily();
        certPlant($pet, $child, 40, 0);
        DB::table('pets')->where('id', $pet->id)->update(['is_game_over' => true, 'is_active' => false]);
        DB::table('activities_log')->insert(['pet_id' => $pet->id, 'activity_type' => 'ignored_warning', 'value' => -2, 'created_at' => '2026-11-04 10:00:00']);

        certTick('2026-11-04 10:01:00');

        $row = certRow($pet, $child);
        expect($row->outcome)->toBe(CertificateOutcome::Report)
            ->and($row->reason)->toBe(CertificateReason::GameOver)
            ->and($row->number)->toBeNull()
            ->and($row->completed_at->utc()->toDateTimeString())->toBe('2026-11-04 10:00:00')
            ->and($row->localDate())->toBe('2026-11-04')
            ->and($row->care_score)->toBe(100);
    });

    it('keeps a challenge that ended before a later game over', function () {
        [, $child, $pet] = certFamily();
        certPlant($pet, $child, 90, 10);
        DB::table('pets')->where('id', $pet->id)->update(['is_game_over' => true, 'is_active' => false]);
        DB::table('activities_log')->insert(['pet_id' => $pet->id, 'activity_type' => 'ignored_warning', 'value' => -2, 'created_at' => '2026-12-29 10:00:00']);

        // The tick that should have seen the end ran only after the game over.
        certTick('2026-12-29 10:01:00');

        $row = certRow($pet, $child);
        expect($row->outcome)->toBe(CertificateOutcome::Certificate)
            ->and($row->completed_at->utc()->toDateTimeString())->toBe(CERT_DONE);
    });

    it('gives every child of a shared pet their own end, score and number', function () {
        [$parent, $child, $pet] = certFamily();
        certPlant($pet, $child, 100, 0);
        $sibling = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Luka']);
        app(FamilyService::class)->addCaretaker($pet, $sibling, true);
        PetContract::create(['pet_id' => $pet->id, 'user_id' => $sibling->id, 'signature_format' => 'svg_path',
            'signature' => 'M1 1 L2 2', 'signed_at' => Carbon::parse('2026-10-15 08:00:00', 'UTC')]);

        certTick(CERT_DONE);
        expect(ChallengeCertificate::count())->toBe(1)
            ->and(certRow($pet, $child)->care_score)->toBe(100);

        certTick('2027-01-07 08:00:00');
        $siblingRow = certRow($pet, $sibling);
        $scores = app(CareScoreService::class);
        $board = $scores->board(collect([$pet->fresh()]), 'Europe/Ljubljana');
        expect($siblingRow->completed_at->utc()->toDateTimeString())->toBe('2027-01-07 08:00:00')
            ->and($siblingRow->started_at->utc()->toDateTimeString())->toBe('2026-10-15 08:00:00')
            // Luka shared the routines from his contract on and did none of them.
            ->and($siblingRow->care_score)->toBe($scores->childScore($board, $sibling->id, $pet->fresh(), null, '2027-01-07')['score'])
            ->and($siblingRow->care_score)->toBe(0)
            ->and($siblingRow->outcome)->toBe(CertificateOutcome::Report)
            ->and(ChallengeCertificate::count())->toBe(2);
    });

    it('is idempotent: one row and one push per child', function () {
        config(['push.enabled' => true]);
        [$parent, $child, $pet] = certFamily();
        certPlant($pet, $child, 90, 10);

        certTick(CERT_DONE);
        certTick('2026-12-28 08:01:00');
        certTick('2026-12-29 08:01:00');

        expect(ChallengeCertificate::count())->toBe(1);
        $pushes = PushNotification::where('type', PushType::ChallengeCompleted->value)->get();
        expect($pushes)->toHaveCount(1)
            ->and($pushes[0]->metric)->toBe('certificate')
            ->and($pushes[0]->pet_id)->toBe($pet->id)
            ->and(collect($pushes[0]->recipients)->pluck('audience')->unique()->all())->toBe(['parent'])
            ->and(collect($pushes[0]->recipients)->pluck('user_id')->all())->toBe([$parent->id]);
    });

    it('freezes the score: routines after the local end date never count', function () {
        [, $child, $pet] = certFamily();
        certPlant($pet, $child, 80, 20);
        // Two missed feeds the day after the end.
        certPlant($pet, null, 0, 2, '2026-12-29');

        certTick('2026-12-30 12:00:00'); // a late tick
        expect(certRow($pet, $child)->care_score)->toBe(80);
    });

    it('runs at the end of pets:process-decay', function () {
        [, $child, $pet] = certFamily();
        certPlant($pet, $child, 75, 25);
        certAt(CERT_DONE);
        DB::table('pets')->where('id', $pet->id)->update(['last_decay_at' => now(), 'last_step_reset_at' => now()]);

        Artisan::call('pets:process-decay');

        expect(certRow($pet, $child)?->care_score)->toBe(75);
    });
});

describe('end day, launch and scan (QA)', function () {
    it('waits until the end day is closed and counts that whole day (QA M2)', function () {
        [, $child, $pet] = certFamily();
        certPlant($pet, $child, 69, 30); // 69 / 99 would round to 70
        DB::table('pets')->where('id', $pet->id)->update(['routines_closed_through' => '2026-12-27']);

        certTick(CERT_DONE);
        expect(certRow($pet, $child))->toBeNull();

        // The evening feed of the end day (after the 09:00 end) is missed — it counts.
        PetDailyRoutine::create([
            'pet_id' => $pet->id, 'local_date' => '2026-12-28', 'routine_type' => 'feed', 'slot' => 1,
            'opens_at' => Carbon::parse('2026-12-28 16:00:00', 'UTC'), 'due_at' => Carbon::parse('2026-12-28 18:00:00', 'UTC'),
            'status' => 'missed',
        ]);
        DB::table('pets')->where('id', $pet->id)->update(['routines_closed_through' => '2026-12-28']);
        certTick('2026-12-28 23:10:00');

        $row = certRow($pet, $child);
        expect($row->care_score)->toBe(69) // 69 / 100: the whole end day counts
            ->and($row->outcome)->toBe(CertificateOutcome::Report)
            ->and($row->completed_at->utc()->toDateTimeString())->toBe(CERT_DONE);
    });

    it('never backfills challenges that ended before the launch (QA M3)', function () {
        // Born 2026-07-01: the 84 days ended 2026-09-23, before certificates existed.
        certAt('2026-07-01 08:00:00');
        $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        $pet = disableHygieneEvents(Pet::factory()->labradorRetriever()->create(['user_id' => $child->id]));
        DB::table('pets')->where('id', $pet->id)->update(['routines_closed_through' => '2027-03-31']);
        certPlant($pet->fresh(), $child, 90, 10, '2026-07-02');
        // A game over before the launch as well.
        [, $child2, $pet2] = certFamily('borderCollie');
        certPlant($pet2, $child2, 10, 0);
        DB::table('pets')->where('id', $pet2->id)->update(['is_game_over' => true, 'is_active' => false]);
        DB::table('activities_log')->insert(['pet_id' => $pet2->id, 'activity_type' => 'ignored_warning', 'value' => -2, 'created_at' => '2026-10-10 10:00:00']);

        certTick('2026-10-13 10:00:00');
        certTick(CERT_DONE);

        expect(ChallengeCertificate::count())->toBe(0)
            ->and(PushNotification::count())->toBe(0);
    });

    it('creates nothing when the child had no scored routine (QA M3)', function () {
        [, $child, $pet] = certFamily();

        certTick(CERT_DONE);

        expect(certRow($pet, $child))->toBeNull()
            ->and(DB::table('pets')->where('id', $pet->id)->value('certificate_next_check_at'))->toStartWith('2999-12-31');
    });

    it('never lets pets waiting for payment starve a due pet (QA M1)', function () {
        certAt(CERT_BORN);
        $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        for ($i = 0; $i < ChallengeCertificateService::MAX_PETS_PER_TICK + 1; $i++) {
            $c = User::factory()->child()->create(['parent_id' => $parent->id]);
            $locked = Pet::factory()->labradorRetriever()->create(['user_id' => $c->id]);
            DB::table('pets')->where('id', $locked->id)->update(['payment_locked_at' => now(), 'routines_closed_through' => '2027-03-31']);
        }
        [, $child, $pet] = certFamily();
        certPlant($pet, $child, 90, 10);

        expect(certTick(CERT_DONE)['pets'])->toBe(1);
        expect(certRow($pet, $child))->not->toBeNull();
    });

    it('moves pets that cannot finish yet to the back of the scan (QA M1)', function () {
        certAt(CERT_BORN);
        $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        for ($i = 0; $i < ChallengeCertificateService::MAX_PETS_PER_TICK + 1; $i++) {
            $c = User::factory()->child()->create(['parent_id' => $parent->id]);
            $paused = Pet::factory()->labradorRetriever()->create(['user_id' => $c->id]);
            // A finished 10-day payment lock: these end 10 days later.
            PetStatusPeriod::create(['pet_id' => $paused->id, 'kind' => 'payment_lock',
                'started_at' => Carbon::parse('2026-11-01 08:00:00', 'UTC'), 'ended_at' => Carbon::parse('2026-11-11 08:00:00', 'UTC')]);
        }
        [, $child, $pet] = certFamily();
        certPlant($pet, $child, 90, 10);

        certTick(CERT_DONE);
        expect(certRow($pet, $child))->toBeNull(); // 200 older pets came first …
        certTick('2026-12-28 08:01:00');
        expect(certRow($pet, $child))->not->toBeNull(); // … and wait for their own end now
    });

    it('looks again at once when the pet goes to the shelter', function () {
        [, $child, $pet] = certFamily();
        certPlant($pet, $child, 40, 0);
        // Looked at before (e.g. a sibling's end) and parked until the child's own end.
        DB::table('pets')->where('id', $pet->id)->update(['certificate_next_check_at' => CERT_DONE]);

        certAt('2026-11-04 10:00:00');
        DB::table('activities_log')->insert(['pet_id' => $pet->id, 'activity_type' => 'ignored_warning', 'value' => -2, 'created_at' => now()]);
        Pet::find($pet->id)->update(['is_game_over' => true, 'is_active' => false]);
        certTick('2026-11-04 10:01:00');

        expect(certRow($pet, $child)->reason)->toBe(CertificateReason::GameOver);
    });
});

describe('push texts', function () {
    it('tells parents in English and Slovenian without names or species', function () {
        expect(PushCopy::body(PushType::ChallengeCompleted, 'certificate', 'parent', 'en'))->toContain('Certificate of Responsibility')
            ->and(PushCopy::body(PushType::ChallengeCompleted, 'certificate', 'parent', 'sl'))->toContain('certifikat odgovornosti')
            ->and(PushCopy::body(PushType::ChallengeCompleted, 'report', 'parent', 'sl'))->toContain('Poročilo')
            ->and(PushType::ChallengeCompleted->isLockNews())->toBeTrue()
            ->and(PushType::ChallengeCompleted->isUrgent())->toBeFalse();
    });
});

describe('parent API', function () {
    it('lists the family\'s certificates with form defaults, never another family\'s', function () {
        [$parent, $child, $pet] = certFamily();
        certPlant($pet, $child, 86, 14);
        [, $otherChild, $otherPet] = certFamily('borderCollie');
        certPlant($otherPet, $otherChild, 90, 10);
        certTick(CERT_DONE);

        actingAsRole($parent);
        $res = getJson('/api/parent/certificates', ['Accept-Language' => 'sl'])->assertOk();

        expect($res->json('certificates'))->toHaveCount(1);
        $item = $res->json('certificates.0');
        expect($item)->toMatchArray([
            'child_id' => $child->id, 'child_nickname' => 'Maja', 'pet_id' => $pet->id, 'outcome' => 'certificate',
            'reason' => null, 'care_score' => 86, 'number' => 'PP-2026-000001', 'completed_local_date' => '2026-12-28',
            'generated' => false, 'pdf_url' => null, 'child_name' => null, 'pet_name' => null, 'locale' => null,
            'species' => 'dog', 'breed_type' => 'labrador_retriever',
        ])->and($item['completed_at'])->toBe('2026-12-28T09:00:00+01:00')
            ->and($item['defaults'])->toBe(['child_name' => 'Maja', 'pet_name' => 'Labradorec', 'locale' => 'sl']);
    });

    it('generates the PDF (one A4 page) and regenerates it with the same number and score', function () {
        [$parent, $child, $pet] = certFamily();
        certPlant($pet, $child, 86, 14);
        certTick(CERT_DONE);
        $row = certRow($pet, $child);

        $res = certGenerate($parent, $row, ['locale' => 'sl', 'child_name' => ' Žiga ', 'pet_name' => 'Šarik'])->assertOk();
        expect($res->json())->toMatchArray(['generated' => true, 'child_name' => 'Žiga', 'pet_name' => 'Šarik', 'locale' => 'sl', 'number' => 'PP-2026-000001', 'care_score' => 86])
            ->and($res->json('pdf_url'))->toContain('/api/certificates/'.$row->id.'/pdf?');

        $row->refresh();
        $pdf = Storage::disk('certificates')->get($row->pdf_path);
        expect(substr($pdf, 0, 4))->toBe('%PDF')
            ->and(CertificatePdfRenderer::pageCount($pdf))->toBe(1)
            ->and($row->pdf_path)->toBe($pet->id.'/certificate-'.$row->id.'.pdf')
            ->and($row->generated_by_user_id)->toBe($parent->id);

        $again = certGenerate($parent, $row, ['locale' => 'en', 'child_name' => 'Maja', 'pet_name' => 'Luna'])->assertOk();
        expect($again->json())->toMatchArray(['number' => 'PP-2026-000001', 'care_score' => 86, 'locale' => 'en', 'child_name' => 'Maja'])
            ->and($again->json('completed_at'))->toBe($res->json('completed_at'))
            ->and(Storage::disk('certificates')->allFiles())->toBe([$pet->id.'/certificate-'.$row->id.'.pdf']);
    });

    it('keeps the longest names on one page', function () {
        $pdf = app(CertificatePdfRenderer::class)->render([
            'locale' => 'sl', 'child_name' => str_repeat('Š', 30), 'pet_name' => str_repeat('Ž', 30),
            'breed_label' => 'Kavalir King Charles španjel', 'care_score' => 100, 'number' => 'PP-2026-123456',
            'completed_local_date' => '2026-12-28',
        ]);
        expect(CertificatePdfRenderer::pageCount($pdf))->toBe(1);
    });

    it('fits the longest single-word names by their measured width (QA M5)', function () {
        $w = str_repeat('W', 30);
        $size = CertificatePdfRenderer::childNameSize($w);
        expect(CertificatePdfRenderer::textWidth($w, 'BricolageExtraBold', $size, CertificatePdfRenderer::childNameLetterSpacing($size)))
            ->toBeLessThanOrEqual(CertificatePdfRenderer::CHILD_NAME_WIDTH)
            ->and(CertificatePdfRenderer::childNameSize('Maja'))->toBe(68)
            ->and(CertificatePdfRenderer::petNameSize('Luna'))->toBe(28)
            // Too wide for the card even at the minimum: it wraps (break-word) instead.
            ->and(CertificatePdfRenderer::petNameSize($w))->toBe(14);
        foreach (['en', 'sl'] as $locale) {
            $pdf = app(CertificatePdfRenderer::class)->render([
                'locale' => $locale, 'child_name' => $w, 'pet_name' => $w, 'breed_label' => 'Kavalir King Charles španjel',
                'care_score' => 100, 'number' => 'PP-2026-123456', 'completed_local_date' => '2026-12-28',
            ]);
            expect(CertificatePdfRenderer::pageCount($pdf))->toBe(1);
        }
    });

    it('refuses names the certificate fonts cannot print and never offers one as a default (QA M4)', function () {
        [$parent, $child, $pet] = certFamily();
        certPlant($pet, $child, 86, 14);
        certTick(CERT_DONE);
        $row = certRow($pet, $child);

        certGenerate($parent, $row, ['child_name' => 'Маша'])->assertStatus(422)->assertJsonPath('codes.child_name', 'name_unsupported_script');
        certGenerate($parent, $row, ['pet_name' => '小白'])->assertStatus(422)->assertJsonPath('codes.pet_name', 'name_unsupported_script');
        certGenerate($parent, $row, ['child_name' => 'Žiga Čebašek', 'pet_name' => "D'Artagnan"])->assertOk();

        DB::table('users')->where('id', $child->id)->update(['name' => 'Маша']);
        actingAsRole($parent);
        expect(getJson('/api/parent/certificates')->json('certificates.0.defaults.child_name'))->toBe('');
    });

    it('cleans a breed default with parentheses into a valid pet name (QA m2)', function () {
        [$parent, $child, $pet] = certFamily('standardPoodle');
        certPlant($pet, $child, 86, 14);
        certTick(CERT_DONE);

        actingAsRole($parent);
        $defaults = getJson('/api/parent/certificates', ['Accept-Language' => 'en'])->json('certificates.0.defaults');
        expect($defaults['pet_name'])->toBe('Poodle Standard');
        certGenerate($parent, certRow($pet, $child), ['pet_name' => $defaults['pet_name'], 'child_name' => $defaults['child_name']])->assertOk();
    });

    it('validates the language and both names', function () {
        [$parent, $child, $pet] = certFamily();
        certPlant($pet, $child, 86, 14);
        certTick(CERT_DONE);
        $row = certRow($pet, $child);

        certGenerate($parent, $row, ['locale' => 'de'])->assertStatus(422)->assertJsonPath('codes.locale', 'locale_invalid');
        certGenerate($parent, $row, ['child_name' => str_repeat('a', 31)])->assertStatus(422)->assertJsonPath('reason', 'name_too_long');
        certGenerate($parent, $row, ['child_name' => ''])->assertStatus(422)->assertJsonPath('codes.child_name', 'name_invalid');
        certGenerate($parent, $row, ['pet_name' => 'Luna2'])->assertStatus(422)->assertJsonPath('codes.pet_name', 'name_invalid');
        certGenerate($parent, $row, ['pet_name' => 'Bastard'])->assertStatus(422)->assertJsonPath('codes.pet_name', 'name_not_allowed');
        certGenerate($parent, $row, ['child_name' => 'ass'])->assertStatus(422)->assertJsonPath('reason', 'name_not_allowed');
        // A nickname with a digit is a valid child name (CreateChildRequest rules).
        certGenerate($parent, $row, ['child_name' => 'Maja 2'])->assertOk();
        expect(certRow($pet, $child)->child_name)->toBe('Maja 2');
    });

    it('refuses a report (409), another family (404) and a child (403)', function () {
        [$parent, $child, $pet] = certFamily();
        certPlant($pet, $child, 50, 50);
        [$otherParent, $otherChild, $otherPet] = certFamily('borderCollie');
        certPlant($otherPet, $otherChild, 90, 10);
        certTick(CERT_DONE);

        certGenerate($parent, certRow($pet, $child))->assertStatus(409)->assertJsonPath('reason', 'not_eligible');
        certGenerate($parent, certRow($otherPet, $otherChild))->assertStatus(404)->assertJsonPath('reason', 'certificate_not_found');
        certGenerate($otherChild, certRow($otherPet, $otherChild))->assertStatus(403);
        expect(certRow($otherPet, $otherChild)->generated_at)->toBeNull();
    });
});

describe('PDF access', function () {
    it('lets the family parents and the child open the signed PDF, nobody else', function () {
        [$parent, $child, $pet] = certFamily();
        certPlant($pet, $child, 86, 14);
        [$otherParent, $otherChild] = certFamily('borderCollie');
        certTick(CERT_DONE);
        $row = certRow($pet, $child);

        // Not generated yet → no URL; a forged URL finds nothing.
        expect(app(ChallengeCertificateService::class)->signedPdfUrl($row))->toBeNull();

        certGenerate($parent, $row)->assertOk();
        $path = certSignedPath($row);

        app('auth')->forgetGuards();
        $res = $this->get($path)->assertOk(); // the URL itself is the capability
        expect($res->headers->get('Content-Type'))->toBe('application/pdf')
            ->and($res->headers->get('Content-Disposition'))->toContain('PetPrep-Certificate-PP-2026-000001.pdf');

        app('auth')->forgetGuards();
        actingAsRole($parent);
        $this->get($path)->assertOk();
        app('auth')->forgetGuards();
        actingAsRole($child);
        $this->get($path)->assertOk();
        app('auth')->forgetGuards();
        actingAsRole($otherChild);
        $this->get($path)->assertForbidden();
        app('auth')->forgetGuards();
        actingAsRole($otherParent);
        $this->get($path)->assertForbidden(); // a valid URL + another family's parent token

        app('auth')->forgetGuards();
        $this->get(str_replace('signature=', 'signature=0', $path))->assertForbidden();
        $this->get('/api/certificates/'.$row->id.'/pdf')->assertForbidden();
    });
});

describe('child and dashboard', function () {
    it('shows the child their certificate, and only "finished" for a report', function () {
        [$parent, $child, $pet] = certFamily();
        certPlant($pet, $child, 86, 14);
        certTick(CERT_DONE);

        actingAsRole($child);
        getJson('/api/child/pet')->assertOk()->assertJsonPath('certificate', [
            'outcome' => 'certificate', 'generated' => false, 'child_name' => null, 'pet_name' => null,
            'care_score' => 86, 'number' => 'PP-2026-000001', 'completed_at' => '2026-12-28T09:00:00+01:00',
            'locale' => null, 'pdf_url' => null,
        ]);

        certGenerate($parent, certRow($pet, $child))->assertOk();
        app('auth')->forgetGuards();
        actingAsRole($child);
        $state = getJson('/api/child/pet')->assertOk();
        expect($state->json('certificate.generated'))->toBeTrue()
            ->and($state->json('certificate.child_name'))->toBe('Maja')
            ->and($state->json('certificate.pdf_url'))->toContain('/api/certificates/');

        ChallengeCertificate::query()->update(['outcome' => 'report', 'reason' => 'low_score', 'number' => null]);
        app('auth')->forgetGuards();
        actingAsRole($child);
        getJson('/api/child/pet')->assertOk()->assertJsonPath('certificate', [
            'outcome' => 'report', 'generated' => false, 'child_name' => null, 'pet_name' => null,
            'care_score' => null, 'number' => null, 'completed_at' => null, 'locale' => null, 'pdf_url' => null,
        ]);
    });

    it('is null in the child state while the challenge runs', function () {
        [, $child] = certFamily();
        actingAsRole($child);
        getJson('/api/child/pet')->assertOk()->assertJsonPath('certificate', null);
    });

    it('adds the certificate to the child block of the family dashboard', function () {
        [$parent, $child, $pet] = certFamily();
        certPlant($pet, $child, 86, 14);
        certTick(CERT_DONE);

        actingAsRole($parent);
        $children = getJson('/api/parent/dashboard')->assertOk()->json('family.children');
        $block = collect($children)->firstWhere('id', $child->id);
        expect($block['certificate']['number'])->toBe('PP-2026-000001')
            ->and($block['certificate']['outcome'])->toBe('certificate');
    });
});

describe('privacy', function () {
    it('deletes a child\'s rows and PDFs with the child profile', function () {
        [$parent, $child, $pet] = certFamily();
        certPlant($pet, $child, 86, 14);
        certTick(CERT_DONE);
        certGenerate($parent, certRow($pet, $child))->assertOk();
        $path = certRow($pet, $child)->pdf_path;
        // A sibling keeps the pet (shared), so only the child's file goes.
        $sibling = User::factory()->child()->create(['parent_id' => $parent->id]);
        app(FamilyService::class)->addCaretaker($pet, $sibling, false);

        app(AccountDeletionService::class)->deleteChildProfile($parent, $child, true);

        expect(ChallengeCertificate::count())->toBe(0);
        Queue::assertPushed(DeleteCertificateFiles::class, fn (DeleteCertificateFiles $job) => $job->paths === [$path]);
        (new DeleteCertificateFiles([], [$path]))->handle();
        expect(Storage::disk('certificates')->exists($path))->toBeFalse();
    });

    it('deletes the rows and the pet\'s PDF directory with the family', function () {
        [$parent, $child, $pet] = certFamily();
        certPlant($pet, $child, 86, 14);
        certTick(CERT_DONE);
        certGenerate($parent, certRow($pet, $child))->assertOk();

        app(AccountDeletionService::class)->deleteParentAccount($parent, true);

        expect(ChallengeCertificate::count())->toBe(0);
        Queue::assertPushed(DeleteCertificateFiles::class, fn (DeleteCertificateFiles $job) => $job->petIds === [$pet->id]);
        (new DeleteCertificateFiles([$pet->id]))->handle();
        expect(Storage::disk('certificates')->allFiles())->toBe([]);
    });

    it('exports the certificates with the printed names and a signed PDF URL', function () {
        [$parent, $child, $pet] = certFamily();
        certPlant($pet, $child, 86, 14);
        certTick(CERT_DONE);
        certGenerate($parent, certRow($pet, $child), ['child_name' => 'Maja'])->assertOk();

        $export = app(AccountExportService::class)->exportFor($parent);
        $certs = collect($export['pets'])->firstWhere('id', $pet->id)['certificates'];

        expect($certs)->toHaveCount(1)
            ->and($certs[0])->toMatchArray(['child_id' => $child->id, 'outcome' => 'certificate', 'care_score' => 86,
                'number' => 'PP-2026-000001', 'child_name' => 'Maja', 'pet_name' => 'Luna', 'locale' => 'sl'])
            ->and($certs[0]['pdf_url'])->toContain('/api/certificates/');
    });
});

describe('texts', function () {
    it('has a breed name in every language for every breed', function () {
        foreach (['en', 'sl'] as $locale) {
            foreach (BreedType::cases() as $breed) {
                expect(ChallengeCertificateService::breedLabel($breed->value, $locale))->not->toBe($breed->value);
            }
        }
    });

    it('has the same certificate keys in English and Slovenian', function () {
        $en = require lang_path('en/certificate.php');
        $sl = require lang_path('sl/certificate.php');
        expect(array_keys($sl))->toBe(array_keys($en))
            ->and($sl['eyebrow'])->toBe('12-TEDENSKI PETPREP IZZIV')
            ->and(CertificatePdfRenderer::formatDate('2026-10-12', 'sl'))->toBe('12. 10. 2026')
            ->and(CertificatePdfRenderer::formatDate('2026-10-12', 'en'))->toBe('12 Oct 2026');
    });
});
