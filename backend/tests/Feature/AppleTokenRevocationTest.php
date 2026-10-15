<?php

use App\Jobs\RevokeAppleRefreshTokenJob;
use App\Jobs\RevokeAppleTokenJob;
use App\Models\Pet;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Services\AccountDeletionService;
use App\Services\Auth\AppleClientSecret;
use App\Services\Auth\RsaJwt;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\postJson;

/*
| M2-10c — Apple token revocation when a parent account is deleted (App Store
| guideline 5.1.1(v)). Keys are generated at runtime (RSA for the Apple ID
| token, EC P-256 for the Sign in with Apple .p8); nothing is committed and no
| real network is used.
*/

const ATR_JWKS = 'https://appleid.apple.com/auth/keys';
const ATR_TOKEN_URL = 'https://appleid.apple.com/auth/token';
const ATR_REVOKE_URL = 'https://appleid.apple.com/auth/revoke';
const ATR_NONCE = 'raw-nonce-revocation';
const ATR_SUB = '000999.revocation.0001';
const ATR_CODE = 'c1a2b3.0.secret-apple-authorization-code';

function atrRsa(): OpenSSLAsymmetricKey
{
    static $key;

    return $key ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
}

/** A runtime P-256 key pair: [private PEM, public key]. */
function atrEcKey(): array
{
    static $pair;
    if ($pair === null) {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        $pair = [$pem, openssl_pkey_get_public(openssl_pkey_get_details($key)['key'])];
    }

    return $pair;
}

/** Configure the Sign in with Apple key as production does: base64 of the .p8 file. */
function atrConfigureKey(): void
{
    config(['services.apple.private_key' => base64_encode(atrEcKey()[0])]);
}

/** @param  array<string, mixed>  $claims */
function atrIdToken(array $claims = []): string
{
    $now = now()->getTimestamp();
    $payload = array_merge(['iss' => 'https://appleid.apple.com', 'aud' => 'com.datavallis.petprep', 'sub' => ATR_SUB,
        'email' => 'relay@privaterelay.appleid.com', 'email_verified' => 'true', 'nonce' => hash('sha256', ATR_NONCE),
        'iat' => $now, 'exp' => $now + 600], $claims);
    $input = RsaJwt::base64UrlEncode(json_encode(['alg' => 'RS256', 'kid' => 'atr-kid']))
        .'.'.RsaJwt::base64UrlEncode(json_encode($payload));
    openssl_sign($input, $sig, atrRsa(), OPENSSL_ALGO_SHA256);

    return $input.'.'.RsaJwt::base64UrlEncode($sig);
}

/** @param  array<string, mixed>  $stubs  extra fakes (Apple token / revoke) */
function atrFake(array $stubs = []): void
{
    $rsa = openssl_pkey_get_details(atrRsa())['rsa'];
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    Http::fake(array_merge([
        ATR_JWKS => Http::response(['keys' => [['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'atr-kid',
            'n' => RsaJwt::base64UrlEncode($rsa['n']), 'e' => RsaJwt::base64UrlEncode($rsa['e'])]]]),
    ], $stubs));
}

/** Run a job's handle() through the container (its dependencies resolved). */
function atrRun(object $job): void
{
    app()->call([$job, 'handle']);
}

/**
 * Apple's /auth/token answer with an id_token for $sub (unsigned is fine — the
 * job reads it without verification).
 *
 * @param  array<string, mixed>  $body
 */
function atrTokenResponse(array $body = ['refresh_token' => 'ref-1'], ?string $sub = ATR_SUB): array
{
    if ($sub !== null) {
        $body['id_token'] = RsaJwt::base64UrlEncode('{"alg":"RS256","kid":"apple"}').'.'
            .RsaJwt::base64UrlEncode(json_encode(['iss' => 'https://appleid.apple.com', 'aud' => 'com.datavallis.petprep', 'sub' => $sub])).'.sig';
    }

    return $body;
}

/** @return list<array{0: string, 1: string}> [level, line] of every log line written during $fn */
function atrLevels(Closure $fn): array
{
    $lines = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$lines) {
        $lines[] = [$e->level, $e->message.' '.json_encode($e->context)];
    });
    $fn();

    return $lines;
}

/** A password-less parent signed up with Apple (+ optionally Google). */
function atrAppleParent(): User
{
    $parent = User::factory()->parent()->create(['email' => 'relay@privaterelay.appleid.com', 'password' => null]);
    UserSocialAccount::create(['user_id' => $parent->id, 'provider' => 'apple', 'subject' => ATR_SUB]);

    return $parent;
}

function atrDelete(User $parent, array $body): TestResponse
{
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/account/delete', array_merge(['confirm' => true], $body));
}

/** @return list<string> every log line (message + context) written during $fn */
function atrLogs(Closure $fn): array
{
    $lines = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$lines) {
        $lines[] = $e->level.' '.$e->message.' '.json_encode($e->context);
    });
    $fn();

    return $lines;
}

beforeEach(function () {
    atrFake();
    config(['services.apple.private_key' => null]);
    Sleep::fake();
});

/* ─────────────────────────── Dispatch on account deletion ─────────────────────────── */

describe('account deletion → RevokeAppleTokenJob', function () {
    it('queues the revocation (encrypted job) for an Apple-linked parent who sent the authorization code', function () {
        Queue::fake([RevokeAppleTokenJob::class]);
        $parent = atrAppleParent();

        atrDelete($parent, ['provider' => 'apple', 'id_token' => atrIdToken(), 'nonce' => ATR_NONCE, 'authorization_code' => ATR_CODE])
            ->assertOk()->assertJsonPath('status', 'deleted');

        expect(User::find($parent->id))->toBeNull();
        Queue::assertPushed(RevokeAppleTokenJob::class, 1);
        Queue::assertPushedOn('auth', RevokeAppleTokenJob::class);
        expect(new RevokeAppleTokenJob('x'))->toBeInstanceOf(ShouldBeEncrypted::class)
            ->and(new RevokeAppleRefreshTokenJob('t', 'refresh_token', 'c'))->toBeInstanceOf(ShouldBeEncrypted::class);
    });

    it('queues it even without a configured key (the job no-ops then)', function () {
        Queue::fake([RevokeAppleTokenJob::class]);
        expect(app(AppleClientSecret::class)->isConfigured())->toBeFalse();

        atrDelete(atrAppleParent(), ['provider' => 'apple', 'id_token' => atrIdToken(), 'nonce' => ATR_NONCE, 'authorization_code' => ATR_CODE])
            ->assertOk();

        Queue::assertPushed(RevokeAppleTokenJob::class, 1);
    });

    it('skips with a neutral log line when an Apple-linked parent sends no code (older app)', function () {
        Queue::fake([RevokeAppleTokenJob::class]);
        $parent = atrAppleParent();

        $logs = atrLogs(fn () => atrDelete($parent, ['provider' => 'apple', 'id_token' => atrIdToken(), 'nonce' => ATR_NONCE])->assertOk());

        Queue::assertNothingPushed();
        expect(collect($logs)->contains(fn ($l) => str_contains($l, 'Apple token revocation skipped: no authorization code')))->toBeTrue();
    });

    it('does not queue for a Google-only parent, a password parent without an Apple link, a refused deletion or a child deletion', function () {
        Queue::fake([RevokeAppleTokenJob::class]);

        // Password parent, no Apple link, sends Apple fields anyway (password path).
        $pw = User::factory()->parent()->create(['email' => 'pw@example.com', 'password' => 'password']);
        atrDelete($pw, ['password' => 'password', 'provider' => 'apple', 'authorization_code' => ATR_CODE])->assertOk();

        // Apple-linked parent, but the re-authentication fails → nothing deleted, nothing queued.
        $apple = atrAppleParent();
        atrDelete($apple, ['provider' => 'apple', 'id_token' => atrIdToken(['iat' => now()->getTimestamp() - 3600]), 'nonce' => ATR_NONCE, 'authorization_code' => ATR_CODE])
            ->assertStatus(422)->assertJson(['reason' => 'reauth_required']);

        // Child deletion by the Apple-linked parent: the parent account stays → no revocation.
        $child = User::factory()->pinOnlyChild()->create(['parent_id' => $apple->id, 'name' => 'Maja']);
        app('auth')->forgetGuards();
        actingAsRole($apple);
        deleteJson("/api/parent/children/{$child->id}", ['confirm' => true, 'provider' => 'apple', 'id_token' => atrIdToken(),
            'nonce' => ATR_NONCE, 'authorization_code' => ATR_CODE])->assertOk();
        expect(User::find($child->id))->toBeNull()->and(User::find($apple->id))->not->toBeNull();

        Queue::assertNothingPushed();
    });

    it('a Google-linked parent cannot send an authorization code; Google deletion queues nothing', function () {
        Queue::fake([RevokeAppleTokenJob::class]);
        $parent = User::factory()->parent()->create(['email' => 'g@gmail.com', 'password' => null]);
        UserSocialAccount::create(['user_id' => $parent->id, 'provider' => 'google', 'subject' => 'g-sub']);

        atrDelete($parent, ['provider' => 'google', 'id_token' => 'x', 'authorization_code' => ATR_CODE])
            ->assertStatus(422)->assertJsonValidationErrors('authorization_code');
        Queue::assertNothingPushed();
    });
});

/* ─────────────────────────── client_secret (ES256) ─────────────────────────── */

describe('AppleClientSecret', function () {
    it('builds an ES256 JWT that verifies with the matching public key', function () {
        atrConfigureKey();
        $secrets = app(AppleClientSecret::class);
        expect($secrets->isConfigured())->toBeTrue();

        [$h, $p, $s] = explode('.', $secrets->make('com.datavallis.petprep'));
        $header = json_decode(RsaJwt::base64UrlDecode($h), true);
        $claims = json_decode(RsaJwt::base64UrlDecode($p), true);
        $raw = RsaJwt::base64UrlDecode($s);

        expect($header)->toBe(['alg' => 'ES256', 'kid' => 'Z8WR3WL68G'])
            ->and($claims['iss'])->toBe('4345XU4MU8')
            ->and($claims['sub'])->toBe('com.datavallis.petprep')
            ->and($claims['aud'])->toBe('https://appleid.apple.com')
            ->and($claims['iat'])->toBe(now()->getTimestamp())
            ->and($claims['exp'])->toBe(now()->getTimestamp() + 300)
            ->and(strlen($raw))->toBe(64)
            ->and(openssl_verify("{$h}.{$p}", AppleClientSecret::rawToDer($raw), atrEcKey()[1], OPENSSL_ALGO_SHA256))->toBe(1);

        // Another key does not verify it.
        $other = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $otherPublic = openssl_pkey_get_public(openssl_pkey_get_details($other)['key']);
        expect(openssl_verify("{$h}.{$p}", AppleClientSecret::rawToDer($raw), $otherPublic, OPENSSL_ALGO_SHA256))->toBe(0);
    });

    it('uses APPLE_CLIENT_ID / team / key overrides', function () {
        atrConfigureKey();
        config(['services.apple.client_id' => 'si.petprep.services', 'services.apple.team_id' => 'TEAM123', 'services.apple.key_id' => 'KEY456']);

        [$h, $p] = explode('.', app(AppleClientSecret::class)->make(app(AppleClientSecret::class)->clientId('com.datavallis.petprep')));
        expect(json_decode(RsaJwt::base64UrlDecode($h), true)['kid'])->toBe('KEY456')
            ->and(json_decode(RsaJwt::base64UrlDecode($p), true))->toMatchArray(['iss' => 'TEAM123', 'sub' => 'si.petprep.services']);
    });

    it('accepts the key as raw PEM with \n escapes; rejects garbage, a non-EC key and a missing key', function () {
        config(['services.apple.private_key' => str_replace("\n", '\n', trim(atrEcKey()[0]))]);
        expect(app(AppleClientSecret::class)->isConfigured())->toBeTrue();

        openssl_pkey_export(atrRsa(), $rsaPem);
        foreach ([null, '', 'not-base64-!!', base64_encode('hello'), base64_encode($rsaPem)] as $value) {
            config(['services.apple.private_key' => $value]);
            expect(app(AppleClientSecret::class)->isConfigured())->toBeFalse();
        }
        expect(fn () => app(AppleClientSecret::class)->make('com.datavallis.petprep'))->toThrow(RuntimeException::class);
    });

    it('converts DER ↔ raw for many real signatures (short and high-bit R / S included)', function () {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $public = openssl_pkey_get_public(openssl_pkey_get_details($key)['key']);
        $lengths = [];

        for ($i = 0; $i < 300; $i++) {
            openssl_sign("message {$i}", $der, $key, OPENSSL_ALGO_SHA256);
            $raw = AppleClientSecret::derToRaw($der);
            $lengths[strlen($der)] = true;

            expect(strlen($raw))->toBe(64)
                ->and(openssl_verify("message {$i}", AppleClientSecret::rawToDer($raw), $public, OPENSSL_ALGO_SHA256))->toBe(1)
                ->and(AppleClientSecret::rawToDer($raw))->toBe($der);
        }
        // DER lengths vary (70–72 bytes) — the padding / 0x00 cases were exercised.
        expect(count($lengths))->toBeGreaterThan(1);
    });

    it('pads a short integer, strips the DER sign byte and rejects malformed input', function () {
        $r = "\x01\x02"; // 2 bytes → left-padded
        $s = "\x00\x80".str_repeat("\x11", 31); // 0x00 sign byte + 32 bytes
        $der = "\x30".chr(4 + strlen($r) + strlen($s))."\x02".chr(strlen($r)).$r."\x02".chr(strlen($s)).$s;

        $raw = AppleClientSecret::derToRaw($der);
        expect(bin2hex(substr($raw, 0, 32)))->toBe(str_repeat('00', 30).'0102')
            ->and(bin2hex(substr($raw, 32)))->toBe('80'.str_repeat('11', 31))
            ->and(AppleClientSecret::rawToDer($raw))->toBe($der);

        // Long-form sequence length (0x81) is understood.
        $long = "\x30\x81".chr(4 + strlen($r) + strlen($s)).substr($der, 2);
        expect(AppleClientSecret::derToRaw($long))->toBe($raw);

        foreach ([
            '',
            "\x31\x06\x02\x01\x01\x02\x01\x01",                     // not a sequence
            "\x30\x06\x02\x01\x01\x02\x01",                         // truncated
            "\x30\x06\x02\x01\x01\x02\x01\x01\x00",                 // trailing byte
            "\x30\x06\x04\x01\x01\x02\x01\x01",                     // not an integer
            "\x30\x25\x02\x21".str_repeat("\x7f", 33)."\x02\x00",  // integer > 32 bytes
        ] as $bad) {
            expect(fn () => AppleClientSecret::derToRaw($bad))->toThrow(InvalidArgumentException::class);
        }
    });
});

/* ─────────────────────────── The job ─────────────────────────── */

describe('RevokeAppleTokenJob', function () {
    it('exchanges the code and revokes the refresh token with the right form fields', function () {
        atrConfigureKey();
        atrFake([
            ATR_TOKEN_URL => Http::response(['access_token' => 'acc-1', 'refresh_token' => 'ref-1', 'id_token' => 'x', 'token_type' => 'Bearer']),
            ATR_REVOKE_URL => Http::response('', 200),
        ]);

        atrRun(new RevokeAppleTokenJob(ATR_CODE));

        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === ATR_TOKEN_URL
            && $r->isForm()
            && $r['client_id'] === 'com.datavallis.petprep'
            && $r['code'] === ATR_CODE
            && $r['grant_type'] === 'authorization_code'
            && count(explode('.', $r['client_secret'])) === 3);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === ATR_REVOKE_URL
            && $r->isForm()
            && $r['client_id'] === 'com.datavallis.petprep'
            && $r['token'] === 'ref-1'
            && $r['token_type_hint'] === 'refresh_token'
            && ! isset($r['code']));
    });

    it('falls back to the access token when Apple returns no refresh token', function () {
        atrConfigureKey();
        atrFake([ATR_TOKEN_URL => Http::response(['access_token' => 'acc-only']), ATR_REVOKE_URL => Http::response('', 200)]);

        atrRun(new RevokeAppleTokenJob(ATR_CODE));

        Http::assertSent(fn (HttpRequest $r) => $r->url() === ATR_REVOKE_URL && $r['token'] === 'acc-only' && $r['token_type_hint'] === 'access_token');
    });

    it('stops without retry or exception on invalid_grant (expired / used code)', function () {
        atrConfigureKey();
        atrFake([ATR_TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);

        $logs = atrLogs(fn () => atrRun(new RevokeAppleTokenJob(ATR_CODE)));

        Http::assertSentCount(1);
        Http::assertNotSent(fn (HttpRequest $r) => $r->url() === ATR_REVOKE_URL);
        expect(collect($logs)->contains(fn ($l) => str_contains($l, 'invalid_grant')))->toBeTrue();
    });

    it('throws on a network error or 5xx during the exchange so the queue retries (3 tries)', function () {
        atrConfigureKey();
        expect((new RevokeAppleTokenJob('x'))->tries)->toBe(3);

        atrFake([ATR_TOKEN_URL => fn () => throw new ConnectionException('connection refused')]);
        expect(fn () => atrRun(new RevokeAppleTokenJob(ATR_CODE)))
            ->toThrow(ConnectionException::class);

        atrFake([ATR_TOKEN_URL => Http::response(['error' => 'server'], 503)]);
        try {
            atrRun(new RevokeAppleTokenJob(ATR_CODE));
            $this->fail('expected an exception');
        } catch (RuntimeException $e) {
            expect($e->getMessage())->not->toContain(ATR_CODE);
        }
    });

    it('retries the revoke call in place on 5xx / network errors (the code is single-use)', function () {
        atrConfigureKey();
        atrFake([
            ATR_TOKEN_URL => Http::response(['refresh_token' => 'ref-2']),
            ATR_REVOKE_URL => Http::sequence()->push('', 503)->pushFailedConnection()->push('', 200),
        ]);

        atrRun(new RevokeAppleTokenJob(ATR_CODE));

        Http::assertSentCount(4); // 1 exchange + 3 revoke attempts
        Http::assertSentInOrder([
            fn (HttpRequest $r) => $r->url() === ATR_TOKEN_URL,
            fn (HttpRequest $r) => $r->url() === ATR_REVOKE_URL,
            fn (HttpRequest $r) => $r->url() === ATR_REVOKE_URL,
            fn (HttpRequest $r) => $r->url() === ATR_REVOKE_URL,
        ]);
    });

    it('makes no HTTP call at all without a configured key', function () {
        $logs = atrLogs(fn () => atrRun(new RevokeAppleTokenJob(ATR_CODE)));

        Http::assertNothingSent();
        expect(collect($logs)->contains(fn ($l) => str_contains($l, 'Apple token revocation skipped: Sign in with Apple key is not configured.')))->toBeTrue();
    });

    it('never writes the authorization code into a log line (deletion, success, refusal, failure)', function () {
        $logs = atrLogs(function () {
            atrConfigureKey();
            // The sync test queue runs the job inside the deletion request.
            atrFake([ATR_TOKEN_URL => Http::response(atrTokenResponse(['refresh_token' => 'ref-3'])), ATR_REVOKE_URL => Http::response('', 200)]);
            atrDelete(atrAppleParent(), ['provider' => 'apple', 'id_token' => atrIdToken(), 'nonce' => ATR_NONCE, 'authorization_code' => ATR_CODE])->assertOk();

            atrFake([ATR_TOKEN_URL => Http::response(['refresh_token' => 'ref-3']), ATR_REVOKE_URL => Http::response('', 200)]);
            atrRun(new RevokeAppleTokenJob(ATR_CODE));
            atrFake([ATR_TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);
            atrRun(new RevokeAppleTokenJob(ATR_CODE));
            atrFake([ATR_TOKEN_URL => Http::response(['refresh_token' => 'ref-4']), ATR_REVOKE_URL => Http::response(['error' => 'invalid_client'], 400)]);
            atrRun(new RevokeAppleTokenJob(ATR_CODE));
        });

        expect($logs)->not->toBeEmpty();
        foreach ($logs as $line) {
            expect($line)->not->toContain(ATR_CODE)->not->toContain('ref-3')->not->toContain('ref-4');
        }
    });

    it('runs the real job through the sync queue after the deletion (end to end)', function () {
        atrConfigureKey();
        atrFake([
            ATR_TOKEN_URL => Http::response(atrTokenResponse(['refresh_token' => 'ref-e2e'])),
            ATR_REVOKE_URL => Http::response('', 200),
        ]);
        $parent = atrAppleParent();

        atrDelete($parent, ['provider' => 'apple', 'id_token' => atrIdToken(), 'nonce' => ATR_NONCE, 'authorization_code' => ATR_CODE])->assertOk();

        Http::assertSent(fn (HttpRequest $r) => $r->url() === ATR_REVOKE_URL && $r['token'] === 'ref-e2e');
        expect(User::find($parent->id))->toBeNull();
    });
});

/* ─────────────────────────── QA round 2 ─────────────────────────── */

describe('client id, subject check, log levels, follow-up job', function () {
    it('uses the aud of the verified Apple token as client id — even when a Services ID is listed first', function () {
        atrConfigureKey();
        config(['services.apple.audiences' => ['si.petprep.web', 'com.datavallis.petprep']]);
        atrFake([ATR_TOKEN_URL => Http::response(atrTokenResponse()), ATR_REVOKE_URL => Http::response('', 200)]);

        atrDelete(atrAppleParent(), ['provider' => 'apple', 'id_token' => atrIdToken(['aud' => 'com.datavallis.petprep']), 'nonce' => ATR_NONCE, 'authorization_code' => ATR_CODE])
            ->assertOk();

        $sub = fn (HttpRequest $r) => json_decode(RsaJwt::base64UrlDecode(explode('.', $r['client_secret'])[1]), true)['sub'];
        Http::assertSent(fn (HttpRequest $r) => $r->url() === ATR_TOKEN_URL && $r['client_id'] === 'com.datavallis.petprep' && $sub($r) === 'com.datavallis.petprep');
        Http::assertSent(fn (HttpRequest $r) => $r->url() === ATR_REVOKE_URL && $r['client_id'] === 'com.datavallis.petprep');
    });

    it('a token issued to the Services ID revokes with the Services ID; APPLE_CLIENT_ID overrides both', function () {
        atrConfigureKey();
        config(['services.apple.audiences' => ['si.petprep.web', 'com.datavallis.petprep']]);
        atrFake([ATR_TOKEN_URL => Http::response(atrTokenResponse()), ATR_REVOKE_URL => Http::response('', 200)]);
        atrDelete(atrAppleParent(), ['provider' => 'apple', 'id_token' => atrIdToken(['aud' => 'si.petprep.web']), 'nonce' => ATR_NONCE, 'authorization_code' => ATR_CODE])
            ->assertOk();
        Http::assertSent(fn (HttpRequest $r) => $r->url() === ATR_TOKEN_URL && $r['client_id'] === 'si.petprep.web');

        config(['services.apple.client_id' => 'override.client']);
        atrFake([ATR_TOKEN_URL => Http::response(atrTokenResponse()), ATR_REVOKE_URL => Http::response('', 200)]);
        atrRun(new RevokeAppleTokenJob(ATR_CODE, 'si.petprep.web', ATR_SUB));
        Http::assertSent(fn (HttpRequest $r) => $r->url() === ATR_TOKEN_URL && $r['client_id'] === 'override.client');

        config(['services.apple.client_id' => null]);
        expect(app(AppleClientSecret::class)->clientId(null))->toBe('com.datavallis.petprep');
    });

    it('does not revoke when Apple returns another account (sub mismatch) or no id_token', function () {
        atrConfigureKey();
        foreach ([atrTokenResponse(['refresh_token' => 'r'], 'someone-else'), atrTokenResponse(['refresh_token' => 'r'], null)] as $body) {
            atrFake([ATR_TOKEN_URL => Http::response($body), ATR_REVOKE_URL => Http::response('', 200)]);
            $levels = atrLevels(fn () => atrRun(new RevokeAppleTokenJob(ATR_CODE, null, ATR_SUB)));

            Http::assertNotSent(fn (HttpRequest $r) => $r->url() === ATR_REVOKE_URL);
            expect(collect($levels)->contains(fn ($l) => $l[0] === 'error' && str_contains($l[1], 'another Apple account')))->toBeTrue();
        }
    });

    it('logs invalid_grant at info and every other refusal at error', function () {
        atrConfigureKey();
        $cases = [
            [ATR_TOKEN_URL, ['error' => 'invalid_grant'], 400, 'info'],
            [ATR_TOKEN_URL, ['error' => 'invalid_client'], 400, 'error'],
            [ATR_TOKEN_URL, ['error' => 'unauthorized_client'], 401, 'error'],
            [ATR_TOKEN_URL, ['oops' => true], 404, 'error'],
            [ATR_REVOKE_URL, ['error' => 'invalid_client'], 400, 'error'],
        ];
        foreach ($cases as [$url, $body, $status, $level]) {
            $fakes = [ATR_TOKEN_URL => Http::response(atrTokenResponse()), ATR_REVOKE_URL => Http::response('', 200)];
            $fakes[$url] = Http::response($body, $status);
            atrFake($fakes);

            $levels = atrLevels(fn () => atrRun(new RevokeAppleTokenJob(ATR_CODE, null, ATR_SUB)));

            $refusal = collect($levels)->first(fn ($l) => str_contains($l[1], 'stopped') || str_contains($l[1], 'refused'));
            expect($refusal)->not->toBeNull()->and($refusal[0])->toBe($level, "{$url} {$status}");
        }
    });

    it('treats 429 on the exchange as transient (throws → queue retry)', function () {
        atrConfigureKey();
        atrFake([ATR_TOKEN_URL => Http::response(['error' => 'rate'], 429)]);

        expect(fn () => atrRun(new RevokeAppleTokenJob(ATR_CODE)))->toThrow(RuntimeException::class, 'HTTP 429');
    });

    it('revoke unavailable 3× → hands the token to RevokeAppleRefreshTokenJob on `auth`, no exception, token not logged', function () {
        atrConfigureKey();
        Queue::fake([RevokeAppleRefreshTokenJob::class]);
        atrFake([ATR_TOKEN_URL => Http::response(atrTokenResponse(['refresh_token' => 'ref-later'])), ATR_REVOKE_URL => Http::response('', 503)]);

        $levels = atrLevels(fn () => atrRun(new RevokeAppleTokenJob(ATR_CODE, null, ATR_SUB)));

        Http::assertSentCount(1 + RevokeAppleTokenJob::REVOKE_ATTEMPTS);
        Queue::assertPushedOn('auth', RevokeAppleRefreshTokenJob::class);
        expect(collect($levels)->contains(fn ($l) => $l[0] === 'warning' && str_contains($l[1], 'postponed')))->toBeTrue();
        foreach ($levels as [, $line]) {
            expect($line)->not->toContain('ref-later')->not->toContain(ATR_CODE);
        }
    });

    it('a non-transient revoke refusal is final: error log, no follow-up job', function () {
        atrConfigureKey();
        Queue::fake([RevokeAppleRefreshTokenJob::class]);
        atrFake([ATR_TOKEN_URL => Http::response(atrTokenResponse()), ATR_REVOKE_URL => Http::response(['error' => 'invalid_request'], 400)]);

        $levels = atrLevels(fn () => atrRun(new RevokeAppleTokenJob(ATR_CODE, null, ATR_SUB)));

        Http::assertSentCount(2);
        Queue::assertNothingPushed();
        expect(collect($levels)->contains(fn ($l) => $l[0] === 'error' && str_contains($l[1], 'invalid_request')))->toBeTrue();
    });

    it('the follow-up job: 5 tries with 1 / 5 / 15 / 60 min backoff, revokes, retries on 5xx, error on refusal and when exhausted', function () {
        atrConfigureKey();
        $job = new RevokeAppleRefreshTokenJob('ref-x', 'refresh_token', 'com.datavallis.petprep');
        expect($job->tries)->toBe(5)->and($job->backoff)->toBe([60, 300, 900, 3600]);

        atrFake([ATR_REVOKE_URL => Http::response('', 200)]);
        atrRun($job);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === ATR_REVOKE_URL && $r['token'] === 'ref-x' && $r['token_type_hint'] === 'refresh_token'
            && $r['client_id'] === 'com.datavallis.petprep');

        atrFake([ATR_REVOKE_URL => Http::response('', 502)]);
        expect(fn () => atrRun($job))->toThrow(RuntimeException::class);

        atrFake([ATR_REVOKE_URL => Http::response(['error' => 'invalid_client'], 400)]);
        $levels = atrLevels(function () use ($job) {
            atrRun($job);
            $job->failed(new RuntimeException('x'));
        });
        expect(collect($levels)->where(0, 'error')->count())->toBe(2);
        foreach ($levels as [, $line]) {
            expect($line)->not->toContain('ref-x');
        }
    });
});

describe('no revocation when nothing is deleted', function () {
    it('re-auth succeeds but the deletion is refused (paid challenge not acknowledged) → no job', function () {
        Queue::fake([RevokeAppleTokenJob::class]);
        $parent = atrAppleParent();
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        Pet::factory()->borderCollie()->legacyTrial()->create([
            'user_id' => $child->id, 'challenge_paid_at' => now(), 'challenge_paid_source' => 'purchase',
        ]);

        atrDelete($parent, ['provider' => 'apple', 'id_token' => atrIdToken(), 'nonce' => ATR_NONCE, 'authorization_code' => ATR_CODE])
            ->assertStatus(422)->assertJsonPath('reason', 'paid_challenge_ack_required');

        expect(User::find($parent->id))->not->toBeNull();
        Queue::assertNothingPushed();
    });

    it('an idempotent second deletion (parents_deleted = 0) queues nothing', function () {
        Queue::fake([RevokeAppleTokenJob::class]);
        $parent = atrAppleParent();
        $service = app(AccountDeletionService::class);

        expect($service->deleteParentAccount($parent, false, ATR_CODE, 'com.datavallis.petprep')['parents_deleted'])->toBe(1);
        Queue::assertPushed(RevokeAppleTokenJob::class, 1);

        expect($service->deleteParentAccount($parent, false, ATR_CODE, 'com.datavallis.petprep')['parents_deleted'])->toBe(0);
        Queue::assertPushed(RevokeAppleTokenJob::class, 1);
    });
});
