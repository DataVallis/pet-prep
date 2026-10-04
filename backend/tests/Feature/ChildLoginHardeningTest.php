<?php

use App\Http\Controllers\PairingController;
use App\Models\ChildLoginPin;
use App\Models\Pet;
use App\Models\User;
use App\Services\ChildPinLoginService;
use App\Services\ChildProfileService;
use App\Services\PairingService;
use App\Support\ClientIp;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| PR #16 review follow-ups: device-name privacy, IPv6 /64 limits, locked
| device revocation, uniform legacy pairing error, trusted-proxy config.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    seedBreedConfigs();
    $this->withoutMiddleware([ThrottleRequests::class]);
});

function hdPin(User $parent, User $child): string
{
    return app(ChildPinLoginService::class)->generatePin($parent, $child->id, null)['pin'];
}

function hdLogin(string $pin, string $device, string $ip = '198.51.100.7'): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => $device]);
}

describe('child device names are never stored raw', function () {
    it('replaces a name-bearing device name with a fixed label', function (string $sent, string $stored) {
        $parent = User::factory()->parent()->create();
        $child = app(ChildProfileService::class)->createChild($parent, 'Maja', null);

        hdLogin(hdPin($parent, $child), $sent)->assertOk();

        expect(PersonalAccessToken::sole()->name)->toBe($stored);
    })->with([
        'apostrophe + surname' => ["Maja Novak's iPhone", 'child-device'],
        'non-ascii' => ['Žanov telefon', 'child-device'],
        'too long' => [str_repeat('a', 41), 'child-device'],
        'emoji' => ['iPhone 🐶', 'child-device'],
        'model only' => ['iPhone', 'iPhone'],
        'android model' => ['Samsung SM-A515F', 'Samsung SM-A515F'],
        'brackets and dot' => ['Pixel 8 (v2.1)', 'Pixel 8 (v2.1)'],
    ]);

    it('applies the same rule to a legacy e-mail child login, not to parents', function () {
        $parent = User::factory()->parent()->create(['email' => 'p@example.test']);
        $child = User::factory()->child()->create(['email' => 'c@example.test', 'parent_id' => $parent->id]);

        postJson('/api/login', ['email' => 'c@example.test', 'password' => 'password', 'device_name' => "Maja Novak's iPhone"])->assertOk();
        postJson('/api/login', ['email' => 'p@example.test', 'password' => 'password', 'device_name' => "Mama's iPhone"])->assertOk();

        expect($child->tokens()->sole()->name)->toBe('child-device')
            ->and($parent->tokens()->sole()->name)->toBe("Mama's iPhone");
    });
});

describe('IPv6 clients are limited per /64', function () {
    it('maps addresses to their rate-limit key', function () {
        expect(ClientIp::rateLimitKey('2001:db8:1:2:aaaa:bbbb:cccc:dddd'))->toBe('2001:db8:1:2::/64')
            ->and(ClientIp::rateLimitKey('2001:db8:1:2::1'))->toBe('2001:db8:1:2::/64')
            ->and(ClientIp::rateLimitKey('2001:db8:1:3::1'))->toBe('2001:db8:1:3::/64')
            ->and(ClientIp::rateLimitKey('203.0.113.9'))->toBe('203.0.113.9')
            ->and(ClientIp::rateLimitKey('::ffff:203.0.113.9'))->toBe('203.0.113.9')
            ->and(ClientIp::rateLimitKey(null))->toBe('');
    });

    it('locks out the whole /64 after 10 failures, not a neighbouring prefix', function () {
        $parent = User::factory()->parent()->create();
        $pin = hdPin($parent, app(ChildProfileService::class)->createChild($parent, 'Maja', null));
        $wrong = $pin === '000000' ? '000001' : '000000';

        // Ten different addresses of one /64 (privacy addresses rotate).
        for ($i = 1; $i <= ChildPinLoginService::MAX_IP_FAILURES; $i++) {
            hdLogin($wrong, 'x', "2001:db8:1:2::{$i}")->assertUnprocessable();
        }

        hdLogin($pin, 'x', '2001:db8:1:2:ffff::99')->assertStatus(429)->assertJsonPath('reason', 'too_many_attempts');
        expect(RateLimiter::attempts('child-pin-login:failures:ip:2001:db8:1:2::/64'))->toBe(ChildPinLoginService::MAX_IP_FAILURES);

        hdLogin($pin, 'x', '2001:db8:1:3::1')->assertOk();
    });

    it('route limiter counts a /64 as one client', function () {
        $this->withMiddleware(ThrottleRequests::class);

        for ($i = 1; $i <= 10; $i++) {
            hdLogin('bad', 'x', "2001:db8:9:9::{$i}")->assertUnprocessable();
        }
        hdLogin('bad', 'x', '2001:db8:9:9::abcd')->assertStatus(429);
        hdLogin('bad', 'x', '2001:db8:9:a::1')->assertUnprocessable();
    });
});

describe('revoking devices locks the child row', function () {
    it('takes the child row lock before deleting tokens and PINs', function () {
        $parent = User::factory()->parent()->create();
        $child = app(ChildProfileService::class)->createChild($parent, 'Maja', null);
        hdLogin(hdPin($parent, $child), 'iPhone')->assertOk();
        hdPin($parent, $child);

        $order = [];
        DB::listen(function ($q) use (&$order) {
            if (str_contains($q->sql, 'from "users"') && str_contains($q->sql, 'for update')) {
                $order[] = 'lock-child';
            } elseif (str_starts_with($q->sql, 'delete from "personal_access_tokens"')) {
                $order[] = 'delete-tokens';
            } elseif (str_starts_with($q->sql, 'update "child_login_pins"')) {
                $order[] = 'revoke-pins';
            }
        });

        $result = app(ChildProfileService::class)->revokeDevices($child);

        expect($order)->toBe(['lock-child', 'delete-tokens', 'revoke-pins'])
            ->and($result)->toBe(['tokens' => 1, 'pins' => 1])
            ->and(ChildLoginPin::open()->count())->toBe(0);
    });
});

describe('deprecated POST /api/child/pair gives one answer for every refusal', function () {
    it('wrong PIN, expired PIN, already paired child and a PIN of a non-parent look the same', function () {
        $parent = User::factory()->parent()->create();
        $bodies = [];

        // Wrong PIN.
        $child = User::factory()->child()->create();
        actingAs($child, 'sanctum');
        $bodies['wrong'] = postJson('/api/child/pair', ['pin' => '000000'])->assertUnprocessable()->json();

        // Expired PIN.
        $expired = app(PairingService::class)->generatePin($parent)['pin'];
        $this->travel(16)->minutes();
        $bodies['expired'] = postJson('/api/child/pair', ['pin' => $expired])->assertUnprocessable()->json();

        // Already paired child.
        $paired = User::factory()->child()->create(['parent_id' => $parent->id]);
        Pet::factory()->create(['user_id' => $paired->id]);
        $pin = app(PairingService::class)->generatePin($parent)['pin'];
        actingAs($paired, 'sanctum');
        $bodies['paired'] = postJson('/api/child/pair', ['pin' => $pin])->assertUnprocessable()->json();

        // A PIN stored on a non-parent row (parent issue).
        $oddUser = User::factory()->child()->create();
        User::whereKey($oddUser->id)->update(['pairing_pin' => '424242', 'pin_expires_at' => now()->addMinutes(10)]);
        actingAs($child, 'sanctum');
        $bodies['not_parent'] = postJson('/api/child/pair', ['pin' => '424242'])->assertUnprocessable()->json();

        $expected = ['message' => PairingController::PAIR_REFUSED_MESSAGE, 'reason' => 'pairing_refused'];
        foreach ($bodies as $body) {
            expect($body)->toBe($expected);
        }
    });
});

describe('trusted proxies come from config', function () {
    it('reads config/trustedproxy.php per request', function () {
        expect(config('trustedproxy.proxies'))->toContain('172.16.0.0/12');

        $parent = User::factory()->parent()->create();
        hdPin($parent, app(ChildProfileService::class)->createChild($parent, 'Maja', null));

        // Default config trusts the Docker network → the forwarded client counts.
        app('auth')->forgetGuards();
        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.77'])
            ->postJson('/api/child/pin-login', ['pin' => '000000', 'device_name' => 'x']);
        expect(RateLimiter::attempts('child-pin-login:failures:ip:203.0.113.77'))->toBe(1);

        // Narrow the list at runtime → the same peer is no longer trusted.
        config(['trustedproxy.proxies' => ['10.9.9.9']]);
        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.78'])
            ->postJson('/api/child/pin-login', ['pin' => '000000', 'device_name' => 'x']);
        expect(RateLimiter::attempts('child-pin-login:failures:ip:203.0.113.78'))->toBe(0)
            ->and(RateLimiter::attempts('child-pin-login:failures:ip:172.18.0.5'))->toBe(1);
    });
});
