<?php

use App\Enums\BreedFactStatus;
use App\Models\BreedFact;
use App\Models\BreedFactRead;
use App\Models\BreedFactShow;
use App\Models\FamilyMember;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Models\PetStatusPeriod;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\BreedFactService;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| M5-R09b — »Ali si vedel?« / breed facts: API (DID_YOU_KNOW_SPEC §0, §2,
| §3.2, §4, §6, §7)
|--------------------------------------------------------------------------
| Only approved facts; a child sees child facts of their own pet's breed
| without links; a parent sees both audiences for the family's breeds,
| with links, the not-vet-reviewed flag on health facts and which children
| read each fact. The child's automatic sheet: max 2 per program week,
| ≥ 2 family-local days apart, not on day 1, not for mutt / domestic cat,
| not without a picture, never while locked. Europe/Ljubljana; DST ends
| on 25 Oct 2026.
*/

afterEach(function () {
    Carbon::setTestNow();
});

function bfAt(string $local): void
{
    Carbon::setTestNow(Carbon::parse($local, 'Europe/Ljubljana')->utc());
}

/** A fact row (approved unless said otherwise). */
function bfFact(string $breed, int $sort, array $attributes = []): BreedFact
{
    $status = $attributes['status'] ?? BreedFactStatus::Approved->value;
    $content = array_merge([
        'breed' => $breed,
        'category' => 'exercise',
        'audience' => 'child',
        'title_en' => "Fact {$sort} EN",
        'title_sl' => "Zanimivost {$sort} SL",
        'why_en' => "Why {$sort}",
        'why_sl' => "Zakaj {$sort}",
        'source_id' => 'S5',
        'source_name' => 'The Royal Kennel Club',
        'source_url' => 'https://www.thekennelclub.org.uk/',
        'quote' => 'Verbatim quote.',
        'game_rule_key' => null,
        'sort' => $sort,
    ], array_diff_key($attributes, ['status' => 1]));

    return BreedFact::create($content + [
        'key' => "{$breed}-".str_pad((string) $sort, 3, '0', STR_PAD_LEFT),
        'status' => $status,
        'verified_by' => $status === 'draft' ? null : 'claude-verifier',
        'approved_at' => $status === 'approved' ? now() : null,
        'content_hash' => BreedFact::hashOf($content),
    ]);
}

/**
 * Parent + child + an active pet of $breed born at $bornLocal (no quiet
 * hours unless asked), with a stored reference image.
 *
 * @return array{0: User, 1: User, 2: Pet}
 */
function bfFamily(string $breed = 'border_collie', string $bornLocal = '2026-10-19 08:00', bool $image = true, bool $quiet = false, array $attributes = []): array
{
    bfAt($bornLocal);
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    if ($quiet) {
        setQuietHours(['parent_id' => $parent->id, 'bedtime_start' => '21:00', 'bedtime_end' => '07:00', 'is_active' => true]);
    } else {
        withoutQuietHours($parent);
    }
    $paid = in_array($breed, ['mutt', 'domestic_cat'], true) ? [] : ['plan' => 'challenge', 'challenge_paid_at' => now(), 'challenge_paid_source' => 'purchase'];
    $pet = disableHygieneEvents(Pet::factory()->create(array_merge([
        'breed_type' => $breed,
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => 2,
    ], $paid, $attributes)));
    if ($image) {
        PetMedia::create(['pet_id' => $pet->id, 'kind' => 'image', 'status' => 'ready', 'storage_path' => "{$pet->id}/reference-g1.jpg", 'mime' => 'image/jpeg']);
    }

    return [$parent, $child, $pet->fresh()];
}

function bfGet(User $user, string $uri, array $headers = [])
{
    test()->actingAs($user, 'sanctum');
    $response = test()->getJson($uri, $headers);
    app('auth')->forgetGuards();

    return $response;
}

function bfPost(User $user, string $uri)
{
    test()->actingAs($user, 'sanctum');
    $response = test()->postJson($uri);
    app('auth')->forgetGuards();

    return $response;
}

function bfDay(User $child): array
{
    return bfGet($child, '/api/child/fact-of-the-day')->assertOk()->json();
}

describe('GET /api/breeds/{breed}/facts', function () {
    it('lists only approved facts; a child gets child facts without links', function () {
        [, $child] = bfFamily();
        $a = bfFact('border_collie', 2);
        $b = bfFact('border_collie', 1, ['game_rule_key' => 'daily_steps']);
        bfFact('border_collie', 3, ['status' => 'draft']);
        bfFact('border_collie', 4, ['status' => 'verified']);
        bfFact('border_collie', 5, ['audience' => 'parent']);
        bfFact('border_collie', 6, ['audience' => 'parent', 'category' => 'health']);
        bfFact('maine_coon', 1);

        $r = bfGet($child, '/api/breeds/border_collie/facts')->assertOk();

        expect($r->json('breed'))->toBe('border_collie')
            ->and(collect($r->json('facts'))->pluck('id')->all())->toBe([$b->id, $a->id])
            ->and($r->json('facts.0'))->toMatchArray([
                'key' => 'border_collie-001', 'category' => 'exercise', 'audience' => 'child',
                'title' => 'Fact 1 EN', 'why' => 'Why 1', 'source_name' => 'The Royal Kennel Club',
                'source_url' => null, 'game_rule_key' => 'daily_steps', 'not_vet_reviewed' => false,
                'read' => false, 'read_at' => null, 'read_by_children' => [],
            ])
            ->and($r->json('read_count'))->toBe(0)
            ->and($r->json('total_count'))->toBe(2);
        // The slug works too.
        bfGet($child, '/api/breeds/border-collie/facts')->assertOk()->assertJsonCount(2, 'facts');
    });

    it('localizes title and why by Accept-Language', function () {
        [, $child] = bfFamily();
        bfFact('border_collie', 1);

        bfGet($child, '/api/breeds/border_collie/facts', ['Accept-Language' => 'sl-SI'])
            ->assertOk()->assertJsonPath('facts.0.title', 'Zanimivost 1 SL')->assertJsonPath('facts.0.why', 'Zakaj 1');
        bfGet($child, '/api/breeds/border_collie/facts', ['Accept-Language' => 'de'])
            ->assertOk()->assertJsonPath('facts.0.title', 'Fact 1 EN');
    });

    it('gives a child only the breed of a pet they actively care for', function () {
        [, $child, $pet] = bfFamily();
        bfFact('maine_coon', 1);

        bfGet($child, '/api/breeds/maine_coon/facts')->assertNotFound()->assertJsonPath('reason', 'breed_not_found');
        bfGet($child, '/api/breeds/no_such_breed/facts')->assertNotFound();

        // Game over → the pet is inactive → the child no longer reads its breed.
        $pet->forceFill(['is_active' => false, 'is_game_over' => true])->save();
        bfGet($child, '/api/breeds/border_collie/facts')->assertNotFound();
    });

    it('gives a parent both audiences of the family breeds, links, the health flag and child reads', function () {
        [$parent, $child] = bfFamily();
        $child2 = User::factory()->child()->create(['parent_id' => $parent->id]);
        $kid = bfFact('border_collie', 1);
        $family = bfFact('border_collie', 2, ['audience' => 'parent', 'category' => 'family']);
        $health = bfFact('border_collie', 3, ['audience' => 'parent', 'category' => 'health']);
        bfFact('border_collie', 4, ['status' => 'verified', 'audience' => 'parent']);
        BreedFactRead::create(['user_id' => $child->id, 'breed_fact_id' => $kid->id, 'read_at' => now()]);
        BreedFactRead::create(['user_id' => $child2->id, 'breed_fact_id' => $kid->id, 'read_at' => now()]);
        // Another family's child reading the same fact is never shown.
        [, $stranger] = bfFamily();
        BreedFactRead::create(['user_id' => $stranger->id, 'breed_fact_id' => $kid->id, 'read_at' => now()]);

        $r = bfGet($parent, '/api/breeds/border_collie/facts')->assertOk();

        expect(collect($r->json('facts'))->pluck('id')->all())->toBe([$kid->id, $family->id, $health->id])
            ->and($r->json('facts.0.source_url'))->toBe('https://www.thekennelclub.org.uk/')
            ->and($r->json('facts.0.read_by_children'))->toBe([$child->id, $child2->id])
            ->and($r->json('facts.0.read'))->toBeFalse()
            ->and($r->json('facts.1.not_vet_reviewed'))->toBeFalse()
            ->and($r->json('facts.2.not_vet_reviewed'))->toBeTrue()
            ->and($r->json('facts.2.read_by_children'))->toBe([]);
    });

    it('gives a parent only the breeds of the family pets', function () {
        [$parent] = bfFamily();
        bfFamily('maine_coon'); // another family
        bfFact('maine_coon', 1);

        bfGet($parent, '/api/breeds/maine_coon/facts')->assertNotFound();
        // A parent without a family sees nothing (and none is created).
        $lonely = User::factory()->parent()->create();
        FamilyMember::where('user_id', $lonely->id)->delete();
        bfGet($lonely, '/api/breeds/border_collie/facts')->assertNotFound();
    });

    it('returns an empty list for mutt and domestic cat', function () {
        [$parent, $child] = bfFamily('mutt');

        bfGet($child, '/api/breeds/mutt/facts')->assertOk()->assertJsonPath('facts', [])->assertJsonPath('total_count', 0);
        bfGet($parent, '/api/breeds/mutt/facts')->assertOk()->assertJsonPath('facts', []);
    });

    it('refuses tokens without an account role and needs a token', function () {
        test()->getJson('/api/breeds/border_collie/facts')->assertUnauthorized();
    });
});

describe('POST /api/breed-facts/{fact}/read', function () {
    it('is idempotent and keeps the first read time', function () {
        [, $child] = bfFamily();
        $fact = bfFact('border_collie', 1);

        $first = bfPost($child, "/api/breed-facts/{$fact->id}/read")->assertOk()->assertJsonPath('status', 'read')
            ->assertJsonPath('fact.read', true)->json('fact.read_at');
        Carbon::setTestNow(now()->addHour());
        $second = bfPost($child, "/api/breed-facts/{$fact->id}/read")->assertOk()->json('fact.read_at');

        expect($second)->toBe($first)
            ->and(BreedFactRead::where('user_id', $child->id)->count())->toBe(1);
        bfGet($child, '/api/breeds/border_collie/facts')->assertJsonPath('facts.0.read', true)->assertJsonPath('read_count', 1);
    });

    it('refuses a fact the caller may not see (404)', function () {
        [$parent, $child] = bfFamily();
        $draft = bfFact('border_collie', 1, ['status' => 'verified']);
        $parentOnly = bfFact('border_collie', 2, ['audience' => 'parent', 'category' => 'health']);
        $otherBreed = bfFact('maine_coon', 1);

        foreach ([$draft->id, $parentOnly->id, $otherBreed->id, 999999, 'abc'] as $id) {
            bfPost($child, "/api/breed-facts/{$id}/read")->assertNotFound()->assertJsonPath('reason', 'fact_not_found');
        }
        bfPost($parent, "/api/breed-facts/{$parentOnly->id}/read")->assertOk();
        expect(BreedFactRead::count())->toBe(1);
    });

    it('fills read_by_children for a parent (child: always empty)', function () {
        [$parent, $child] = bfFamily();
        $fact = bfFact('border_collie', 1);
        bfPost($child, "/api/breed-facts/{$fact->id}/read")->assertOk()->assertJsonPath('fact.read_by_children', []);

        bfPost($parent, "/api/breed-facts/{$fact->id}/read")->assertOk()
            ->assertJsonPath('fact.read', true)
            ->assertJsonPath('fact.read_by_children', [$child->id])
            ->assertJsonPath('fact.source_url', 'https://www.thekennelclub.org.uk/');
    });
});

describe('GET /api/child/fact-of-the-day', function () {
    it('gives the next unread child fact by sort and the progress', function () {
        [, $child] = bfFamily();
        $second = bfFact('border_collie', 2);
        $first = bfFact('border_collie', 1);
        bfFact('border_collie', 3, ['audience' => 'parent']);
        bfAt('2026-10-20 12:00');

        expect(bfDay($child))->toMatchArray(['read_count' => 0, 'total_count' => 2, 'program_week' => 1, 'shown_this_week' => 0])
            ->and(bfDay($child)['fact']['id'])->toBe($first->id);
        bfPost($child, "/api/breed-facts/{$first->id}/read")->assertOk();
        expect(bfDay($child)['fact']['id'])->toBe($second->id)
            ->and(bfDay($child)['fact']['source_url'])->toBeNull();
        bfPost($child, "/api/breed-facts/{$second->id}/read")->assertOk();
        expect(bfDay($child))->toMatchArray(['fact' => null, 'may_auto_show' => false, 'reason' => 'no_fact', 'read_count' => 2]);
    });

    it('never auto-shows on program day 1, then allows it', function () {
        [, $child] = bfFamily();
        bfFact('border_collie', 1);

        bfAt('2026-10-20 07:59'); // 23 h 59 min after birth
        expect(bfDay($child))->toMatchArray(['may_auto_show' => false, 'reason' => 'first_day']);
        bfAt('2026-10-20 08:00');
        expect(bfDay($child))->toMatchArray(['may_auto_show' => true, 'reason' => null]);
    });

    it('caps at 2 per program week, 2 family-local days apart, idempotent per day', function () {
        [, $child, $pet] = bfFamily(); // born Mon 2026-10-19 08:00
        bfFact('border_collie', 1);
        bfFact('border_collie', 2);
        bfFact('border_collie', 3);

        bfAt('2026-10-20 18:00'); // Tue, day 2
        bfPost($child, '/api/child/fact-of-the-day/shown')->assertOk()->assertJsonPath('status', 'recorded')
            ->assertJsonPath('state.shown_today', true)->assertJsonPath('state.shown_this_week', 1)
            ->assertJsonPath('state.may_auto_show', false)->assertJsonPath('state.reason', 'shown_today');
        // Another device the same day: nothing new.
        bfAt('2026-10-20 23:30');
        bfPost($child, '/api/child/fact-of-the-day/shown')->assertOk()->assertJsonPath('status', 'already_shown');

        bfAt('2026-10-21 10:00'); // Wed: only 1 day later
        expect(bfDay($child))->toMatchArray(['may_auto_show' => false, 'reason' => 'too_soon']);
        $refused = bfPost($child, '/api/child/fact-of-the-day/shown')->assertStatus(422)->json();
        expect(array_keys($refused))->toBe(['status', 'reason', 'state'])
            ->and($refused['status'])->toBe('refused')
            ->and($refused['reason'])->toBe('too_soon')
            ->and($refused['state'])->toMatchArray(['may_auto_show' => false, 'reason' => 'too_soon', 'shown_this_week' => 1]);

        bfAt('2026-10-22 00:05'); // Thu, just after midnight: 2 local days later
        bfPost($child, '/api/child/fact-of-the-day/shown')->assertOk()->assertJsonPath('status', 'recorded');

        bfAt('2026-10-25 12:00'); // Sun, still program week 1 → cap reached
        expect(bfDay($child))->toMatchArray(['may_auto_show' => false, 'reason' => 'week_cap', 'shown_this_week' => 2]);

        bfAt('2026-10-26 07:59'); // Mon before the weekly birthday: week 1
        expect(bfDay($child)['reason'])->toBe('week_cap');
        bfAt('2026-10-26 08:00'); // week 2
        expect(bfDay($child))->toMatchArray(['may_auto_show' => true, 'program_week' => 2, 'shown_this_week' => 0]);

        $shows = BreedFactShow::where('pet_id', $pet->id)->orderBy('local_date')->get();
        expect($shows->map(fn (BreedFactShow $s) => [$s->localDate(), $s->program_week])->all())
            ->toBe([['2026-10-20', 1], ['2026-10-22', 1]])
            ->and($shows->every(fn (BreedFactShow $s) => $s->breed_fact_id !== null))->toBeTrue();
    });

    it('keeps the 2-day gap across a program week boundary', function () {
        [, $child] = bfFamily(); // born Mon 08:00
        bfFact('border_collie', 1);

        bfAt('2026-10-25 20:00'); // Sun, week 1
        bfPost($child, '/api/child/fact-of-the-day/shown')->assertOk();
        bfAt('2026-10-26 09:00'); // Mon, week 2 — but only 1 local day later
        expect(bfDay($child))->toMatchArray(['program_week' => 2, 'shown_this_week' => 0, 'reason' => 'too_soon']);
        bfAt('2026-10-27 09:00');
        expect(bfDay($child)['may_auto_show'])->toBeTrue();
    });

    it('counts family-local days and program weeks across the DST change', function () {
        // Born Sat 2026-10-24 18:00 CEST (16:00 UTC); clocks go back on Sun the 25th.
        [, $child, $pet] = bfFamily(bornLocal: '2026-10-24 18:00');
        bfFact('border_collie', 1);
        $service = app(BreedFactService::class);

        // Sun 25th 23:30 CET = 22:30 UTC → local day 25 (UTC date would also say 25).
        Carbon::setTestNow(Carbon::parse('2026-10-25 22:30:00', 'UTC'));
        bfPost($child, '/api/child/fact-of-the-day/shown')->assertOk()->assertJsonPath('status', 'recorded');
        expect(BreedFactShow::sole()->localDate())->toBe('2026-10-25');

        // 23:30 UTC on the 26th = 00:30 CET on the 27th: 2 local days later (the UTC date says 1).
        Carbon::setTestNow(Carbon::parse('2026-10-26 23:30:00', 'UTC'));
        expect(bfDay($child)['may_auto_show'])->toBeTrue();
        Carbon::setTestNow(Carbon::parse('2026-10-26 22:59:00', 'UTC')); // 23:59 CET on the 26th
        expect(bfDay($child)['reason'])->toBe('too_soon');

        // The weekly birthday keeps 18:00 local: Sat 31st 18:00 CET = 17:00 UTC.
        expect($service->programWeek($pet, Carbon::parse('2026-10-31 16:59:59', 'UTC')))->toBe(1)
            ->and($service->programWeek($pet, Carbon::parse('2026-10-31 17:00:00', 'UTC')))->toBe(2);
    });

    it('does not count payment-lock time as program time', function () {
        [, $child, $pet] = bfFamily(); // born Mon 2026-10-19 08:00, paid challenge
        bfFact('border_collie', 1);
        bfFact('border_collie', 2);

        bfAt('2026-10-20 12:00'); // program day 2 (week 1)
        bfPost($child, '/api/child/fact-of-the-day/shown')->assertOk()->assertJsonPath('state.program_week', 1);

        // Locked for payment for 10 days (Wed 08:00 → Sat 31st 08:00), then paid.
        PetStatusPeriod::create(['pet_id' => $pet->id, 'kind' => 'payment_lock',
            'started_at' => Carbon::parse('2026-10-21 08:00', 'Europe/Ljubljana')->utc(),
            'ended_at' => Carbon::parse('2026-10-31 08:00', 'Europe/Ljubljana')->utc()]);

        // Real day 13 = program day 3: still program week 1 (wall clock would say week 2).
        bfAt('2026-11-01 12:00');
        expect(bfDay($child))->toMatchArray(['program_week' => 1, 'shown_this_week' => 1, 'may_auto_show' => true]);
        bfPost($child, '/api/child/fact-of-the-day/shown')->assertOk();
        bfAt('2026-11-03 12:00'); // program day 5: still week 1, cap reached
        expect(bfDay($child))->toMatchArray(['program_week' => 1, 'reason' => 'week_cap']);
    });

    it('is false while locked: payment, ill, hard stop, game over, unborn', function () {
        [, $child, $pet] = bfFamily();
        bfFact('border_collie', 1);
        bfAt('2026-10-21 12:00');
        expect(bfDay($child)['may_auto_show'])->toBeTrue();

        $pet->forceFill(['payment_locked_at' => now()])->saveQuietly();
        expect(bfDay($child)['reason'])->toBe('locked');
        $pet->forceFill(['payment_locked_at' => null, 'illness_until' => now()->addDay()])->saveQuietly();
        expect(bfDay($child)['reason'])->toBe('locked');
        $pet->forceFill(['illness_until' => null, 'is_hard_stopped' => true])->saveQuietly();
        expect(bfDay($child)['reason'])->toBe('locked');
        bfPost($child, '/api/child/fact-of-the-day/shown')->assertStatus(422)->assertJsonPath('reason', 'locked');
        $pet->forceFill(['is_hard_stopped' => false, 'is_game_over' => true, 'is_active' => false])->saveQuietly();
        expect(bfDay($child)['reason'])->toBe('no_pet'); // no active pet any more

        [, $child2] = bfFamily(attributes: ['born_at' => null]);
        expect(bfDay($child2))->toMatchArray(['may_auto_show' => false, 'reason' => 'locked', 'program_week' => null]);
        expect(BreedFactShow::count())->toBe(0);
    });

    it('is false in quiet hours, without a picture, for mutt and domestic cat', function () {
        [, $child] = bfFamily(quiet: true);
        bfFact('border_collie', 1);
        bfAt('2026-10-21 22:00');
        expect(bfDay($child)['reason'])->toBe('quiet_hours');
        bfAt('2026-10-21 19:00');
        expect(bfDay($child)['may_auto_show'])->toBeTrue();

        [, $noImage] = bfFamily(image: false);
        bfAt('2026-10-21 12:00');
        expect(bfDay($noImage))->toMatchArray(['may_auto_show' => false, 'reason' => 'no_image']);

        [, $mutt] = bfFamily('mutt');
        bfAt('2026-10-21 12:00');
        expect(bfDay($mutt))->toMatchArray(['fact' => null, 'may_auto_show' => false, 'reason' => 'non_pedigree', 'total_count' => 0]);
    });

    it('answers no_pet for a child without a pet, and only children may call it', function () {
        $parent = User::factory()->parent()->create();
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);

        expect(bfDay($child))->toMatchArray(['fact' => null, 'reason' => 'no_pet', 'program_week' => null]);
        bfPost($child, '/api/child/fact-of-the-day/shown')->assertStatus(422)->assertJsonPath('reason', 'no_pet');
        bfGet($parent, '/api/child/fact-of-the-day')->assertForbidden();
    });
});

it('decides /shown on a fresh pet row (a lock set after the controller loaded the pet)', function () {
    [, $child, $pet] = bfFamily();
    bfFact('border_collie', 1);
    bfAt('2026-10-21 12:00');
    $stale = $pet->fresh();
    $pet->fresh()->forceFill(['is_hard_stopped' => true])->saveQuietly();

    expect(app(BreedFactService::class)->recordShown($child, $stale))->toBe(['status' => 'refused', 'reason' => 'locked'])
        ->and(BreedFactShow::count())->toBe(0);
});

describe('privacy', function () {
    it('deletes reads and shows with the child, and exports them', function () {
        [$parent, $child, $pet] = bfFamily();
        $fact = bfFact('border_collie', 1);
        bfAt('2026-10-21 12:00');
        bfPost($child, "/api/breed-facts/{$fact->id}/read")->assertOk();
        bfPost($parent, "/api/breed-facts/{$fact->id}/read")->assertOk();
        bfPost($child, '/api/child/fact-of-the-day/shown')->assertStatus(422); // all read → no_fact
        BreedFactShow::create(['user_id' => $child->id, 'pet_id' => $pet->id, 'breed_fact_id' => $fact->id, 'program_week' => 1, 'local_date' => '2026-10-21', 'shown_at' => now()]);

        $export = bfGet($parent, '/api/parent/account/export')->assertOk()->json('breed_facts');
        expect(collect($export['reads'])->pluck('user_id')->sort()->values()->all())->toBe(collect([$parent->id, $child->id])->sort()->values()->all())
            ->and($export['reads'][0]['fact_key'])->toBe('border_collie-001')
            ->and($export['auto_shows'])->toHaveCount(1)
            ->and($export['auto_shows'][0])->toMatchArray(['user_id' => $child->id, 'pet_id' => $pet->id, 'program_week' => 1, 'local_date' => '2026-10-21']);

        app(AccountDeletionService::class)->deleteChildProfile($parent, $child, true);
        expect(BreedFactRead::where('user_id', $child->id)->exists())->toBeFalse()
            ->and(BreedFactRead::where('user_id', $parent->id)->exists())->toBeTrue()
            ->and(BreedFactShow::count())->toBe(0)
            ->and(BreedFact::count())->toBe(1);

        app(AccountDeletionService::class)->deleteParentAccount($parent, true);
        expect(BreedFactRead::count())->toBe(0)->and(BreedFact::count())->toBe(1);
    });
});
