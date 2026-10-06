<?php

namespace Tests;

use App\Enums\TokenAbility;
use App\Models\User;
use App\Services\BehaviourEventService;
use App\Services\HygieneEventService;
use App\Services\TrainingService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase wraps every test in a transaction. FalGateway refuses to
        // call fal.ai inside a transaction (PR #22 review) — treat this one as the
        // ambient level so only transactions opened by the code under test count.
        config(['media.ambient_transaction_level' => DB::transactionLevel()]);

        // The hygiene / chewing RNGs are salted with APP_KEY, which CI generates
        // fresh for every run — so random event times differed between runs and
        // a test that ignored them failed now and then. Pin one salt for the
        // whole suite (useHygieneSalt() / useBehaviourSalt() still override it).
        $this->app->when(HygieneEventService::class)->needs('$seedSalt')->give(self::RNG_SALT);
        $this->app->when(BehaviourEventService::class)->needs('$seedSalt')->give(self::RNG_SALT);
        $this->app->when(TrainingService::class)->needs('$seedSalt')->give(self::RNG_SALT);
    }

    public const RNG_SALT = 'petprep-test-suite';

    /**
     * `actingAs($user, 'sanctum')` (M2-03): the user gets an in-memory
     * Sanctum token with the ability of their role (`parent` / `child`), as
     * a real login issues — so route groups with `ability:*` behave as in
     * production. A real (persisted) token already attached is kept.
     * Use actingAsWithAbilities() for anything else (e.g. legacy ['*']).
     */
    public function be(Authenticatable $user, $guard = null)
    {
        if ($guard === 'sanctum' && $user instanceof User) {
            $current = $user->currentAccessToken();
            if (! ($current instanceof PersonalAccessToken && $current->exists)) {
                return $this->actingAsWithAbilities($user, TokenAbility::abilitiesFor($user));
            }
        }

        return parent::be($user, $guard);
    }

    /**
     * Authenticate through the sanctum guard with an unsaved token carrying
     * exactly $abilities (unlike Sanctum::actingAs' mock, a missing ability
     * answers false instead of throwing).
     *
     * @param  list<string>  $abilities
     */
    public function actingAsWithAbilities(User $user, array $abilities): static
    {
        $token = (new PersonalAccessToken)->forceFill([
            'name' => 'test',
            'abilities' => $abilities,
        ]);
        $user->withAccessToken($token);

        if ($user->wasRecentlyCreated) {
            $user->wasRecentlyCreated = false;
        }

        app('auth')->guard('sanctum')->setUser($user);
        app('auth')->shouldUse('sanctum');

        return $this;
    }
}
