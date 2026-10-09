<?php

use App\Enums\ActivityType;
use App\Enums\BreedType;
use App\Enums\HygieneEventKind;
use App\Enums\HygieneEventStatus;
use App\Enums\PushType;
use App\Enums\Species;
use App\Exceptions\ChallengeException;
use App\Jobs\SendPushNotification;
use App\Models\ActivityLog;
use App\Models\DevicePushToken;
use App\Models\Pet;
use App\Models\PetHygieneEvent;
use App\Models\PushNotification;
use App\Models\User;
use App\Services\AccountExportService;
use App\Services\ChallengeCreditService;
use App\Services\NotificationService;
use App\Services\Push\ExpoPushClient;
use App\Services\Push\PushCopy;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| M5-R06-06 — push and server texts per species (plan T8, CAT_SPEC §6 / §9)
|--------------------------------------------------------------------------
|
| A cat gets its own EN + SL texts (`push.cat.*`; Slovenian "muca" is
| feminine — "muca je lačna", "bo zbolela"), the dog keeps its texts
| byte-for-byte (DogPushTextSnapshotTest). Keys with a dog noun need a cat
| text or are explicitly dog-only. M3-12: while only a scratching is open,
| food / water reminders send the child to the scratcher, never "clean first"
| (cleaning does not resolve a scratching — QA m3 of R06-05).
*/

/** Keys whose text names the dog but that a cat can never be sent (walk, chewing). */
const CPT_DOG_ONLY = [
    'walk_reminder',
    'tidy.soft', 'tidy.critical',
    'clean_and_tidy.soft', 'clean_and_tidy.critical',
    'illness.child.walk', 'illness.parent.walk',
];

/** A dog noun (EN) or a Slovenian dog noun / masculine agreement that must not reach a cat. */
function cptDogWords(string $locale): string
{
    return $locale === 'sl'
        ? '/(?<!\p{L})(kuž\p{L}*|pes|psa|psu|psom|psi|mladič\p{L}*|zbolel|lačen|žejen|živel|odšel|dobil|bil)(?!\p{L})/iu'
        : '/\b(dog|dogs|puppy|puppies|pup|lead)\b|dog’s/i';
}

/** @return list<string> */
function cptLocales(): array
{
    return config('locales.supported');
}

/** @return array<string, string> */
function cptKeys(string $locale): array
{
    return Arr::dot(require lang_path("{$locale}/push.php"));
}

function cptAt(string $local): void
{
    Carbon::setTestNow(Carbon::parse($local, 'Europe/Ljubljana')->utc());
}

/**
 * A family with a born young domestic cat (no quiet hours) at $local — by default
 * 08:00, inside the 06–10 feed window with no meal yet (food and water allowed).
 *
 * @return array{0: User, 1: User, 2: Pet}
 */
function cptCatFamily(BreedType $breed = BreedType::DomesticCat, array $attributes = [], string $local = '2026-10-21 08:00'): array
{
    config(['push.enabled' => true]);
    seedLifeStageData();
    cptAt($local);
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    withoutQuietHours($parent);
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Mia']);
    $cat = disableHygieneEvents(Pet::factory()->create(array_merge([
        'breed_type' => $breed->value,
        'user_id' => $child->id,
        'born_at' => Carbon::parse('2026-10-20 08:00', 'Europe/Ljubljana')->utc(),
        'arrival_age_months' => 12,
        'origin' => 'adopted',
        'hunger_level' => 100,
        'thirst_level' => 100,
        'energy_level' => 100,
        'hygiene_level' => 100,
    ], $attributes)));

    return [$parent, $child, $cat->fresh()];
}

/** Open (applied, not cleaned) messes of the given kinds; hygiene shows 0 while any is open. */
function cptOpen(Pet $pet, array $kinds): Pet
{
    foreach ($kinds as $kind) {
        PetHygieneEvent::create(['pet_id' => $pet->id, 'kind' => $kind, 'local_date' => '2026-10-21',
            'scheduled_at' => now()->subMinutes(30), 'status' => HygieneEventStatus::Applied]);
    }
    if ($kinds !== []) {
        Pet::whereKey($pet->id)->update(['hygiene_level' => 0, 'hygiene_zero_since' => now()->subMinutes(30)]);
    }

    return $pet->fresh();
}

/** A meal the child gave at a family-local time (fed_pet row — CareScheduleService's source of truth). */
function cptFed(Pet $pet, User $child, string $local): void
{
    ActivityLog::withoutEvents(fn () => (new ActivityLog)->forceFill([
        'pet_id' => $pet->id, 'actor_user_id' => $child->id, 'activity_type' => ActivityType::FedPet,
        'value' => null, 'created_at' => Carbon::parse($local, 'Europe/Ljubljana')->utc(),
    ])->save());
}

/** NotificationService::actionCopy (M3-12) for a phase reminder of $metric. */
function cptActionCopy(Pet $pet, PushType $type, string $metric): ?array
{
    $row = new PushNotification(['type' => $type, 'metric' => $metric]);

    return (new ReflectionMethod(NotificationService::class, 'actionCopy'))->invoke(app(NotificationService::class), $row, $pet);
}

function cptDevice(User $user, string $locale): DevicePushToken
{
    return DevicePushToken::create([
        'user_id' => $user->id,
        'expo_push_token' => 'ExponentPushToken['.Str::random(22).']',
        'platform' => 'ios',
        'app_version' => '1.0.0',
        'locale' => $locale,
        'last_seen_at' => now(),
    ]);
}

/** Fake Expo; every sent message lands in the returned ArrayObject. */
function cptFakeExpo(): ArrayObject
{
    $sent = new ArrayObject;
    Http::swap(new HttpFactory(app('events')));
    Http::preventStrayRequests();
    Http::fake([
        'https://exp.host/--/api/v2/push/send' => function (Request $request) use ($sent) {
            $tickets = [];
            foreach ($request->data() as $message) {
                $sent->append($message);
                $tickets[] = ['status' => 'ok', 'id' => 'ticket-'.Str::random(10)];
            }

            return Http::response(['data' => $tickets]);
        },
    ]);

    return $sent;
}

/** Queue and deliver one escalation push; returns the bodies by device locale. */
function cptDeliver(Pet $pet, PushType $type, string $metric): array
{
    $sent = cptFakeExpo();
    Queue::fake([SendPushNotification::class]);
    $row = app(NotificationService::class)->escalation($pet, $type, $metric);
    (new SendPushNotification($row->id))->handle(app(NotificationService::class), app(ExpoPushClient::class));

    $locales = DevicePushToken::pluck('locale', 'expo_push_token');

    return collect($sent->getArrayCopy())->mapWithKeys(fn (array $m): array => [$locales[$m['to']] => $m['body']])->all();
}

afterEach(function () {
    Carbon::setTestNow();
});

describe('coverage: every push key that names the dog has a cat text or is dog-only (plan T8)', function () {
    it('gives every key with a dog noun (or Slovenian masculine agreement) a cat variant, unless dog-only', function () {
        foreach (cptLocales() as $locale) {
            $keys = cptKeys($locale);
            $missing = [];
            foreach ($keys as $key => $text) {
                if (str_starts_with($key, 'cat.') || in_array($key, CPT_DOG_ONLY, true)) {
                    continue;
                }
                if (preg_match(cptDogWords($locale), $text) === 1 && ! array_key_exists("cat.{$key}", $keys)) {
                    $missing[] = $key;
                }
            }
            expect($missing)->toBe([], "{$locale}: dog texts without a cat variant");
        }
    });

    it('keeps the dog-only list honest: those keys really name the dog and have no cat text', function () {
        foreach (cptLocales() as $locale) {
            $keys = cptKeys($locale);
            foreach (CPT_DOG_ONLY as $key) {
                expect($keys)->toHaveKey($key)
                    ->not->toHaveKey("cat.{$key}");
            }
        }
        expect(preg_match(cptDogWords('en'), cptKeys('en')['walk_reminder']))->toBe(1);
    });

    it('cat texts never name the dog or use Slovenian masculine agreement, and EN / SL have the same cat keys', function () {
        foreach (cptLocales() as $locale) {
            foreach (cptKeys($locale) as $key => $text) {
                if (! str_starts_with($key, 'cat.')) {
                    continue;
                }
                expect(preg_match(cptDogWords($locale), $text))->toBe(0, "{$locale} {$key}: {$text}")
                    ->and($text)->not->toBe('');
            }
        }
        $catKeys = fn (string $l): array => array_values(array_filter(array_keys(cptKeys($l)), fn (string $k): bool => str_starts_with($k, 'cat.')));
        foreach (cptLocales() as $locale) {
            expect($catKeys($locale))->toBe($catKeys('en'));
        }
        expect($catKeys('en'))
            ->and(count($catKeys('en')))->toBeGreaterThan(30);
    });

    it('has no cat-only group left outside the cat namespace (R06-04 / R06-05 drafts replaced)', function () {
        foreach (cptLocales() as $locale) {
            $top = array_keys(require lang_path("{$locale}/push.php"));
            foreach (PushCopy::CAT_ONLY_GROUPS as $group) {
                expect($top)->not->toContain($group);
            }
        }
    });

    it('QA m3: a language without a cat text falls back to the cat text of the default language, never to the dog text', function () {
        config(['locales.supported' => ['en', 'sl', 'xx']]);
        app('translator')->addLines(['push.soft.hunger' => 'XX dog text'], 'xx');
        Log::spy();

        expect(PushCopy::body(PushType::SoftWarning, 'hunger', 'child', 'xx', null, [], Species::Cat))
            ->toBe('Your cat is giving you a gentle look and sitting by the empty food bowl.')
            ->and(PushCopy::body(PushType::SoftWarning, 'hunger', 'child', 'xx'))->toBe('XX dog text');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx): bool => str_contains($msg, 'cat text missing') && $ctx['locale'] === 'xx')->once();
    });

    it('never puts a name or the child into a cat text (no placeholders but :time)', function () {
        foreach (cptLocales() as $locale) {
            foreach (cptKeys($locale) as $key => $text) {
                if (str_starts_with($key, 'cat.')) {
                    preg_match_all('/:([a-z_]+)/', $text, $m);
                    expect(array_diff($m[1], ['time']))->toBe([], "{$locale} {$key}");
                }
            }
        }
    });
});

describe('rendering per species (EN + SL)', function () {
    it('renders each cat push type in its own words', function (PushType $type, ?string $metric, string $audience, ?string $variant, string $en, string $sl) {
        expect(PushCopy::body($type, $metric, $audience, 'en', $variant, ['time' => '17:00'], Species::Cat))->toBe($en)
            ->and(PushCopy::body($type, $metric, $audience, 'sl', $variant, ['time' => '17:00'], Species::Cat))->toBe($sl);
    })->with([
        'soft hunger' => [PushType::SoftWarning, 'hunger', 'child', null, 'Your cat is giving you a gentle look and sitting by the empty food bowl.', 'Tvoja muca te milo gleda in sedi ob prazni posodi za hrano.'],
        'soft thirst' => [PushType::SoftWarning, 'thirst', 'child', null, 'Your cat is giving you a gentle look and sitting by the empty water bowl.', 'Tvoja muca te milo gleda in sedi ob prazni posodi za vodo.'],
        'soft hygiene' => [PushType::SoftWarning, 'hygiene', 'child', null, 'Your cat is giving you a gentle look — there’s a mess next to the litter tray that needs cleaning up.', 'Tvoja muca te milo gleda — zraven peska je nered, ki ga je treba počistiti.'],
        'critical hunger' => [PushType::CriticalAlert, 'hunger', 'child', null, 'If you don’t feed your cat within 30 minutes, it will get sick.', 'Če muce ne nahraniš v 30 minutah, bo zbolela.'],
        'critical thirst' => [PushType::CriticalAlert, 'thirst', 'child', null, 'If you don’t give your cat water within 30 minutes, it will get sick.', 'Če muci ne daš vode v 30 minutah, bo zbolela.'],
        'critical hygiene' => [PushType::CriticalAlert, 'hygiene', 'child', null, 'Your cat made a mess next to the litter tray! Clean it up as soon as you can, or it will get sick.', 'Muca je naredila nered zraven peska! Počisti ga čim prej, sicer bo zbolela.'],
        'wait hunger' => [PushType::SoftWarning, 'hunger', 'child', PushCopy::VARIANT_WAIT, 'Your cat is getting hungry. The next meal is at 17:00 — don’t forget it.', 'Tvoja muca postaja lačna. Naslednji obrok je ob 17:00 — ne pozabi nanj.'],
        'wait thirst' => [PushType::CriticalAlert, 'thirst', 'child', PushCopy::VARIANT_WAIT, 'Your cat is thirsty. You can give it water again at 17:00 — don’t forget it.', 'Tvoja muca je žejna. Vodo ji lahko spet daš ob 17:00 — ne pozabi nanjo.'],
        'clean first hunger' => [PushType::SoftWarning, 'hunger', 'child', PushCopy::VARIANT_CLEAN_FIRST, 'Your cat is hungry, but the mess has to be cleaned up first. Then you can feed it.', 'Tvoja muca je lačna, a najprej je treba počistiti nered. Potem jo lahko nahraniš.'],
        'clean first thirst' => [PushType::SoftWarning, 'thirst', 'child', PushCopy::VARIANT_CLEAN_FIRST, 'Your cat is thirsty, but the mess has to be cleaned up first. Then you can give it water.', 'Tvoja muca je žejna, a najprej je treba počistiti nered. Potem ji lahko daš vodo.'],
        'scratcher first hunger' => [PushType::SoftWarning, 'hunger', 'child', PushCopy::VARIANT_SCRATCHER_FIRST, 'Your cat is hungry, but first carry it to the scratching post and praise it. Then you can feed it.', 'Tvoja muca je lačna, a najprej jo odnesi na praskalnik in jo pohvali. Potem jo lahko nahraniš.'],
        'scratcher first thirst' => [PushType::CriticalAlert, 'thirst', 'child', PushCopy::VARIANT_SCRATCHER_FIRST, 'Your cat is thirsty, but first carry it to the scratching post and praise it. Then you can give it water.', 'Tvoja muca je žejna, a najprej jo odnesi na praskalnik in jo pohvali. Potem ji lahko daš vodo.'],
        'clean + scratcher first hunger' => [PushType::CriticalAlert, 'hunger', 'child', PushCopy::VARIANT_CLEAN_AND_SCRATCHER_FIRST, 'Your cat is hungry, but first clean up the mess, then carry it to the scratching post and praise it. Then you can feed it.', 'Tvoja muca je lačna, a najprej počisti nered, nato jo odnesi na praskalnik in jo pohvali. Potem jo lahko nahraniš.'],
        'clean + scratcher first thirst' => [PushType::SoftWarning, 'thirst', 'child', PushCopy::VARIANT_CLEAN_AND_SCRATCHER_FIRST, 'Your cat is thirsty, but first clean up the mess, then carry it to the scratching post and praise it. Then you can give it water.', 'Tvoja muca je žejna, a najprej počisti nered, nato jo odnesi na praskalnik in jo pohvali. Potem ji lahko daš vodo.'],
        'clean first, meal later' => [PushType::SoftWarning, 'hunger', 'child', PushCopy::VARIANT_CLEAN_FIRST_WAIT, 'Your cat is hungry, but the mess has to be cleaned up first. The next meal is at 17:00.', 'Tvoja muca je lačna, a najprej je treba počistiti nered. Naslednji obrok je ob 17:00.'],
        'clean first, water later' => [PushType::SoftWarning, 'thirst', 'child', PushCopy::VARIANT_CLEAN_FIRST_WAIT, 'Your cat is thirsty, but the mess has to be cleaned up first. You can give it water again at 17:00.', 'Tvoja muca je žejna, a najprej je treba počistiti nered. Vodo ji lahko spet daš ob 17:00.'],
        'scratcher first, meal later' => [PushType::CriticalAlert, 'hunger', 'child', PushCopy::VARIANT_SCRATCHER_FIRST_WAIT, 'Your cat is hungry, but first carry it to the scratching post and praise it. The next meal is at 17:00.', 'Tvoja muca je lačna, a najprej jo odnesi na praskalnik in jo pohvali. Naslednji obrok je ob 17:00.'],
        'scratcher first, water later' => [PushType::SoftWarning, 'thirst', 'child', PushCopy::VARIANT_SCRATCHER_FIRST_WAIT, 'Your cat is thirsty, but first carry it to the scratching post and praise it. You can give it water again at 17:00.', 'Tvoja muca je žejna, a najprej jo odnesi na praskalnik in jo pohvali. Vodo ji lahko spet daš ob 17:00.'],
        'clean + scratcher first, meal later' => [PushType::SoftWarning, 'hunger', 'child', PushCopy::VARIANT_CLEAN_AND_SCRATCHER_FIRST_WAIT, 'Your cat is hungry, but first clean up the mess, then carry it to the scratching post and praise it. The next meal is at 17:00.', 'Tvoja muca je lačna, a najprej počisti nered, nato jo odnesi na praskalnik in jo pohvali. Naslednji obrok je ob 17:00.'],
        'clean + scratcher first, water later' => [PushType::CriticalAlert, 'thirst', 'child', PushCopy::VARIANT_CLEAN_AND_SCRATCHER_FIRST_WAIT, 'Your cat is thirsty, but first clean up the mess, then carry it to the scratching post and praise it. You can give it water again at 17:00.', 'Tvoja muca je žejna, a najprej počisti nered, nato jo odnesi na praskalnik in jo pohvali. Vodo ji lahko spet daš ob 17:00.'],
        'scratcher soft' => [PushType::SoftWarning, 'hygiene', 'child', PushCopy::VARIANT_SCRATCHER, 'Your cat has scratched the sofa. Carry it to the scratching post and praise it.', 'Tvoja muca je opraskala kavč. Odnesi jo na praskalnik in jo pohvali.'],
        'scratcher critical' => [PushType::CriticalAlert, 'hygiene', 'child', PushCopy::VARIANT_SCRATCHER, 'Your cat scratched the sofa! Carry it to the scratching post and praise it as soon as you can, or it will get sick.', 'Muca je opraskala kavč! Čim prej jo odnesi na praskalnik in jo pohvali, sicer bo zbolela.'],
        'clean + scratcher soft' => [PushType::SoftWarning, 'hygiene', 'child', PushCopy::VARIANT_CLEAN_AND_SCRATCHER, 'Your cat is waiting: clean up the mess, then carry it to the scratching post and praise it.', 'Tvoja muca te čaka: počisti nered, nato jo odnesi na praskalnik in jo pohvali.'],
        'clean + scratcher critical' => [PushType::CriticalAlert, 'hygiene', 'child', PushCopy::VARIANT_CLEAN_AND_SCRATCHER, 'Clean up the mess, carry your cat to the scratching post and praise it as soon as you can, or it will get sick.', 'Čim prej počisti nered, muco odnesi na praskalnik in jo pohvali, sicer bo zbolela.'],
        'play reminder' => [PushType::PlayReminder, 'energy', 'child', null, 'Your cat hasn’t played today and is waiting for the feather wand. Shall we play?', 'Tvoja muca se danes še ni igrala in čaka na palico s peresom. Se greva igrat?'],
        'litter reminder' => [PushType::LitterReminder, 'litter:1', 'child', null, 'Your cat has used the litter tray. Scoop the tray soon, before it starts to smell.', 'Tvoja muca je bila na pesku. Počisti ga čim prej, preden začne smrdeti.'],
        'parent alarm hunger' => [PushType::ParentAlarm, 'hunger', 'parent', null, 'Your child hasn’t looked after the cat today. The cat has had no food for over an hour.', 'Tvoj otrok danes ni poskrbel za muco. Muca je že več kot uro brez hrane.'],
        'parent alarm thirst' => [PushType::ParentAlarm, 'thirst', 'parent', null, 'Your child hasn’t looked after the cat today. The cat has had no water for over an hour.', 'Tvoj otrok danes ni poskrbel za muco. Muca je že več kot uro brez vode.'],
        'parent alarm hygiene' => [PushType::ParentAlarm, 'hygiene', 'parent', null, 'Your child hasn’t looked after the cat today. A mess has not been taken care of for over an hour.', 'Tvoj otrok danes ni poskrbel za muco. Za nered že več kot uro ni nihče poskrbel.'],
        'illness child hygiene' => [PushType::Illness, 'hygiene', 'child', null, 'Your cat lived in a mess for too long and got sick. It will stay at the vet for 12 hours of observation.', 'Muca je predolgo živela v neredu in je zbolela. 12 ur bo na opazovanju pri veterinarju.'],
        'illness child other' => [PushType::Illness, null, 'child', null, 'Your cat got sick. It will stay at the vet for 12 hours of observation.', 'Muca je zbolela. 12 ur bo na opazovanju pri veterinarju.'],
        'illness parent hygiene' => [PushType::Illness, 'hygiene', 'parent', null, 'The cat got sick because a mess wasn’t taken care of. It will stay at the vet for 12 hours of observation.', 'Muca je zbolela, ker za nered ni nihče poskrbel. 12 ur bo na opazovanju pri veterinarju.'],
        'illness parent other' => [PushType::Illness, 'hunger', 'parent', null, 'The cat got sick. It will stay at the vet for 12 hours of observation.', 'Muca je zbolela. 12 ur bo na opazovanju pri veterinarju.'],
        'illness never "walk" for a cat' => [PushType::Illness, 'walk', 'child', null, 'Your cat got sick. It will stay at the vet for 12 hours of observation.', 'Muca je zbolela. 12 ur bo na opazovanju pri veterinarju.'],
        'game over child' => [PushType::GameOver, null, 'child', null, 'Your cat has gone to a shelter because nobody looked after it for too long. Talk to your parents.', 'Muca je odšla v zavetišče, ker zanjo predolgo ni nihče poskrbel. Pogovori se s starši.'],
        'game over parent' => [PushType::GameOver, null, 'parent', null, 'The cat has gone to a shelter because it went 24 hours without essential care. Choose how to continue in the app.', 'Muca je odšla v zavetišče, ker 24 ur ni dobila nujne skrbi. V aplikaciji izberite, kako naprej.'],
        'payment child' => [PushType::PaymentRequired, null, 'child', null, 'The game is waiting for your parent. Your cat is safe and resting.', 'Igra počaka na starša. Tvoja muca je na varnem in počiva.'],
        'payment parent' => [PushType::PaymentRequired, null, 'parent', null, 'The free trial has ended. The cat is waiting safely until you unlock the 12-week challenge in the app.', 'Brezplačni preizkus je končan. Muca varno čaka, dokler v aplikaciji ne odklenete 12-tedenskega izziva.'],
        'payment parent no trial' => [PushType::PaymentRequired, 'no_trial', 'parent', null, 'The cat is waiting safely until you unlock the 12-week challenge in the app.', 'Muca varno čaka, dokler v aplikaciji ne odklenete 12-tedenskega izziva.'],
        'trial ending (no animal)' => [PushType::TrialEnding, null, 'parent', null, 'The free trial ends tomorrow. Unlock the 12-week challenge in the app so the game can go on.', 'Preizkus se izteče jutri. Odklenite 12-tedenski izziv v aplikaciji, da se igra nadaljuje.'],
    ]);

    it('never sends a cat a dog word, whatever the type, metric, audience, variant or language', function () {
        $types = array_values(array_filter(PushType::cases(), fn (PushType $t): bool => $t !== PushType::WalkReminder));
        $variants = [null, PushCopy::VARIANT_WAIT, PushCopy::VARIANT_CLEAN_FIRST, PushCopy::VARIANT_SCRATCHER_FIRST,
            PushCopy::VARIANT_CLEAN_AND_SCRATCHER_FIRST, PushCopy::VARIANT_SCRATCHER, PushCopy::VARIANT_CLEAN_AND_SCRATCHER,
            PushCopy::VARIANT_CLEAN_FIRST_WAIT, PushCopy::VARIANT_SCRATCHER_FIRST_WAIT, PushCopy::VARIANT_CLEAN_AND_SCRATCHER_FIRST_WAIT];
        $checked = 0;
        foreach ($types as $type) {
            foreach ([null, 'hunger', 'thirst', 'hygiene', 'energy', 'walk', 'no_trial'] as $metric) {
                foreach (['child', 'parent'] as $audience) {
                    foreach ([...cptLocales(), null, 'de'] as $locale) {
                        foreach ($variants as $variant) {
                            $body = PushCopy::body($type, $metric, $audience, $locale, $variant, ['time' => '17:00'], Species::Cat);
                            expect($body)->not->toBe('')->not->toStartWith('push.')->not->toContain(':time');
                            expect(preg_match(cptDogWords($locale === 'sl' ? 'sl' : 'en'), $body))->toBe(0, "{$type->value} {$metric} {$audience} {$locale} {$variant}: {$body}");
                            $checked++;
                        }
                    }
                }
            }
        }
        expect($checked)->toBeGreaterThan(1000);
    });

    it('differs from the dog text for every type that names the animal', function () {
        foreach ([PushType::SoftWarning, PushType::CriticalAlert, PushType::ParentAlarm, PushType::Illness, PushType::GameOver, PushType::PaymentRequired] as $type) {
            foreach (cptLocales() as $locale) {
                $audience = $type === PushType::ParentAlarm ? 'parent' : 'child';
                expect(PushCopy::body($type, 'hunger', $audience, $locale, null, [], Species::Cat))
                    ->not->toBe(PushCopy::body($type, 'hunger', $audience, $locale));
            }
        }
    });

    it('agrees in gender in Slovenian: "muca je lačna / žejna", "bo zbolela"', function () {
        $sl = fn (PushType $t, string $m, ?string $v = null): string => PushCopy::body($t, $m, 'child', 'sl', $v, ['time' => '17:00'], Species::Cat);
        expect($sl(PushType::SoftWarning, 'hunger', PushCopy::VARIANT_CLEAN_FIRST))->toContain('muca je lačna')
            ->and($sl(PushType::SoftWarning, 'thirst', PushCopy::VARIANT_WAIT))->toContain('muca je žejna')
            ->and($sl(PushType::CriticalAlert, 'hunger'))->toBe('Če muce ne nahraniš v 30 minutah, bo zbolela.')
            ->and($sl(PushType::CriticalAlert, 'thirst'))->toContain('muci ne daš')
            ->and($sl(PushType::Illness, 'hygiene'))->toContain('živela')->toContain('je zbolela');
    });
});

describe('M3-12 for a cat: food / water / hygiene reminders never ask for what the app refuses', function () {
    it('names the right first step for every combination of open scratching / litter accident / mess', function (array $kinds, string $food, ?string $hygiene) {
        [, , $cat] = cptCatFamily();
        $cat = cptOpen($cat, $kinds);

        foreach ([PushType::SoftWarning, PushType::CriticalAlert] as $type) {
            foreach (['hunger', 'thirst'] as $metric) {
                expect(cptActionCopy($cat, $type, $metric))->toBe(['variant' => $food, 'replace' => []]);
            }
            expect(cptActionCopy($cat, $type, 'hygiene'))->toBe(['variant' => $hygiene, 'replace' => []]);
        }

        // The text matches: with only a scratching open nothing says "clean".
        $onlyScratching = $kinds === [HygieneEventKind::Scratching];
        foreach (['hunger', 'thirst'] as $metric) {
            $en = PushCopy::body(PushType::SoftWarning, $metric, 'child', 'en', $food, [], Species::Cat);
            $sl = PushCopy::body(PushType::SoftWarning, $metric, 'child', 'sl', $food, [], Species::Cat);
            expect(str_contains($en, 'clean'))->toBe(! $onlyScratching)
                ->and(str_contains($sl, 'počisti'))->toBe(! $onlyScratching)
                ->and(str_contains($en, 'scratching post'))->toBe(in_array(HygieneEventKind::Scratching, $kinds, true));
        }
    })->with([
        'scratching only' => [[HygieneEventKind::Scratching], PushCopy::VARIANT_SCRATCHER_FIRST, PushCopy::VARIANT_SCRATCHER],
        'litter accident only' => [[HygieneEventKind::LitterAccident], PushCopy::VARIANT_CLEAN_FIRST, null],
        'other mess only' => [[HygieneEventKind::Poop], PushCopy::VARIANT_CLEAN_FIRST, null],
        'scratching + litter accident' => [[HygieneEventKind::Scratching, HygieneEventKind::LitterAccident], PushCopy::VARIANT_CLEAN_AND_SCRATCHER_FIRST, PushCopy::VARIANT_CLEAN_AND_SCRATCHER],
        'scratching + other mess' => [[HygieneEventKind::Scratching, HygieneEventKind::Poop], PushCopy::VARIANT_CLEAN_AND_SCRATCHER_FIRST, PushCopy::VARIANT_CLEAN_AND_SCRATCHER],
        'litter accident + other mess' => [[HygieneEventKind::LitterAccident, HygieneEventKind::Poop], PushCopy::VARIANT_CLEAN_FIRST, null],
        'all three' => [[HygieneEventKind::Scratching, HygieneEventKind::LitterAccident, HygieneEventKind::Poop], PushCopy::VARIANT_CLEAN_AND_SCRATCHER_FIRST, PushCopy::VARIANT_CLEAN_AND_SCRATCHER],
    ]);

    it('with nothing open, food / water reminders are the plain or "wait" texts', function () {
        [, , $cat] = cptCatFamily();
        foreach (['hunger', 'thirst'] as $metric) {
            $copy = cptActionCopy($cat, PushType::SoftWarning, $metric);
            expect($copy['variant'] ?? null)->not->toBeIn([PushCopy::VARIANT_CLEAN_FIRST, PushCopy::VARIANT_SCRATCHER_FIRST, PushCopy::VARIANT_CLEAN_AND_SCRATCHER_FIRST]);
        }
        expect(cptActionCopy($cat, PushType::SoftWarning, 'hygiene'))->toBe(['variant' => null, 'replace' => []]);
    });

    it('QA m1: fed at 07:00, hungry at 15:45 with a scratching open — "scratcher first, the next meal is at 17:00", never "then you can feed it"', function () {
        [, $child, $cat] = cptCatFamily(local: '2026-10-21 07:00');
        cptFed($cat, $child, '2026-10-21 07:00');
        cptAt('2026-10-21 15:45');
        Pet::whereKey($cat->id)->update(['hunger_level' => 30]);
        $cat = cptOpen($cat->fresh(), [HygieneEventKind::Scratching]);

        $copy = cptActionCopy($cat, PushType::SoftWarning, 'hunger');
        expect($copy)->toBe(['variant' => PushCopy::VARIANT_SCRATCHER_FIRST_WAIT, 'replace' => ['time' => '17:00']]);
        $en = PushCopy::body(PushType::SoftWarning, 'hunger', 'child', 'en', $copy['variant'], $copy['replace'], Species::Cat);
        $sl = PushCopy::body(PushType::SoftWarning, 'hunger', 'child', 'sl', $copy['variant'], $copy['replace'], Species::Cat);
        expect($en)->toBe('Your cat is hungry, but first carry it to the scratching post and praise it. The next meal is at 17:00.')
            ->not->toContain('Then you can feed')
            ->and($sl)->toBe('Tvoja muca je lačna, a najprej jo odnesi na praskalnik in jo pohvali. Naslednji obrok je ob 17:00.');

        // With a mess next to the tray as well, and with only that mess.
        $cat = cptOpen($cat, [HygieneEventKind::LitterAccident]);
        expect(cptActionCopy($cat, PushType::CriticalAlert, 'hunger'))
            ->toBe(['variant' => PushCopy::VARIANT_CLEAN_AND_SCRATCHER_FIRST_WAIT, 'replace' => ['time' => '17:00']]);
    });

    it('QA m1: a mess open after the last meal of the day — no food reminder at all (nothing possible today)', function () {
        [, $child, $cat] = cptCatFamily(local: '2026-10-21 07:00');
        cptFed($cat, $child, '2026-10-21 07:00');
        cptFed($cat, $child, '2026-10-21 18:00');
        cptAt('2026-10-21 21:30');
        Pet::whereKey($cat->id)->update(['hunger_level' => 30]);
        $cat = cptOpen($cat->fresh(), [HygieneEventKind::LitterAccident]);

        expect(cptActionCopy($cat, PushType::SoftWarning, 'hunger'))->toBeNull();
    });

    it('leaves the dog unchanged: an open chewing still blocks food with the dog\'s "clean first"', function () {
        config(['push.enabled' => true]);
        seedBreedConfigs();
        cptAt('2026-10-21 12:00');
        $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        withoutQuietHours($parent);
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        $dog = disableHygieneEvents(Pet::factory()->create(['user_id' => $child->id]));
        $dog = cptOpen($dog, [HygieneEventKind::Chewing]);

        expect(cptActionCopy($dog, PushType::SoftWarning, 'hunger'))->toBe(['variant' => PushCopy::VARIANT_CLEAN_FIRST, 'replace' => []])
            ->and(cptActionCopy($dog, PushType::SoftWarning, 'hygiene'))->toBe(['variant' => PushCopy::VARIANT_TIDY, 'replace' => []]);
    });
});

describe('delivery: the device gets the cat text in its own language', function () {
    it('sends a hungry cat with an open scratching "to the scratcher first" (EN + SL), the child only', function () {
        [$parent, $child, $cat] = cptCatFamily();
        cptDevice($child, 'sl');
        cptDevice($child, 'en');
        cptDevice($parent, 'sl'); // phase 1 goes to caretakers, not parents
        $cat = cptOpen($cat, [HygieneEventKind::Scratching]);
        Pet::whereKey($cat->id)->update(['hunger_level' => 25]);

        $bodies = cptDeliver($cat->fresh(), PushType::SoftWarning, 'hunger');

        expect($bodies)->toBe([
            'sl' => 'Tvoja muca je lačna, a najprej jo odnesi na praskalnik in jo pohvali. Potem jo lahko nahraniš.',
            'en' => 'Your cat is hungry, but first carry it to the scratching post and praise it. Then you can feed it.',
        ]);
    });

    it('sends parents the cat\'s phase 3 alarm and a game over in cat words', function () {
        [$parent, , $cat] = cptCatFamily();
        cptDevice($parent, 'sl');
        Pet::whereKey($cat->id)->update(['thirst_level' => 0]);
        expect(cptDeliver($cat->fresh(), PushType::ParentAlarm, 'thirst'))
            ->toBe(['sl' => 'Tvoj otrok danes ni poskrbel za muco. Muca je že več kot uro brez vode.']);
    });

    it('sends a dog the unchanged dog text through the same path', function () {
        config(['push.enabled' => true]);
        seedBreedConfigs();
        cptAt('2026-10-21 12:00');
        $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        withoutQuietHours($parent);
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        $dog = disableHygieneEvents(Pet::factory()->create(['user_id' => $child->id, 'hunger_level' => 100, 'hygiene_level' => 100]));
        cptDevice($child, 'sl');
        $dog = cptOpen($dog, [HygieneEventKind::Poop]);
        Pet::whereKey($dog->id)->update(['hunger_level' => 25]);

        expect(cptDeliver($dog->fresh(), PushType::SoftWarning, 'hunger'))
            ->toBe(['sl' => 'Tvoj kuža je lačen, a najprej je treba počistiti nered. Potem ga lahko nahraniš.']);
    });
});

describe('other server texts that name the animal', function () {
    it('explains the data export with "pets" once the family has a cat; a dog family keeps "dogs"', function () {
        [$parent] = cptCatFamily();
        app()->setLocale('sl');
        expect(app(AccountExportService::class)->exportFor($parent)['about'])->toContain('slik in videov ljubljenčkov');
        app()->setLocale('en');
        expect(app(AccountExportService::class)->exportFor($parent)['about'])->toContain('Links to the pets’ images');

        $dogParent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        $dogChild = User::factory()->child()->create(['parent_id' => $dogParent->id]);
        Pet::factory()->create(['user_id' => $dogChild->id]);
        expect(app(AccountExportService::class)->exportFor($dogParent)['about'])->toBe(__('account.export.about'))
            ->toContain('Links to the dogs’ images');
    });

    it('names the cat in challenge refusals (API message; the reason code is unchanged)', function () {
        [, , $coon] = cptCatFamily(BreedType::MaineCoon);
        try {
            app(ChallengeCreditService::class)->activate($coon);
            $this->fail('expected a refusal');
        } catch (ChallengeException $e) {
            expect($e->reason)->toBe('already_paid')
                ->and($e->getMessage())->toBe('This cat\'s challenge is already unlocked.');
        }
    });

    it('has the same account keys in every language', function () {
        $keys = fn (string $locale): array => array_keys(Arr::dot(require lang_path("{$locale}/account.php")));
        expect($keys('sl'))->toBe($keys('en'));
    });
});
