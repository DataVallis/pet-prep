<?php

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/*
| POST /api/login brute-force guard (David 2026-11-11): per-IP `login` throttle
| (live here: the suite strips it elsewhere) + per-account lockout in
| PasswordLoginGuard (always live).
*/

function ltParent(string $email = 'lt@example.com'): User
{
    return User::factory()->create(['email' => $email, 'password' => Hash::make('Correct-Pass-1')]);
}

function ltAttempt(string $email, string $password, string $ip = '203.0.113.10')
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/login', ['email' => $email, 'password' => $password]);
}

it('locks an account after 5 wrong passwords, from any IP, even for the right password', function () {
    ltParent();

    foreach (range(1, AppServiceProvider::LOGIN_PASSWORD_FAILURES) as $i) {
        ltAttempt('lt@example.com', 'wrong', '203.0.113.'.$i)->assertStatus(401);
    }

    $r = ltAttempt('lt@example.com', 'Correct-Pass-1', '198.51.100.7')->assertStatus(429);
    expect($r->json('message'))->toBeString()
        ->and((int) $r->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and($r->json())->not->toHaveKey('token');
});

it('normalises the e-mail (case, spaces) for the lockout', function () {
    ltParent();
    foreach (range(1, 5) as $i) {
        ltAttempt($i % 2 ? 'LT@Example.com' : ' lt@example.com', 'wrong')->assertStatus(401);
    }
    ltAttempt('lt@example.com', 'Correct-Pass-1')->assertStatus(429);
});

it('counts unknown e-mails too and does not leak whether the account exists', function () {
    foreach (range(1, 5) as $i) {
        ltAttempt('ghost@example.com', 'x')->assertStatus(401);
    }
    ltAttempt('ghost@example.com', 'x')->assertStatus(429);
});

it('does not affect other accounts', function () {
    ltParent();
    ltParent('other@example.com');
    foreach (range(1, 5) as $i) {
        ltAttempt('lt@example.com', 'wrong')->assertStatus(401);
    }
    ltAttempt('lt@example.com', 'wrong')->assertStatus(429);

    ltAttempt('other@example.com', 'Correct-Pass-1')->assertOk()->assertJsonStructure(['token', 'abilities', 'user', 'pet']);
});

it('resets the counter on a successful login', function () {
    ltParent();
    foreach (range(1, 4) as $i) {
        ltAttempt('lt@example.com', 'wrong')->assertStatus(401);
    }
    ltAttempt('lt@example.com', 'Correct-Pass-1')->assertOk();
    // 4 more wrong ones are fine again (counter was cleared) ...
    foreach (range(1, 4) as $i) {
        ltAttempt('lt@example.com', 'wrong')->assertStatus(401);
    }
    ltAttempt('lt@example.com', 'Correct-Pass-1')->assertOk();
});

it('unlocks when the window has passed', function () {
    ltParent();
    foreach (range(1, 5) as $i) {
        ltAttempt('lt@example.com', 'wrong')->assertStatus(401);
    }
    ltAttempt('lt@example.com', 'Correct-Pass-1')->assertStatus(429);

    $this->travel(AppServiceProvider::LOGIN_FAILURE_WINDOW_SECONDS + 5)->seconds();
    ltAttempt('lt@example.com', 'Correct-Pass-1')->assertOk();
});

it('normal use is not blocked: a few typos then success', function () {
    ltParent();
    ltAttempt('lt@example.com', 'typo')->assertStatus(401);
    ltAttempt('lt@example.com', 'typo2')->assertStatus(401);
    ltAttempt('lt@example.com', 'Correct-Pass-1')->assertOk();
});

it('keeps the successful response shape unchanged', function () {
    ltParent();
    ltAttempt('lt@example.com', 'Correct-Pass-1')->assertOk()
        ->assertJsonStructure(['token', 'abilities', 'user' => ['id', 'name', 'email', 'role', 'has_password', 'linked_providers'], 'pet', 'awaiting_contract']);
});

describe('per-IP throttle', function () {
    beforeEach(function () {
        $this->withMiddleware([ThrottleRequests::class]);
        RateLimiter::clear('login');
    });

    it('limits POST /api/login to 10 per minute per IP with 429 + Retry-After', function () {
        foreach (range(1, AppServiceProvider::LOGIN_PER_MINUTE) as $i) {
            // a different unknown e-mail each time: the account lock stays out of the way
            ltAttempt("ghost{$i}@example.com", 'x', '192.0.2.50')->assertStatus(401);
        }
        $r = ltAttempt('ghost99@example.com', 'x', '192.0.2.50')->assertStatus(429);
        expect($r->headers->get('Retry-After'))->not->toBeNull();

        // another IP is fine
        ltAttempt('ghost1@example.com', 'x', '192.0.2.51')->assertStatus(401);
    });

    it('keys IPv6 on its /64', function () {
        foreach (range(1, AppServiceProvider::LOGIN_PER_MINUTE) as $i) {
            ltAttempt("g{$i}@example.com", 'x', '2001:db8:1:2::'.$i)->assertStatus(401);
        }
        ltAttempt('g99@example.com', 'x', '2001:db8:1:2:ffff::1')->assertStatus(429);
        ltAttempt('g99@example.com', 'x', '2001:db8:1:3::1')->assertStatus(401);
    });

    it('lets a normal sign-in through', function () {
        ltParent();
        ltAttempt('lt@example.com', 'Correct-Pass-1', '192.0.2.60')->assertOk();
    });
});
