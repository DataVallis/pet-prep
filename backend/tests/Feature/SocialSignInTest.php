<?php

use App\Enums\FamilyRole;
use App\Enums\SocialProvider;
use App\Enums\UserRole;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Providers\AppServiceProvider;
use App\Services\Auth\RsaJwt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
| M2-10c — parent sign-in with Google / Apple (POST /api/auth/social) and the
| re-authentication of password-less parents for deletions.
|
| A runtime-generated RSA key stands in for Google / Apple: its public half is
| served from faked JWKS endpoints and every test token is signed with it. No
| private key is committed and no real network is used.
| The `social` limiter is live in testing; every test uses its own IP unless
| it is about the limit.
*/

const SS_GOOGLE_JWKS = 'https://www.googleapis.com/oauth2/v3/certs';
const SS_APPLE_JWKS = 'https://appleid.apple.com/auth/keys';
const SS_GOOGLE_CLIENT = 'test-web-client.apps.googleusercontent.com';
const SS_APPLE_NONCE = 'raw-nonce-0123456789';

/** One RSA key pair per name, generated once per process. */
function ssKey(string $name = 'main'): OpenSSLAsymmetricKey
{
    static $keys = [];

    return $keys[$name] ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
}

/** @return array<string, string> the public JWK of a test key */
function ssJwk(string $kid, string $keyName = 'main'): array
{
    $rsa = openssl_pkey_get_details(ssKey($keyName))['rsa'];

    return ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => $kid,
        'n' => RsaJwt::base64UrlEncode($rsa['n']), 'e' => RsaJwt::base64UrlEncode($rsa['e'])];
}

/**
 * Replace every HTTP fake (a later Http::fake() would not override the
 * beforeEach stubs for the same URL).
 *
 * @param  array<string, mixed>  $stubs
 */
function ssRefake(array $stubs): void
{
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    Http::fake($stubs);
}

/** Both providers publish the main test key as `kid-1`. */
function ssFakeProviders(): void
{
    ssRefake([
        SS_GOOGLE_JWKS => Http::response(['keys' => [ssJwk('kid-1')]], 200, ['Cache-Control' => 'public, max-age=21600']),
        SS_APPLE_JWKS => Http::response(['keys' => [ssJwk('kid-1')]], 200),
    ]);
}

/**
 * A signed ID token as Google / Apple would issue it.
 *
 * @param  array<string, mixed>  $claims  overrides (null removes a claim)
 */
function ssToken(string $provider = 'google', array $claims = [], string $kid = 'kid-1', string $keyName = 'main', array $header = []): string
{
    $now = now()->getTimestamp();
    $defaults = $provider === 'google'
        ? ['iss' => 'https://accounts.google.com', 'aud' => SS_GOOGLE_CLIENT, 'azp' => SS_GOOGLE_CLIENT,
            'sub' => '110000000000000000001', 'email' => 'Ana.Novak@Gmail.com', 'email_verified' => true,
            'name' => 'Ana Novak', 'iat' => $now, 'exp' => $now + 3600]
        : ['iss' => 'https://appleid.apple.com', 'aud' => 'com.datavallis.petprep',
            'sub' => '000123.abcdef0123456789.0420', 'email' => 'x7k2p9@privaterelay.appleid.com',
            'email_verified' => 'true', 'is_private_email' => 'true',
            'nonce' => hash('sha256', SS_APPLE_NONCE), 'nonce_supported' => true, 'iat' => $now, 'exp' => $now + 600];

    $payload = array_filter(array_merge($defaults, $claims), fn ($v) => $v !== null);
    $input = RsaJwt::base64UrlEncode(json_encode(array_merge(['alg' => 'RS256', 'kid' => $kid, 'typ' => 'JWT'], $header)))
        .'.'.RsaJwt::base64UrlEncode(json_encode($payload));
    openssl_sign($input, $signature, ssKey($keyName), OPENSSL_ALGO_SHA256);

    return $input.'.'.RsaJwt::base64UrlEncode($signature);
}

/** @param  array<string, mixed>  $body */
function ssPost(array $body, string $ip = '198.51.100.120'): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => ''])->withServerVariables(['REMOTE_ADDR' => $ip]);

    return postJson('/api/auth/social', $body);
}

/** @param  array<string, mixed>  $overrides */
function ssGoogle(array $overrides = [], array $claims = [], string $ip = '198.51.100.120'): TestResponse
{
    return ssPost(array_merge([
        'provider' => 'google',
        'id_token' => ssToken('google', $claims),
        'timezone' => 'Europe/Ljubljana',
        'terms_accepted' => true,
    ], $overrides), $ip);
}

/** @param  array<string, mixed>  $overrides */
function ssApple(array $overrides = [], array $claims = [], string $ip = '198.51.100.121'): TestResponse
{
    return ssPost(array_merge([
        'provider' => 'apple',
        'id_token' => ssToken('apple', $claims),
        'nonce' => SS_APPLE_NONCE,
        'name' => 'Ana',
        'timezone' => 'Europe/Ljubljana',
        'terms_accepted' => true,
    ], $overrides), $ip);
}

beforeEach(function () {
    ssFakeProviders();
    config(['services.google.client_ids' => [SS_GOOGLE_CLIENT, 'test-ios-client.apps.googleusercontent.com']]);
});

describe('POST /api/auth/social — sign-up and sign-in', function () {
    it('creates a parent with their own family from a Google token (terms accepted) and signs them in', function () {
        $res = ssGoogle(['timezone' => 'America/New_York'])->assertOk()
            ->assertJsonStructure(['token', 'abilities', 'user' => ['id', 'name', 'email', 'role'], 'pet', 'awaiting_contract', 'created'])
            ->assertJsonPath('created', true)
            ->assertJsonPath('abilities', ['parent'])
            ->assertJsonPath('user.role', 'parent')
            ->assertJsonPath('user.name', 'Ana Novak')
            ->assertJsonPath('user.email', 'ana.novak@gmail.com')
            ->assertJsonPath('pet', null)
            ->assertJsonPath('user.has_password', false)
            ->assertJsonPath('user.linked_providers', ['google']);

        $user = User::sole();
        expect($user->role)->toBe(UserRole::Parent)
            ->and($user->password)->toBeNull()
            ->and($user->hasPassword())->toBeFalse()
            ->and($user->terms_accepted_at)->not->toBeNull()
            ->and($user->email_verified_at)->toBeNull();

        $membership = FamilyMember::where('user_id', $user->id)->sole();
        expect($membership->role)->toBe(FamilyRole::Parent)
            ->and(Family::find($membership->family_id)->timezone)->toBe('America/New_York');

        $link = UserSocialAccount::sole();
        expect($link->user_id)->toBe($user->id)
            ->and($link->provider)->toBe(SocialProvider::Google)
            ->and($link->subject)->toBe('110000000000000000001')
            ->and($link->email)->toBe('ana.novak@gmail.com');

        $token = PersonalAccessToken::findToken($res->json('token'));
        expect($token)->not->toBeNull()
            ->and($token->tokenable_id)->toBe($user->id)
            ->and($token->abilities)->toBe(['parent']);
    });

    it('refuses to create an account without accepted terms and writes nothing', function () {
        ssGoogle(['terms_accepted' => false])->assertStatus(422)->assertJson(['code' => 'terms_required']);
        ssGoogle(['terms_accepted' => null])->assertStatus(422)->assertJson(['code' => 'terms_required']);

        expect(User::count())->toBe(0)
            ->and(UserSocialAccount::count())->toBe(0)
            ->and(Family::count())->toBe(0)
            ->and(PersonalAccessToken::count())->toBe(0);
    });

    it('signs the same identity in again without creating anything (terms not needed)', function () {
        $first = ssGoogle()->assertOk()->assertJsonPath('created', true);

        ssGoogle(['terms_accepted' => false], ['email' => 'changed@gmail.com', 'name' => 'Other Name'])->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('user.id', $first->json('user.id'))
            ->assertJsonPath('user.name', 'Ana Novak');

        expect(User::count())->toBe(1)
            ->and(UserSocialAccount::count())->toBe(1)
            ->and(PersonalAccessToken::count())->toBe(2);
    });

    it('ships PetPrep\'s Google client ids as the default (tests always set the list explicitly)', function () {
        $services = require config_path('services.php');

        expect(env('GOOGLE_CLIENT_IDS'))->toBeNull()
            ->and($services['google']['client_ids'])->toBe([
                '1029107925972-jcets5rq4a03c00okr54e55jvluj2giv.apps.googleusercontent.com',
                '1029107925972-fm90sk3l07phkqbkfcnjli2jddp4bqfu.apps.googleusercontent.com',
                '1029107925972-42jsoh8vvtsjv4tk8398o4g1mrp94mib.apps.googleusercontent.com',
            ]);
    });

    it('accepts any configured Google client id as audience', function () {
        ssGoogle([], ['aud' => 'test-ios-client.apps.googleusercontent.com'])->assertOk()->assertJsonPath('created', true);
    });

    it('signs up with Apple on the default audience (bundle id), name from the app, relay e-mail stored', function () {
        expect(config('services.apple.audiences'))->toBe(['com.datavallis.petprep']);
        // Every Http call of this file goes to the fakes (preventStrayRequests in ssRefake).

        ssApple()->assertOk()
            ->assertJsonPath('created', true)
            ->assertJsonPath('user.name', 'Ana')
            ->assertJsonPath('user.email', 'x7k2p9@privaterelay.appleid.com');

        expect(UserSocialAccount::sole()->provider)->toBe(SocialProvider::Apple);
    });

    it('falls back to the e-mail local part when neither token nor app give a name', function () {
        ssApple(['name' => null])->assertOk()->assertJsonPath('user.name', 'x7k2p9');
    });

    it('creates the account without an e-mail when the provider did not verify it', function () {
        ssGoogle([], ['email_verified' => false])->assertOk()
            ->assertJsonPath('created', true)
            ->assertJsonPath('user.email', null);

        expect(User::sole()->email)->toBeNull();
    });
});

describe('POST /api/auth/social — token verification', function () {
    it('rejects an invalid token with 401 invalid_token and creates nothing', function (Closure $token) {
        ssPost([
            'provider' => 'google',
            'id_token' => $token(),
            'timezone' => 'Europe/Ljubljana',
            'terms_accepted' => true,
        ])->assertStatus(401)->assertJson(['code' => 'invalid_token']);

        expect(User::count())->toBe(0)->and(UserSocialAccount::count())->toBe(0);
    })->with([
        'wrong audience' => [fn () => ssToken('google', ['aud' => 'someone-elses-client.apps.googleusercontent.com'])],
        'wrong issuer' => [fn () => ssToken('google', ['iss' => 'https://evil.example.com'])],
        'Apple token sent as Google' => [fn () => ssToken('apple')],
        'expired' => [fn () => ssToken('google', ['iat' => now()->getTimestamp() - 7200, 'exp' => now()->getTimestamp() - 120])],
        'issued in the future' => [fn () => ssToken('google', ['iat' => now()->getTimestamp() + 600])],
        'no exp' => [fn () => ssToken('google', ['exp' => null])],
        'no sub' => [fn () => ssToken('google', ['sub' => null])],
        'bad signature (other key, same kid)' => [fn () => ssToken('google', [], 'kid-1', 'attacker')],
        'unknown kid' => [fn () => ssToken('google', [], 'kid-unknown')],
        'tampered payload' => [function () {
            [$h, , $s] = explode('.', ssToken('google'));
            $forged = RsaJwt::base64UrlEncode(json_encode(['iss' => 'https://accounts.google.com', 'aud' => SS_GOOGLE_CLIENT,
                'sub' => 'victim', 'iat' => now()->getTimestamp(), 'exp' => now()->getTimestamp() + 600]));

            return "{$h}.{$forged}.{$s}";
        }],
        'alg none' => [fn () => RsaJwt::base64UrlEncode('{"alg":"none","kid":"kid-1"}').'.'.RsaJwt::base64UrlEncode('{"sub":"x"}').'.x'],
        'HS256 confusion' => [function () {
            $input = RsaJwt::base64UrlEncode('{"alg":"HS256","kid":"kid-1"}').'.'.RsaJwt::base64UrlEncode(json_encode(['sub' => 'x']));

            return $input.'.'.RsaJwt::base64UrlEncode(hash_hmac('sha256', $input, 'secret', true));
        }],
        'garbage' => [fn () => 'not-a-jwt'],
    ]);

    it('rejects a token that is not valid yet (nbf in the future)', function () {
        ssGoogle([], ['nbf' => now()->getTimestamp() + 600])->assertStatus(401)->assertJson(['code' => 'invalid_token']);
        ssGoogle([], ['nbf' => now()->getTimestamp() - 10])->assertOk();
    });

    it('rejects a token with a crit header', function () {
        ssPost(['provider' => 'google', 'id_token' => ssToken('google', [], 'kid-1', 'main', ['crit' => ['exp']]),
            'timezone' => 'Europe/Ljubljana', 'terms_accepted' => true])
            ->assertStatus(401)->assertJson(['code' => 'invalid_token']);
        expect(User::count())->toBe(0);
    });

    it('accepts an array aud that contains our client id, rejects one that does not', function () {
        ssGoogle([], ['sub' => 'arr-1', 'email' => 'arr1@gmail.com', 'aud' => ['other-client', SS_GOOGLE_CLIENT]])->assertOk();
        ssGoogle([], ['sub' => 'arr-2', 'email' => 'arr2@gmail.com', 'aud' => ['other-client', 'third-client']])
            ->assertStatus(401)->assertJson(['code' => 'invalid_token']);
        expect(User::count())->toBe(1);
    });

    it('accepts a token that expired within the 60 s leeway, not one beyond it', function () {
        $now = now()->getTimestamp();
        ssGoogle([], ['exp' => $now - 30])->assertOk();
        ssGoogle([], ['sub' => 'other', 'email' => 'b@gmail.com', 'exp' => $now - 61])->assertStatus(401);
    });

    it('rejects an Apple token whose nonce does not match sha256(raw nonce)', function () {
        ssApple(['nonce' => 'a-different-raw-nonce'])->assertStatus(401)->assertJson(['code' => 'invalid_token']);
        ssApple([], ['nonce' => null])->assertStatus(401)->assertJson(['code' => 'invalid_token']);
        // The hash itself is not the raw nonce.
        ssApple(['nonce' => hash('sha256', SS_APPLE_NONCE)])->assertStatus(401);

        expect(User::count())->toBe(0);
    });

    it('requires the raw nonce for Apple (validation), not for Google', function () {
        ssApple(['nonce' => null])->assertStatus(422)->assertJsonValidationErrors('nonce');
        ssGoogle(['nonce' => null])->assertOk();
    });

    it('answers 503 provider_disabled without any network call when no Google client id is configured', function () {
        config(['services.google.client_ids' => []]);

        ssGoogle()->assertStatus(503)->assertJson(['code' => 'provider_disabled']);

        Http::assertNothingSent();
        expect(User::count())->toBe(0);
    });

    it('can disable Apple with an empty audience list', function () {
        config(['services.apple.audiences' => []]);

        ssApple()->assertStatus(503)->assertJson(['code' => 'provider_disabled']);

        Http::assertNothingSent();
        expect(User::count())->toBe(0);
    });

    it('answers 503 provider_unavailable when the provider keys cannot be fetched', function () {
        ssRefake([SS_GOOGLE_JWKS => fn () => throw new ConnectionException('timeout')]);
        ssGoogle()->assertStatus(503)->assertJson(['code' => 'provider_unavailable']);

        expect(User::count())->toBe(0);
    });

    it('caches the JWKS (one fetch for several sign-ins) and re-fetches once for a rotated key', function () {
        ssRefake([SS_GOOGLE_JWKS => Http::sequence()
            ->push(['keys' => [ssJwk('kid-1')]], 200, ['Cache-Control' => 'max-age=3600'])
            ->push(['keys' => [ssJwk('kid-1'), ssJwk('kid-2', 'rotated')]], 200)]);

        ssGoogle()->assertOk();
        ssGoogle()->assertOk();
        Http::assertSentCount(1);

        // Google rotated its keys: a token with the new kid forces one re-fetch
        // (at most one forced fetch per minute — the first fetch counts too).
        $this->travel(2)->minutes();
        ssPost(['provider' => 'google', 'id_token' => ssToken('google', ['sub' => 'rotated-user', 'email' => 'r@gmail.com'], 'kid-2', 'rotated'),
            'timezone' => 'Europe/Ljubljana', 'terms_accepted' => true])->assertOk();
        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === SS_GOOGLE_JWKS);

        // A second unknown kid within the refresh interval does not hammer Google.
        ssPost(['provider' => 'google', 'id_token' => ssToken('google', [], 'kid-3'), 'timezone' => 'Europe/Ljubljana'])
            ->assertStatus(401);
        Http::assertSentCount(2);
    });

    it('validates the body', function () {
        ssPost(['provider' => 'facebook', 'id_token' => 'x', 'timezone' => 'Europe/Ljubljana'])
            ->assertStatus(422)->assertJsonValidationErrors('provider');
        ssPost(['provider' => 'google', 'timezone' => 'Europe/Ljubljana'])
            ->assertStatus(422)->assertJsonValidationErrors('id_token');
        ssPost(['provider' => 'google', 'id_token' => str_repeat('a', 4097), 'timezone' => 'Europe/Ljubljana'])
            ->assertStatus(422)->assertJsonValidationErrors('id_token');
        ssPost(['provider' => 'google', 'id_token' => ssToken()])
            ->assertStatus(422)->assertJsonValidationErrors('timezone');
        ssGoogle(['locale' => 'xx'])->assertStatus(422)->assertJsonValidationErrors('locale');
        ssGoogle(['name' => str_repeat('a', 61)])->assertStatus(422)->assertJsonValidationErrors('name');
        expect(User::count())->toBe(0);
    });
});

describe('POST /api/auth/social — linking rules', function () {
    it('never links to an account that has a password: 409 email_in_use (only this cause), nothing linked', function () {
        $existing = User::factory()->parent()->create(['email' => 'ana.novak@gmail.com', 'password' => 'Varno1Geslo']);

        ssGoogle()->assertStatus(409)->assertJson(['code' => 'email_in_use']);

        expect(UserSocialAccount::count())->toBe(0)
            ->and(User::count())->toBe(1)
            ->and($existing->fresh()->hasPassword())->toBeTrue()
            ->and(PersonalAccessToken::count())->toBe(0);
    });

    it('matches the e-mail case-insensitively (legacy mixed-case rows)', function () {
        User::factory()->parent()->create(['email' => 'Ana.Novak@gmail.com', 'password' => 'Varno1Geslo']);

        ssGoogle()->assertStatus(409)->assertJson(['code' => 'email_in_use']);
    });

    it('links Apple to the password-less account Google created when Apple verified the same e-mail', function () {
        $first = ssGoogle()->assertOk();

        ssApple(['terms_accepted' => false], ['email' => 'ana.novak@gmail.com', 'email_verified' => true])->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('user.id', $first->json('user.id'));

        expect(User::count())->toBe(1)
            ->and(UserSocialAccount::where('user_id', $first->json('user.id'))->get()->map(fn ($a) => $a->provider->value)->sort()->values()->all())
            ->toBe(['apple', 'google']);
    });

    it('does not link on an unverified e-mail: 409 email_link_unavailable', function () {
        ssApple(['terms_accepted' => true], ['email' => 'ana.novak@gmail.com']);
        expect(User::count())->toBe(1);

        ssGoogle([], ['email_verified' => false])->assertStatus(409)->assertJson(['code' => 'email_link_unavailable']);
        ssGoogle([], ['email_verified' => null])->assertStatus(409)->assertJson(['code' => 'email_link_unavailable']);

        expect(UserSocialAccount::count())->toBe(1)->and(User::count())->toBe(1);
    });

    it('does not link a verified non-Gmail Google address without hd (Google not authoritative): 409 email_link_unavailable', function () {
        ssApple([], ['email' => 'ana@firma.si', 'email_verified' => true])->assertOk();

        ssGoogle([], ['email' => 'ana@firma.si', 'email_verified' => true])
            ->assertStatus(409)->assertJson(['code' => 'email_link_unavailable']);

        expect(UserSocialAccount::count())->toBe(1)->and(User::count())->toBe(1);
    });

    it('links a Google Workspace address (hd claim) to the password-less account', function () {
        $first = ssApple([], ['email' => 'ana@firma.si', 'email_verified' => true])->assertOk();

        ssGoogle(['terms_accepted' => false], ['email' => 'ana@firma.si', 'hd' => 'firma.si'])->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('user.id', $first->json('user.id'))
            ->assertJsonPath('user.linked_providers', ['apple', 'google']);
    });

    it('a new account from a non-authoritative Google address stores no users.email; the link keeps the verified address', function () {
        ssGoogle([], ['email' => 'ana@firma.si'])->assertOk()->assertJsonPath('user.email', null);

        expect(User::sole()->email)->toBeNull()
            ->and(UserSocialAccount::sole()->email)->toBe('ana@firma.si');

        // A later Apple sign-in with the same address cannot reach that account.
        ssApple([], ['email' => 'ana@firma.si', 'email_verified' => true])->assertOk()->assertJsonPath('created', true);
        expect(User::count())->toBe(2);
    });

    it('stores no e-mail on the link when the provider did not verify it', function () {
        ssGoogle([], ['email_verified' => false])->assertOk();

        expect(UserSocialAccount::sole()->email)->toBeNull();
    });

    it('does not attach a second identity of the same provider to an account by e-mail', function () {
        ssGoogle()->assertOk();

        ssGoogle([], ['sub' => '110000000000000000999'])->assertStatus(409)->assertJson(['code' => 'email_link_unavailable']);
        expect(UserSocialAccount::count())->toBe(1);
    });

    it('refuses a child account with that e-mail: 403 not_a_parent', function () {
        User::factory()->child()->create(['email' => 'ana.novak@gmail.com', 'password' => null]);

        ssGoogle()->assertStatus(403)->assertJson(['code' => 'not_a_parent']);
        expect(UserSocialAccount::count())->toBe(0);
    });

    it('refuses a superadmin: 403 superadmin_protected, also when already linked', function () {
        $admin = User::factory()->parent()->create(['email' => 'ana.novak@gmail.com', 'password' => null, 'is_superadmin' => true]);

        ssGoogle()->assertStatus(403)->assertJson(['code' => 'superadmin_protected']);

        UserSocialAccount::create(['user_id' => $admin->id, 'provider' => 'google', 'subject' => '110000000000000000001']);
        ssGoogle()->assertStatus(403)->assertJson(['code' => 'superadmin_protected']);
        expect(PersonalAccessToken::count())->toBe(0);
    });
});

describe('password login and a password-less parent', function () {
    it('POST /api/login answers invalid credentials (no exception) for a social-only parent', function () {
        ssGoogle()->assertOk();

        app('auth')->forgetGuards();
        postJson('/api/login', ['email' => 'ana.novak@gmail.com', 'password' => 'anything'])
            ->assertStatus(401)->assertJson(['message' => 'Invalid credentials.']);
        postJson('/api/login', ['email' => 'ana.novak@gmail.com', 'password' => ''])->assertStatus(422);
    });

    it('shows has_password and linked_providers on /api/login, /api/register and /api/user', function () {
        $parent = User::factory()->parent()->create(['email' => 'mama@example.com', 'password' => 'Varno1Geslo']);
        UserSocialAccount::create(['user_id' => $parent->id, 'provider' => 'apple', 'subject' => 'apple-sub']);

        app('auth')->forgetGuards();
        $login = postJson('/api/login', ['email' => 'mama@example.com', 'password' => 'Varno1Geslo'])->assertOk()
            ->assertJsonPath('user.has_password', true)
            ->assertJsonPath('user.linked_providers', ['apple']);

        app('auth')->forgetGuards();
        test()->withHeaders(['Authorization' => 'Bearer '.$login->json('token')]);
        getJson('/api/user')->assertOk()
            ->assertJsonPath('has_password', true)
            ->assertJsonPath('linked_providers', ['apple']);

        app('auth')->forgetGuards();
        test()->withHeaders(['Authorization' => ''])->withServerVariables(['REMOTE_ADDR' => '198.51.100.140']);
        postJson('/api/register', ['name' => 'Oče', 'email' => 'oce@example.com', 'password' => 'Varno1Geslo',
            'password_confirmation' => 'Varno1Geslo', 'timezone' => 'Europe/Ljubljana', 'accept_terms' => true])
            ->assertCreated()
            ->assertJsonPath('user.has_password', true)
            ->assertJsonPath('user.linked_providers', []);

        $social = ssGoogle()->assertOk();
        app('auth')->forgetGuards();
        test()->withHeaders(['Authorization' => 'Bearer '.$social->json('token')]);
        getJson('/api/user')->assertOk()
            ->assertJsonPath('has_password', false)
            ->assertJsonPath('linked_providers', ['google']);
    });

    it('the export lists the linked providers without the provider user id', function () {
        ssGoogle()->assertOk();
        $user = User::sole();

        app('auth')->forgetGuards();
        actingAsRole($user);
        $export = getJson('/api/parent/account/export')->assertOk();

        $export->assertJsonPath('parents.0.password_login', false)
            ->assertJsonPath('parents.0.linked_providers.0.provider', 'google')
            ->assertJsonPath('parents.0.linked_providers.0.email', 'ana.novak@gmail.com');
        expect($export->getContent())->not->toContain('110000000000000000001');
    });
});

/* ─────────────────────── Deletions: re-authentication (M2-08 + M2-10c) ─────────────────────── */

function ssSocialParent(): User
{
    ssGoogle()->assertOk();

    return User::sole();
}

function ssDeleteAccount(User $parent, array $body): TestResponse
{
    app('auth')->forgetGuards();
    test()->withServerVariables(['REMOTE_ADDR' => '198.51.100.130']);
    actingAsRole($parent);

    return postJson('/api/parent/account/delete', array_merge(['confirm' => true], $body));
}

describe('deletions for a parent without a password', function () {
    it('deletes the account with a fresh token of the linked provider; links go with it', function () {
        $parent = ssSocialParent();
        $this->travel(5)->minutes();

        ssDeleteAccount($parent, ['provider' => 'google', 'id_token' => ssToken('google')])
            ->assertOk()->assertJsonPath('status', 'deleted');

        expect(User::find($parent->id))->toBeNull()
            ->and(UserSocialAccount::count())->toBe(0);
    });

    it('refuses a token older than 10 minutes with reauth_required', function () {
        $parent = ssSocialParent();
        $stale = ssToken('google', ['iat' => now()->getTimestamp() - 11 * 60]);

        ssDeleteAccount($parent, ['provider' => 'google', 'id_token' => $stale])
            ->assertStatus(422)->assertJson(['reason' => 'reauth_required']);

        expect(User::find($parent->id))->not->toBeNull();
    });

    it('refuses a valid token of a provider identity that is not linked to this parent', function () {
        $parent = ssSocialParent();

        ssDeleteAccount($parent, ['provider' => 'apple', 'id_token' => ssToken('apple'), 'nonce' => SS_APPLE_NONCE])
            ->assertStatus(422)->assertJson(['reason' => 'reauth_required']);
        ssDeleteAccount($parent, ['provider' => 'google', 'id_token' => ssToken('google', ['sub' => 'someone-else'])])
            ->assertStatus(422)->assertJson(['reason' => 'reauth_required']);
        ssDeleteAccount($parent, ['provider' => 'google', 'id_token' => ssToken('google', [], 'kid-1', 'attacker')])
            ->assertStatus(422)->assertJson(['reason' => 'reauth_required']);

        expect(User::find($parent->id))->not->toBeNull();
    });

    it('requires a password or a provider token; a password alone is refused as before', function () {
        $parent = ssSocialParent();

        ssDeleteAccount($parent, [])->assertStatus(422)->assertJsonValidationErrors('password');
        ssDeleteAccount($parent, ['id_token' => ssToken('google')])->assertStatus(422)->assertJsonValidationErrors('provider');
        ssDeleteAccount($parent, ['password' => 'guess'])->assertStatus(422)->assertJson(['reason' => 'invalid_password']);

        expect(User::find($parent->id))->not->toBeNull();
    });

    it('counts failed re-authentications in the 5-per-15-minutes lockout', function () {
        $parent = ssSocialParent();
        $stale = ssToken('google', ['iat' => now()->getTimestamp() - 3600]);

        foreach (range(1, 5) as $i) {
            ssDeleteAccount($parent, ['provider' => 'google', 'id_token' => $stale])->assertStatus(422);
        }
        ssDeleteAccount($parent, ['provider' => 'google', 'id_token' => ssToken('google')])
            ->assertStatus(429)->assertHeader('Retry-After');

        expect(User::find($parent->id))->not->toBeNull();
    });

    it('does not count a 503 provider_unavailable in the lockout', function () {
        $parent = ssSocialParent();
        $token = ssToken('google');
        // Keys cached by the sign-in → drop them so the deletion must fetch again.
        cache()->flush();
        ssRefake([SS_GOOGLE_JWKS => fn () => throw new ConnectionException('down')]);

        foreach (range(1, 6) as $i) {
            ssDeleteAccount($parent, ['provider' => 'google', 'id_token' => $token])
                ->assertStatus(503)->assertJson(['reason' => 'provider_unavailable']);
        }

        ssFakeProviders();
        ssDeleteAccount($parent, ['provider' => 'google', 'id_token' => $token])->assertOk();
        expect(User::find($parent->id))->toBeNull();
    });

    it('accepts an Apple authorization_code (reserved, unused) and refuses it for Google or when too long', function () {
        ssApple()->assertOk();
        $parent = User::sole();

        ssDeleteAccount($parent, ['provider' => 'google', 'id_token' => ssToken('google'), 'authorization_code' => 'c0de'])
            ->assertStatus(422)->assertJsonValidationErrors('authorization_code');
        ssDeleteAccount($parent, ['provider' => 'apple', 'id_token' => ssToken('apple'), 'nonce' => SS_APPLE_NONCE, 'authorization_code' => str_repeat('a', 2049)])
            ->assertStatus(422)->assertJsonValidationErrors('authorization_code');
        expect(User::find($parent->id))->not->toBeNull();

        ssDeleteAccount($parent, ['provider' => 'apple', 'id_token' => ssToken('apple'), 'nonce' => SS_APPLE_NONCE, 'authorization_code' => 'c0de.apple'])
            ->assertOk();
        expect(User::find($parent->id))->toBeNull();
    });

    it('deletes a child profile with a fresh provider token', function () {
        $parent = ssSocialParent();
        $child = User::factory()->pinOnlyChild()->create(['parent_id' => $parent->id, 'name' => 'Maja']);

        app('auth')->forgetGuards();
        actingAsRole($parent);
        deleteJson("/api/parent/children/{$child->id}", ['confirm' => true, 'provider' => 'google', 'id_token' => ssToken('google', ['iat' => now()->getTimestamp() - 3600])])
            ->assertStatus(422)->assertJson(['reason' => 'reauth_required']);
        expect(User::find($child->id))->not->toBeNull();

        deleteJson("/api/parent/children/{$child->id}", ['confirm' => true, 'provider' => 'google', 'id_token' => ssToken('google')])
            ->assertOk()->assertJsonPath('status', 'deleted');
        expect(User::find($child->id))->toBeNull();
    });

    it('a parent WITH a password still confirms with the password; a provider token does not replace it', function () {
        $parent = User::factory()->parent()->create(['email' => 'mama@example.com', 'password' => 'password']);
        UserSocialAccount::create(['user_id' => $parent->id, 'provider' => 'google', 'subject' => '110000000000000000001']);

        ssDeleteAccount($parent, ['provider' => 'google', 'id_token' => ssToken('google')])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        ssDeleteAccount($parent, ['password' => 'wrong', 'provider' => 'google', 'id_token' => ssToken('google')])
            ->assertStatus(422)->assertJson(['reason' => 'invalid_password']);
        expect(User::find($parent->id))->not->toBeNull();

        ssDeleteAccount($parent, ['password' => 'password'])->assertOk();
        expect(User::find($parent->id))->toBeNull();
    });
});

describe('throttle', function () {
    it('limits POST /api/auth/social to 10 per minute per IP', function () {
        config(['services.google.client_ids' => []]); // cheap requests, no network

        foreach (range(1, AppServiceProvider::SOCIAL_SIGN_IN_PER_MINUTE) as $i) {
            ssGoogle([], [], '203.0.113.90')->assertStatus(503);
        }
        ssGoogle([], [], '203.0.113.90')->assertStatus(429)->assertHeader('Retry-After');
        ssGoogle([], [], '203.0.113.91')->assertStatus(503);
    });
});

describe('RsaJwt', function () {
    it('builds a PEM that openssl reads back as the same RSA key', function () {
        $key = RsaJwt::publicKeyFromJwk(ssJwk('k'));
        expect($key)->not->toBeNull()
            ->and(openssl_pkey_get_details($key)['rsa']['n'])->toBe(openssl_pkey_get_details(ssKey())['rsa']['n']);
    });

    it('rejects small keys and non-RSA / non-signing JWKs', function () {
        $small = openssl_pkey_get_details(openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]))['rsa'];
        $jwk = ['kty' => 'RSA', 'n' => RsaJwt::base64UrlEncode($small['n']), 'e' => RsaJwt::base64UrlEncode($small['e'])];

        expect(RsaJwt::publicKeyFromJwk($jwk))->toBeNull()
            ->and(RsaJwt::publicKeyFromJwk(['kty' => 'EC'] + ssJwk('k')))->toBeNull()
            ->and(RsaJwt::publicKeyFromJwk(['use' => 'enc'] + ssJwk('k')))->toBeNull()
            ->and(RsaJwt::publicKeyFromJwk(['alg' => 'RS512'] + ssJwk('k')))->toBeNull();
    });
});
