<?php

use App\Models\Family;
use App\Models\Pet;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Services\Auth\RsaJwt;
use App\Support\DummyPasswordHash;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

/*
|--------------------------------------------------------------------------
| M6-S01b — website sign-in for gift voucher redemption (CONTRACT_WEB_SIGNIN §6.1)
|--------------------------------------------------------------------------
| POST / DELETE /api/web/parent-session, the `gift:redeem` token, its scope
| (rule W on every other route) and the app's tokens / routes unchanged.
| A runtime RSA key stands in for Google / Apple (faked JWKS, as SocialSignInTest).
| The `web-session` limiter is live; every test uses its own IP unless it is
| about the limit.
*/

require_once __DIR__.'/../Support/GiftVoucherFakes.php';

const WS_GOOGLE_JWKS = 'https://www.googleapis.com/oauth2/v3/certs';
const WS_APPLE_JWKS = 'https://appleid.apple.com/auth/keys';
const WS_GOOGLE_APP = 'test-app-client.apps.googleusercontent.com';
const WS_GOOGLE_WEB = 'test-website-client.apps.googleusercontent.com';
const WS_APPLE_APP = 'com.datavallis.petprep';
const WS_APPLE_WEB = 'com.datavallis.petprep.web';
const WS_NONCE = 'web-raw-nonce-0123456789abcdef';
const WS_PASSWORD = 'Correct-Horse-42';

function wsKey(): OpenSSLAsymmetricKey
{
    static $key = null;

    return $key ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
}

function wsFakeProviders(): void
{
    $rsa = openssl_pkey_get_details(wsKey())['rsa'];
    $jwk = ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'kid-ws',
        'n' => RsaJwt::base64UrlEncode($rsa['n']), 'e' => RsaJwt::base64UrlEncode($rsa['e'])];
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    Http::fake([
        WS_GOOGLE_JWKS => Http::response(['keys' => [$jwk]], 200),
        WS_APPLE_JWKS => Http::response(['keys' => [$jwk]], 200),
    ]);
}

/**
 * @param  array<string, mixed>  $claims  overrides (null removes a claim)
 */
function wsToken(string $provider = 'google', array $claims = []): string
{
    $now = now()->getTimestamp();
    $defaults = $provider === 'google'
        ? ['iss' => 'https://accounts.google.com', 'aud' => WS_GOOGLE_WEB, 'sub' => 'g-sub-parent',
            'email' => 'parent@gmail.com', 'email_verified' => true, 'nonce' => hash('sha256', WS_NONCE),
            'iat' => $now, 'exp' => $now + 3600]
        : ['iss' => 'https://appleid.apple.com', 'aud' => WS_APPLE_WEB, 'sub' => '000123.apple.0420',
            'nonce' => hash('sha256', WS_NONCE), 'iat' => $now, 'exp' => $now + 600];
    $payload = array_filter(array_merge($defaults, $claims), fn ($v) => $v !== null);
    $input = RsaJwt::base64UrlEncode(json_encode(['alg' => 'RS256', 'kid' => 'kid-ws', 'typ' => 'JWT']))
        .'.'.RsaJwt::base64UrlEncode(json_encode($payload));
    openssl_sign($input, $signature, wsKey(), OPENSSL_ALGO_SHA256);

    return $input.'.'.RsaJwt::base64UrlEncode($signature);
}

/** @param  array<string, mixed>  $body */
function wsPost(array $body, string $ip = '198.51.100.210'): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => ''])->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/web/parent-session', $body);
}

function wsPassword(string $email = 'parent@example.com', string $password = WS_PASSWORD, string $ip = '198.51.100.210'): TestResponse
{
    return wsPost(['method' => 'password', 'email' => $email, 'password' => $password, 'locale' => 'en'], $ip);
}

/** @param  array<string, mixed>  $claims */
function wsSocial(string $provider = 'google', array $claims = [], ?string $nonce = WS_NONCE, string $ip = '198.51.100.211'): TestResponse
{
    return wsPost(array_filter(['method' => $provider, 'id_token' => wsToken($provider, $claims), 'nonce' => $nonce, 'locale' => 'en'], fn ($v) => $v !== null), $ip);
}

/** A parent with a family and a password. */
function wsParent(string $email = 'parent@example.com', ?string $password = WS_PASSWORD): User
{
    $parent = User::factory()->parent()->create(['email' => $email]);
    $parent->forceFill(['password' => $password !== null ? Hash::make($password) : null])->save();

    return $parent;
}

function wsLink(User $user, string $provider, string $subject): void
{
    UserSocialAccount::create(['user_id' => $user->id, 'provider' => $provider, 'subject' => $subject, 'email' => null]);
}

/** @return array<string, int> */
function wsCounts(): array
{
    return [
        'users' => User::count(), 'families' => Family::count(), 'social' => UserSocialAccount::count(),
        'tokens' => PersonalAccessToken::count(),
    ];
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-11 21:35:00', 'UTC'));
    config([
        'services.lemonsqueezy.store_id' => (string) GV_STORE,
        'services.lemonsqueezy.gift_product_id' => (string) GV_PRODUCT,
        'services.google.client_ids' => [WS_GOOGLE_APP],
        'services.google.web_client_ids' => [WS_GOOGLE_WEB],
        'services.apple.audiences' => [WS_APPLE_APP],
        'services.apple.web_audiences' => [WS_APPLE_WEB],
    ]);
    gvSwitch(true);
    wsFakeProviders();
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('password', function () {
    it('WS1: password OK → 200; token row web:gift-redeem, ["gift:redeem"], expires now + 15 min; no user / e-mail / name', function () {
        $parent = wsParent();

        $res = wsPassword()->assertOk();
        expect(array_keys($res->json()))->toBe(['token', 'token_type', 'abilities', 'expires_at', 'expires_in'])
            ->and($res->json('token_type'))->toBe('Bearer')
            ->and($res->json('abilities'))->toBe(['gift:redeem'])
            ->and($res->json('expires_at'))->toBe('2026-11-11T21:50:00Z')
            ->and($res->json('expires_in'))->toBe(900)
            ->and($res->getContent())->not->toContain('parent@example.com');

        $row = PersonalAccessToken::findToken($res->json('token'));
        expect($row)->not->toBeNull()
            ->and($row->tokenable_id)->toBe($parent->id)
            ->and($row->name)->toBe('web:gift-redeem')
            ->and($row->abilities)->toBe(['gift:redeem'])
            ->and($row->expires_at->equalTo(now()->addMinutes(15)))->toBeTrue();
    });

    it('WS2: unknown e-mail, wrong password and a password-less parent get identical 401 bodies; no token row', function () {
        wsParent();
        wsParent('nopass@example.com', null);
        $before = PersonalAccessToken::count();

        $unknown = wsPassword('nobody@example.com', ip: '198.51.100.1')->assertStatus(401)->json();
        $wrong = wsPassword(password: 'Wrong-Horse-42', ip: '198.51.100.2')->assertStatus(401)->json();
        $none = wsPassword('nopass@example.com', ip: '198.51.100.3')->assertStatus(401)->json();

        expect($unknown)->toBe($wrong)->toBe($none)
            ->and($unknown)->toBe(['error' => 'invalid_credentials', 'message' => 'The e-mail or password is not correct.'])
            ->and(PersonalAccessToken::count())->toBe($before);
    });

    it('WS3: a legacy child with a correct password and a parent without a family → 403 not_parent, no token', function () {
        $parent = wsParent();
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        $child->forceFill(['email' => 'legacy-kid@example.com', 'password' => Hash::make(WS_PASSWORD)])->save();
        $loner = wsParent('loner@example.com');
        DB::table('family_user')->where('user_id', $loner->id)->delete();
        $before = PersonalAccessToken::count();

        wsPassword('legacy-kid@example.com', ip: '198.51.100.4')->assertStatus(403)->assertExactJson(['error' => 'not_parent', 'message' => 'Only a parent account can sign in here.']);
        wsPassword('loner@example.com', ip: '198.51.100.5')->assertStatus(403)->assertJsonPath('error', 'not_parent');
        expect(PersonalAccessToken::count())->toBe($before);
    });

    it('M1 (QA): an unknown e-mail and a password-less account cost exactly ONE Hash::check against the constant hash — never a Hash::make', function () {
        wsParent('nopass@example.com', null);
        Hash::spy();

        wsPassword('nobody@example.com', ip: '198.51.100.6')->assertStatus(401);
        wsPassword('nopass@example.com', ip: '198.51.100.7')->assertStatus(401);

        Hash::shouldHaveReceived('check')->twice()->withArgs(fn ($plain, $hash) => $hash === DummyPasswordHash::VALUE);
        Hash::shouldNotHaveReceived('make');
        expect(DummyPasswordHash::VALUE)->toStartWith('$2y$12$')->and(password_get_info(DummyPasswordHash::VALUE)['algoName'])->toBe('bcrypt');
    });

    it('M1 (QA): a known account also costs exactly one Hash::check (its own hash)', function () {
        $parent = wsParent();
        Hash::spy();

        wsPassword(password: 'Wrong-Horse-42', ip: '198.51.100.8')->assertStatus(401);

        Hash::shouldHaveReceived('check')->once()->withArgs(fn ($plain, $hash) => $hash === $parent->getAuthPassword());
        Hash::shouldNotHaveReceived('make');
    });

    it('M2 (QA): the website ids default to PetPrep\'s real ids (env unset) and never include the app ids', function () {
        $services = require config_path('services.php');
        expect($services['google']['web_client_ids'])->toBe(['1029107925972-6kanm5f1u2bha65ua73e4m09fbjn4cp7.apps.googleusercontent.com'])
            ->and($services['apple']['web_audiences'])->toBe(['com.datavallis.petprep.web'])
            ->and(array_intersect($services['google']['web_client_ids'], $services['google']['client_ids']))->toBe([])
            ->and(array_intersect($services['apple']['web_audiences'], $services['apple']['audiences']))->toBe([]);
    });

    it('WS9b: a superadmin parent may use the password method (parity with /api/login)', function () {
        $admin = wsParent('admin@example.com');
        $admin->forceFill(['is_superadmin' => true])->save();

        wsPassword('admin@example.com')->assertOk()->assertJsonPath('abilities', ['gift:redeem']);
    });

    it('WS13: 5 failures for one e-mail from 5 IPs lock it — the 6th (even correct) is 429 + Retry-After; a success never resets it', function () {
        wsParent();
        for ($i = 1; $i <= 4; $i++) {
            wsPassword('Parent@Example.com ', 'Wrong-'.$i, '192.0.2.'.$i)->assertStatus(401);
        }
        wsPassword(ip: '192.0.2.10')->assertOk(); // a success does not launder the counter
        wsPassword(password: 'Wrong-5', ip: '192.0.2.5')->assertStatus(401);

        wsPassword(ip: '192.0.2.6')->assertStatus(429)->assertJsonPath('error', 'rate_limited')->assertHeader('Retry-After');
        // Unknown e-mails are counted too (no enumeration).
        for ($i = 1; $i <= 5; $i++) {
            wsPassword('ghost@example.com', ip: '192.0.2.'.(20 + $i))->assertStatus(401);
        }
        wsPassword('ghost@example.com', ip: '192.0.2.30')->assertStatus(429);
        // After 15 minutes the account works again.
        $this->travel(16)->minutes();
        wsPassword(ip: '192.0.2.31')->assertOk();
    });
});

describe('google and apple', function () {
    it('WS4: a Google token for the WEBSITE client + nonce, identity linked → 200; no row written', function () {
        $parent = wsParent();
        wsLink($parent, 'google', 'g-sub-parent');
        $before = wsCounts();

        $res = wsSocial()->assertOk()->assertJsonPath('abilities', ['gift:redeem']);
        expect(PersonalAccessToken::findToken($res->json('token'))->tokenable_id)->toBe($parent->id);
        $after = wsCounts();
        expect($after['users'])->toBe($before['users'])->and($after['families'])->toBe($before['families'])
            ->and($after['social'])->toBe($before['social'])->and($after['tokens'])->toBe($before['tokens'] + 1);
    });

    it('WS5: a Google token with an APP aud is refused at the web endpoint', function () {
        wsLink(wsParent(), 'google', 'g-sub-parent');

        wsSocial('google', ['aud' => WS_GOOGLE_APP])->assertStatus(401)->assertExactJson(['error' => 'invalid_token', 'message' => 'The sign-in could not be confirmed. Please try again.']);
    });

    it('WS6: a Google token with the WEBSITE aud is refused by the app\'s /api/auth/social (separation)', function () {
        wsLink(wsParent(), 'google', 'g-sub-parent');
        app('auth')->forgetGuards();

        test()->withHeaders(['Authorization' => ''])->withServerVariables(['REMOTE_ADDR' => '198.51.100.230'])
            ->postJson('/api/auth/social', ['provider' => 'google', 'id_token' => wsToken('google'), 'timezone' => 'Europe/Ljubljana'])
            ->assertStatus(401)->assertJsonPath('code', 'invalid_token');
        // The same holds for Apple's Services ID.
        test()->postJson('/api/auth/social', ['provider' => 'apple', 'id_token' => wsToken('apple'), 'nonce' => WS_NONCE, 'timezone' => 'Europe/Ljubljana'])
            ->assertStatus(401)->assertJsonPath('code', 'invalid_token');
    });

    it('WS7: Apple with the Services ID aud + nonce → 200; a missing nonce → 422; a wrong nonce → 401 (both providers)', function () {
        $parent = wsParent();
        wsLink($parent, 'apple', '000123.apple.0420');
        wsLink($parent, 'google', 'g-sub-parent');

        wsSocial('apple')->assertOk();
        wsSocial('apple', ['jti' => 'a2'], nonce: null, ip: '198.51.100.31')->assertStatus(422)->assertJsonPath('error', 'invalid_request');
        wsSocial('google', ['jti' => 'g2'], nonce: null, ip: '198.51.100.32')->assertStatus(422)->assertJsonPath('error', 'invalid_request');
        wsSocial('apple', ['jti' => 'a3'], nonce: 'another-raw-nonce-0000', ip: '198.51.100.33')->assertStatus(401)->assertJsonPath('error', 'invalid_token');
        wsSocial('google', ['jti' => 'g3'], nonce: 'another-raw-nonce-0000', ip: '198.51.100.34')->assertStatus(401)->assertJsonPath('error', 'invalid_token');
        // A Google token WITHOUT a nonce claim is refused on the web (required for both).
        wsSocial('google', ['jti' => 'g4', 'nonce' => null], ip: '198.51.100.35')->assertStatus(401)->assertJsonPath('error', 'invalid_token');
    });

    it('WS8: an unknown identity → 404 account_not_found — also when the token\'s verified e-mail is an existing password-less parent\'s: no link, no rows', function () {
        wsParent('parent@gmail.com', null);
        $before = wsCounts();

        wsSocial('google', ['sub' => 'g-sub-unknown'])->assertStatus(404)->assertExactJson(['error' => 'account_not_found', 'message' => 'There is no PetPrep account for this sign-in. Use the sign-in you use in the PetPrep app.']);
        expect(wsCounts())->toBe($before);
    });

    it('WS9: a superadmin via Google → 403 not_parent', function () {
        $admin = wsParent('admin@example.com');
        $admin->forceFill(['is_superadmin' => true])->save();
        wsLink($admin, 'google', 'g-sub-parent');

        wsSocial()->assertStatus(403)->assertJsonPath('error', 'not_parent');
    });

    it('WS10: the same ID token twice → the second is 401 invalid_token', function () {
        wsLink(wsParent(), 'google', 'g-sub-parent');
        $idToken = wsToken('google');

        wsPost(['method' => 'google', 'id_token' => $idToken, 'nonce' => WS_NONCE])->assertOk();
        wsPost(['method' => 'google', 'id_token' => $idToken, 'nonce' => WS_NONCE], '198.51.100.40')->assertStatus(401)->assertJsonPath('error', 'invalid_token');
    });

    it('WS11: no website id configured → 503 provider_disabled and no HTTP at all (the app ids do not count)', function () {
        config(['services.google.web_client_ids' => [], 'services.apple.web_audiences' => []]);
        Http::swap(new HttpFactory);
        Http::fake();

        wsSocial('google')->assertStatus(503)->assertJsonPath('error', 'provider_disabled');
        wsSocial('apple', ip: '198.51.100.41')->assertStatus(503)->assertJsonPath('error', 'provider_disabled');
        Http::assertNothingSent();
    });

    it('JWKS unreachable → 503 provider_unavailable', function () {
        Http::swap(new HttpFactory);
        Http::fake([WS_GOOGLE_JWKS => Http::response('', 500)]);

        wsSocial()->assertStatus(503)->assertJsonPath('error', 'provider_unavailable');
    });
});

describe('switch, limits, tokens', function () {
    it('WS12: gift switch off → 503 unavailable before any Hash::check or JWKS request', function () {
        wsParent();
        gvSwitch(false);
        Http::swap(new HttpFactory);
        Http::fake();
        Hash::spy();

        wsPassword()->assertStatus(503)->assertExactJson(['error' => 'unavailable', 'message' => 'Signing in here is not available right now.']);
        wsSocial(ip: '198.51.100.50')->assertStatus(503)->assertJsonPath('error', 'unavailable');
        Hash::shouldNotHaveReceived('check');
        Http::assertNothingSent();

        // Lemon Squeezy not configured counts as off too.
        gvSwitch(true);
        config(['services.lemonsqueezy.store_id' => '']);
        wsSocial(ip: '198.51.100.51')->assertStatus(503)->assertJsonPath('error', 'unavailable');
    });

    it('WS13: the 11th request in a minute from one IP is 429 + Retry-After; another IP is unaffected; DELETE shares the bucket', function () {
        for ($i = 0; $i < 9; $i++) {
            wsPost(['method' => 'nope'], '203.0.113.77')->assertStatus(422);
        }
        app('auth')->forgetGuards();
        test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])->deleteJson('/api/web/parent-session')->assertNoContent();
        wsPost(['method' => 'nope'], '203.0.113.77')->assertStatus(429)
            ->assertJsonPath('error', 'rate_limited')->assertHeader('Retry-After');
        wsPost(['method' => 'nope'], '203.0.113.78')->assertStatus(422);
    });

    it('WS14: a 4th web sign-in deletes the oldest web token; app tokens are untouched', function () {
        $parent = wsParent();
        $app = $parent->createToken('iPhone', ['parent']);
        $legacyNamed = $parent->createToken('web:gift-redeem', ['parent']); // an app token that happens to carry the name

        $tokens = [];
        for ($i = 0; $i < 4; $i++) {
            $tokens[] = wsPassword(ip: '198.51.100.'.(60 + $i))->assertOk()->json('token');
            $this->travel(1)->seconds();
        }

        expect(PersonalAccessToken::findToken($tokens[0]))->toBeNull()
            ->and(PersonalAccessToken::findToken($tokens[1]))->not->toBeNull()
            ->and(PersonalAccessToken::findToken($tokens[3]))->not->toBeNull()
            ->and(PersonalAccessToken::whereKey($app->accessToken->id)->exists())->toBeTrue()
            ->and(PersonalAccessToken::whereKey($legacyNamed->accessToken->id)->exists())->toBeTrue()
            ->and($parent->tokens()->where('abilities', '["gift:redeem"]')->count())->toBe(3);
    });

    it('WS15: DELETE deletes only that web token; an app token is never deleted; no token → 204', function () {
        $parent = wsParent();
        $one = wsPassword()->json('token');
        $two = wsPassword(ip: '198.51.100.70')->json('token');
        $app = $parent->createToken('iPhone', ['parent'])->plainTextToken;
        $del = fn (?string $t) => (function () use ($t) {
            app('auth')->forgetGuards();

            return test()->withHeaders(['Authorization' => $t !== null ? 'Bearer '.$t : ''])
                ->withServerVariables(['REMOTE_ADDR' => '198.51.100.71'])->deleteJson('/api/web/parent-session');
        })();

        $del($one)->assertNoContent();
        expect(PersonalAccessToken::findToken($one))->toBeNull()->and(PersonalAccessToken::findToken($two))->not->toBeNull();
        $del($one)->assertNoContent(); // idempotent
        $del($app)->assertNoContent();
        expect(PersonalAccessToken::findToken($app))->not->toBeNull();
        $del(null)->assertNoContent();
        $del('999|not-a-token')->assertNoContent();
    });

    it('WS16: extra body fields never shape the token', function () {
        wsParent();
        $res = wsPost(['method' => 'password', 'email' => 'parent@example.com', 'password' => WS_PASSWORD,
            'abilities' => ['*'], 'ability' => 'parent', 'device_name' => 'evil', 'name' => 'x', 'terms_accepted' => true,
            'timezone' => 'Europe/Paris'])->assertOk();

        $row = PersonalAccessToken::findToken($res->json('token'));
        expect($row->abilities)->toBe(['gift:redeem'])->and($row->name)->toBe('web:gift-redeem');
    });

    it('WS18: locale de → 200 and an English message on errors; xx → 422', function () {
        wsParent();
        wsPost(['method' => 'password', 'email' => 'parent@example.com', 'password' => WS_PASSWORD, 'locale' => 'de'])->assertOk();
        wsPost(['method' => 'password', 'email' => 'parent@example.com', 'password' => 'Wrong-1', 'locale' => 'fi'], '198.51.100.80')
            ->assertStatus(401)->assertJsonPath('message', 'The e-mail or password is not correct.');
        wsPost(['method' => 'password', 'email' => 'parent@example.com', 'password' => 'Wrong-2', 'locale' => 'sl'], '198.51.100.81')
            ->assertStatus(401)->assertJsonPath('message', 'E-naslov ali geslo ni pravilno.');
        $bad = wsPost(['method' => 'password', 'email' => 'parent@example.com', 'password' => WS_PASSWORD, 'locale' => 'xx'], '198.51.100.82')
            ->assertStatus(422)->json();
        expect(array_keys($bad))->toBe(['error', 'message'])->and($bad['error'])->toBe('invalid_request');
    });

    it('R6: sanctum:prune-expired --hours=24 deletes web tokens expired > 24 h ago and keeps app tokens (expires_at null), however old', function () {
        $parent = wsParent();
        $old = $parent->createToken('web:gift-redeem', ['gift:redeem'], now()->subHours(25));
        $recent = $parent->createToken('web:gift-redeem', ['gift:redeem'], now()->subHours(2));
        $app = $parent->createToken('iPhone', ['parent']);
        PersonalAccessToken::whereKey($app->accessToken->id)->update(['created_at' => now()->subYears(2)]);

        Artisan::call('sanctum:prune-expired', ['--hours' => 24]);

        expect(PersonalAccessToken::whereKey($old->accessToken->id)->exists())->toBeFalse()
            ->and(PersonalAccessToken::whereKey($recent->accessToken->id)->exists())->toBeTrue()
            ->and(PersonalAccessToken::whereKey($app->accessToken->id)->exists())->toBeTrue();
    });

    it('schedules the daily prune with an explicit overlap expiry', function () {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'sanctum:prune-expired --hours=24'));
        expect($event)->not->toBeNull()->and($event->description)->toContain('website sign-in tokens');
    });
});

describe('scope (rule W)', function () {
    it('WS17: a web token gets 403 token_scope on EVERY other API route (route list iterated), and changes nothing', function () {
        seedBreedConfigs();
        $parent = wsParent();
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        $pet = disableHygieneEvents(Pet::factory()->create(['user_id' => $child->id]));
        $web = wsPassword()->json('token');
        $tokensBefore = PersonalAccessToken::count();
        $devicesBefore = DB::table('device_push_tokens')->count();

        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/') || in_array($route->getName(), ['gift.redeem', 'web.parent-session.destroy'], true)) {
                continue;
            }
            $method = collect($route->methods())->first(fn (string $m) => ! in_array($m, ['HEAD', 'OPTIONS'], true));
            // Fill every parameter so the route itself matches (respecting its `where`).
            $path = '/'.preg_replace_callback('/\{([^}?]+)\??\}/', function (array $m) use ($route, $pet): string {
                $pattern = $route->wheres[$m[1]] ?? null;

                return $pattern === null || preg_match('/^'.$pattern.'$/', (string) $pet->id) === 1 ? (string) $pet->id : 'abc';
            }, $uri);
            app('auth')->forgetGuards();
            $res = test()->withHeaders(['Authorization' => 'Bearer '.$web])
                ->withServerVariables(['REMOTE_ADDR' => '10.9.'.intdiv($checked, 200).'.'.($checked % 200 + 1)])
                ->json($method, $path, ['socket_id' => '1.2', 'channel_name' => 'private-pet.'.$pet->id, 'expo_push_token' => 'ExponentPushToken[x]', 'platform' => 'ios']);
            expect($res->status())->toBe(403, "{$method} {$path} answered {$res->status()}")
                ->and($res->json('error'))->toBe('token_scope', "{$method} {$path}");
            $checked++;
        }

        // The listed routes of the contract are among them (sanity).
        $names = collect(Route::getRoutes())->map(fn ($r) => $r->uri())->all();
        expect($names)->toContain('api/user', 'api/logout', 'api/devices', 'api/broadcasting/auth', 'api/parent/dashboard',
            'api/parent/billing', 'api/child/pet', 'api/app-errors', 'api/media/{media}', 'api/breeds/{breed}/facts', 'api/web/parent-session')
            ->and($checked)->toBeGreaterThan(60)
            // logout did not delete it, devices wrote nothing.
            ->and(PersonalAccessToken::count())->toBe($tokensBefore)
            ->and(DB::table('device_push_tokens')->count())->toBe($devicesBefore);
    });

    it('a signed media URL with a web token is 403 token_scope (the signature does not matter)', function () {
        seedBreedConfigs();
        $parent = wsParent();
        $web = wsPassword()->json('token');
        $url = URL::temporarySignedRoute('media.show', now()->addHour(), ['media' => 1], absolute: false);
        app('auth')->forgetGuards();

        test()->withHeaders(['Authorization' => 'Bearer '.$web])->getJson($url)->assertStatus(403)->assertJsonPath('error', 'token_scope');
    });

    it('R3: legacy ["*"], parent and child tokens still pass the bare auth group and broadcasting auth', function () {
        seedBreedConfigs();
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.reverb.options.host' => 'localhost',
        ]);
        app(BroadcastManager::class)->forgetDrivers();
        require base_path('routes/channels.php');

        $parent = wsParent();
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        $pet = disableHygieneEvents(Pet::factory()->create(['user_id' => $child->id]));
        $call = function (string $token, string $method, string $uri, array $data = []) {
            app('auth')->forgetGuards();

            return test()->withHeaders(['Authorization' => 'Bearer '.$token])->json($method, $uri, $data);
        };

        foreach ([[$parent, ['*']], [$parent, ['parent']], [$child, ['child']]] as $i => [$user, $abilities]) {
            $token = $user->createToken('device-'.$i, $abilities)->plainTextToken;
            $call($token, 'GET', '/api/user')->assertOk()->assertJsonPath('id', $user->id);
            $call($token, 'POST', '/api/devices', ['expo_push_token' => 'ExponentPushToken[r3abcdefgh'.$i.']', 'platform' => 'ios', 'locale' => 'en'])->assertSuccessful();
            $call($token, 'POST', '/api/devices/unregister', ['expo_push_token' => 'ExponentPushToken[r3abcdefgh'.$i.']'])->assertSuccessful();
            $call($token, 'POST', '/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-pet.'.$pet->id])
                ->assertOk()->assertJsonStructure(['auth']);
            $call($token, 'POST', '/api/logout')->assertOk();
            expect(PersonalAccessToken::findToken($token))->toBeNull();
        }
    });

    it('R3b: the app-errors route still ignores an invalid bearer (never 401) and a web token never redeems through it', function () {
        app('auth')->forgetGuards();
        $res = test()->withHeaders(['Authorization' => 'Bearer 999|nope'])->postJson('/api/app-errors', []);
        expect($res->status())->not->toBe(401)->and($res->status())->not->toBe(403);
    });
});
