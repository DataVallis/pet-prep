<?php

use App\Filament\Resources\BreedQuizStatResource;
use App\Filament\Resources\BreedQuizStatResource\Pages\ListBreedQuizStats;
use App\Models\BreedQuizStat;
use App\Models\User;
use App\Services\GameDataResetService;
use App\Support\WebsiteLocale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| M6-S06 — anonymous counters of the petprep.si breed quiz (BREED_QUIZ_SPEC §8)
|--------------------------------------------------------------------------
|
| POST /api/breed-quiz/events: public, 60 / hour per IP, honeypot `website`
| (204, nothing stored), 204 on success, 422 on invalid input. One counter row
| per (UTC day, event, breed, rank, locale); rank is NULL for clicks and the
| unique key is NULLS NOT DISTINCT. No IP, no cookie, nothing else stored.
|
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-16 10:00:00', 'UTC'));
    Http::preventStrayRequests();
});

/** @return array<string, mixed> */
function bqBody(array $overrides = []): array
{
    return array_merge([
        'event' => 'completed',
        'locale' => 'sl',
        'breeds' => ['labrador_retriever', 'beagle', 'poodle'],
        'website' => '',
    ], $overrides);
}

function bqAdmin(): User
{
    return User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
}

describe('POST /api/breed-quiz/events', function () {
    it('counts a completed quiz: one row per breed with its rank, 204 without a token', function () {
        $this->postJson('/api/breed-quiz/events', bqBody())->assertNoContent();

        $rows = BreedQuizStat::orderBy('rank')->get();
        expect($rows)->toHaveCount(3)
            ->and($rows->pluck('breed')->all())->toBe(['labrador_retriever', 'beagle', 'poodle'])
            ->and($rows->pluck('rank')->all())->toBe([1, 2, 3])
            ->and($rows->pluck('count')->unique()->all())->toBe([1])
            ->and($rows->pluck('event')->unique()->all())->toBe(['completed'])
            ->and($rows->pluck('locale')->unique()->all())->toBe(['sl'])
            ->and($rows->first()->day->toDateString())->toBe('2026-11-16');
    });

    it('accepts 1 or 2 breeds for completed (a shorter result list)', function () {
        $this->postJson('/api/breed-quiz/events', bqBody(['breeds' => ['beagle']]))->assertNoContent();
        $this->postJson('/api/breed-quiz/events', bqBody(['breeds' => ['pug', 'akita']]))->assertNoContent();

        expect(BreedQuizStat::count())->toBe(3);
    });

    it('counts a click with rank NULL', function (string $event) {
        $this->postJson('/api/breed-quiz/events', bqBody(['event' => $event, 'breeds' => ['beagle']]))->assertNoContent();

        $row = BreedQuizStat::sole();
        expect($row->event)->toBe($event)->and($row->breed)->toBe('beagle')->and($row->rank)->toBeNull()->and($row->count)->toBe(1);
    })->with(['calculator_click', 'app_click']);

    it('accepts unknown breed ids (no DB lookup) and every website locale', function () {
        foreach (WebsiteLocale::ALL as $locale) {
            $this->postJson('/api/breed-quiz/events', bqBody(['event' => 'app_click', 'locale' => $locale, 'breeds' => ['not_a_real_breed_99']]))
                ->assertNoContent();
        }

        expect(BreedQuizStat::count())->toBe(15);
    });

    it('upsert increments the same counter (clicks with NULL rank included) and never adds a second row', function () {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/breed-quiz/events', bqBody(['event' => 'app_click', 'breeds' => ['beagle']]))->assertNoContent();
        }
        $this->postJson('/api/breed-quiz/events', bqBody())->assertNoContent();
        $this->postJson('/api/breed-quiz/events', bqBody(['breeds' => ['beagle', 'labrador_retriever']]))->assertNoContent();

        expect(BreedQuizStat::where('event', 'app_click')->sole()->count)->toBe(3);
        // beagle was rank 2 once and rank 1 once; labrador rank 1 once and rank 2 once.
        expect(BreedQuizStat::where(['event' => 'completed', 'breed' => 'beagle', 'rank' => 2])->sole()->count)->toBe(1)
            ->and(BreedQuizStat::where(['event' => 'completed', 'breed' => 'labrador_retriever', 'rank' => 1])->sole()->count)->toBe(1)
            ->and(BreedQuizStat::where(['event' => 'completed', 'breed' => 'poodle', 'rank' => 3])->sole()->count)->toBe(1);

        $this->postJson('/api/breed-quiz/events', bqBody())->assertNoContent();
        expect(BreedQuizStat::where(['event' => 'completed', 'breed' => 'poodle', 'rank' => 3])->sole()->count)->toBe(2)
            ->and(BreedQuizStat::count())->toBe(1 + 5);
    });

    it('keeps separate rows per day (UTC), locale and event', function () {
        $this->postJson('/api/breed-quiz/events', bqBody(['event' => 'app_click', 'breeds' => ['beagle']]))->assertNoContent();
        $this->postJson('/api/breed-quiz/events', bqBody(['event' => 'app_click', 'breeds' => ['beagle'], 'locale' => 'en']))->assertNoContent();
        $this->postJson('/api/breed-quiz/events', bqBody(['event' => 'calculator_click', 'breeds' => ['beagle']]))->assertNoContent();
        // 23:30 in UTC is still the 16th, 00:30 UTC is the 17th (the day is the UTC date).
        Carbon::setTestNow(Carbon::parse('2026-11-16 23:59:00', 'UTC'));
        $this->postJson('/api/breed-quiz/events', bqBody(['event' => 'app_click', 'breeds' => ['beagle']]))->assertNoContent();
        Carbon::setTestNow(Carbon::parse('2026-11-17 00:30:00', 'UTC'));
        $this->postJson('/api/breed-quiz/events', bqBody(['event' => 'app_click', 'breeds' => ['beagle']]))->assertNoContent();

        expect(BreedQuizStat::count())->toBe(4)
            ->and(BreedQuizStat::where(['event' => 'app_click', 'locale' => 'sl'])->orderBy('day')->pluck('count', 'day')->all())->toHaveCount(2);
        expect(BreedQuizStat::where(['event' => 'app_click', 'locale' => 'sl'])->whereDate('day', '2026-11-16')->sole()->count)->toBe(2);
    });

    it('answers 422 for an invalid body and stores nothing', function (array $override, string $field) {
        $this->postJson('/api/breed-quiz/events', bqBody($override))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        expect(BreedQuizStat::count())->toBe(0);
    })->with([
        'no event' => [['event' => null], 'event'],
        'unknown event' => [['event' => 'view'], 'event'],
        'no locale' => [['locale' => null], 'locale'],
        'unknown locale' => [['locale' => 'ja'], 'locale'],
        'upper-case locale' => [['locale' => 'SL'], 'locale'],
        'no breeds' => [['breeds' => null], 'breeds'],
        'empty breeds' => [['breeds' => []], 'breeds'],
        'breeds not an array' => [['breeds' => 'beagle'], 'breeds'],
        'completed with 4 breeds' => [['breeds' => ['a', 'b', 'c', 'd']], 'breeds'],
        'click with 2 breeds' => [['event' => 'app_click', 'breeds' => ['a', 'b']], 'breeds'],
        'click with 0 breeds' => [['event' => 'calculator_click', 'breeds' => []], 'breeds'],
        'duplicate breed in the top 3' => [['breeds' => ['beagle', 'beagle']], 'breeds.1'],
        'breed with upper case' => [['breeds' => ['Beagle']], 'breeds.0'],
        'breed with a dash' => [['breeds' => ['cavalier-king']], 'breeds.0'],
        'breed with markup' => [['breeds' => ['<script>']], 'breeds.0'],
        'breed too long' => [['breeds' => [str_repeat('a', 65)]], 'breeds.0'],
        'breed empty' => [['breeds' => ['']], 'breeds.0'],
        'breed not a string' => [['breeds' => [['x']]], 'breeds.0'],
    ]);

    it('accepts a breed id exactly 64 characters long', function () {
        $this->postJson('/api/breed-quiz/events', bqBody(['event' => 'app_click', 'breeds' => [str_repeat('a', 64)]]))->assertNoContent();

        expect(BreedQuizStat::count())->toBe(1);
    });

    it('honeypot: a filled `website` gets 204 and nothing is stored (also with an invalid body)', function () {
        $this->postJson('/api/breed-quiz/events', bqBody(['website' => 'https://spam.example']))->assertNoContent();
        $this->postJson('/api/breed-quiz/events', ['website' => 'x'])->assertNoContent();
        $this->postJson('/api/breed-quiz/events', bqBody(['website' => ['x']]))->assertNoContent();

        expect(BreedQuizStat::count())->toBe(0);
    });

    it('is throttled to 60 per hour per IP (429), another IP is not', function () {
        for ($i = 1; $i <= 60; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
                ->postJson('/api/breed-quiz/events', bqBody(['event' => 'app_click', 'breeds' => ['beagle']]))->assertNoContent();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/api/breed-quiz/events', bqBody(['event' => 'app_click', 'breeds' => ['beagle']]))->assertStatus(429);
        expect(BreedQuizStat::sole()->count)->toBe(60);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson('/api/breed-quiz/events', bqBody(['event' => 'app_click', 'breeds' => ['beagle']]))->assertNoContent();
    });

    it('shares one throttle bucket per IPv6 /64', function () {
        for ($i = 1; $i <= 60; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2::'.dechex($i)])
                ->postJson('/api/breed-quiz/events', bqBody(['event' => 'app_click', 'breeds' => ['beagle']]))->assertNoContent();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2:ffff::1'])
            ->postJson('/api/breed-quiz/events', bqBody(['event' => 'app_click', 'breeds' => ['beagle']]))->assertStatus(429);
    });

    it('stores nothing about the sender (no IP column, no IP value in any row) and queues / logs nothing', function () {
        Queue::fake();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])
            ->withHeaders(['User-Agent' => 'QuizUA/1.0'])
            ->postJson('/api/breed-quiz/events', bqBody())->assertNoContent();

        expect(Schema::getColumnListing('breed_quiz_stats'))
            ->toBe(['id', 'day', 'event', 'breed', 'rank', 'locale', 'count', 'created_at', 'updated_at']);
        expect(json_encode(BreedQuizStat::all()->toArray()))->not->toContain('198.51.100.77')->not->toContain('QuizUA');
        Queue::assertNothingPushed();
    });

    it('allows the petprep.si origin (CORS preflight and the real response)', function () {
        $pre = $this->call('OPTIONS', '/api/breed-quiz/events', [], [], [], [
            'HTTP_ORIGIN' => 'https://petprep.si',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
        ]);
        expect($pre->headers->get('Access-Control-Allow-Origin'))->toBeIn(['*', 'https://petprep.si']);

        $res = $this->withHeaders(['Origin' => 'https://petprep.si'])->postJson('/api/breed-quiz/events', bqBody());
        $res->assertNoContent();
        expect($res->headers->get('Access-Control-Allow-Origin'))->toBeIn(['*', 'https://petprep.si']);
    });
});

describe('database', function () {
    it('treats two NULL ranks as the same key (NULLS NOT DISTINCT) and refuses bad values', function () {
        $row = ['day' => '2026-11-16', 'event' => 'app_click', 'breed' => 'beagle', 'rank' => null, 'locale' => 'sl', 'count' => 1];
        BreedQuizStat::create($row);

        expect(fn () => BreedQuizStat::create($row))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
        expect(fn () => BreedQuizStat::create([...$row, 'event' => 'view']))->toThrow(Illuminate\Database\QueryException::class);
        expect(fn () => BreedQuizStat::create([...$row, 'event' => 'completed', 'rank' => 4]))->toThrow(Illuminate\Database\QueryException::class);
    });

    it('is kept by the game data reset', function () {
        expect(GameDataResetService::KEEP)->toContain('breed_quiz_stats');
    });
});

describe('/admin', function () {
    it('lists the counters for a superadmin, filtered by event', function () {
        $click = BreedQuizStat::create(['day' => '2026-11-16', 'event' => 'app_click', 'breed' => 'beagle', 'rank' => null, 'locale' => 'sl', 'count' => 4]);
        $done = BreedQuizStat::create(['day' => '2026-11-15', 'event' => 'completed', 'breed' => 'pug', 'rank' => 1, 'locale' => 'en', 'count' => 2]);
        $this->actingAs(bqAdmin());

        Livewire::test(ListBreedQuizStats::class)
            ->assertCanSeeTableRecords([$click, $done])
            ->filterTable('event', 'app_click')
            ->assertCanSeeTableRecords([$click])
            ->assertCanNotSeeTableRecords([$done])
            ->removeTableFilter('event')
            ->filterTable('day', ['from' => '2026-11-16', 'until' => null])
            ->assertCanSeeTableRecords([$click])
            ->assertCanNotSeeTableRecords([$done]);
    });

    it('refuses everyone but a superadmin on the route and has no create, edit or delete', function () {
        $this->get('/admin/breed-quiz-stats')->assertRedirect();

        $this->actingAs(User::factory()->create(['role' => 'parent', 'is_superadmin' => false]));
        $this->get('/admin/breed-quiz-stats')->assertForbidden();
        expect(BreedQuizStatResource::canAccess())->toBeFalse();

        $this->actingAs(bqAdmin());
        $this->get('/admin/breed-quiz-stats')->assertOk();
        expect(BreedQuizStatResource::canAccess())->toBeTrue()
            ->and(BreedQuizStatResource::canCreate())->toBeFalse()
            ->and(BreedQuizStatResource::canDeleteAny())->toBeFalse();
    });
});
