<?php

namespace App\Services;

use App\Enums\TokenAbility;
use App\Enums\UserRole;
use App\Models\Family;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Parent self-registration with e-mail + password (M2-10a).
 *
 * One transaction: parent user (role parent, e-mail lower case, password
 * hashed, terms_accepted_at = now) + the parent's own family in the chosen
 * timezone + the first Sanctum token with the `parent` ability. No external
 * HTTP (no breach-password lookup, no e-mail sending — verification is
 * M2-10b).
 */
class ParentRegistrationService
{
    public function __construct(private readonly FamilyService $families) {}

    /**
     * @return array{user: User, family: Family, token: string}
     *
     * @throws ValidationException e-mail taken (also when a concurrent
     *                             request won the race to the unique index)
     */
    public function register(
        string $name,
        string $email,
        string $password,
        string $timezone,
        string $deviceName,
    ): array {
        $email = User::normalizeEmail($email);

        try {
            return DB::transaction(function () use ($name, $email, $password, $timezone, $deviceName): array {
                $user = new User;
                $user->forceFill([
                    'name' => $name,
                    'email' => $email,
                    'password' => $password, // hashed by the model cast
                    'role' => UserRole::Parent,
                    // Mirror of families.timezone (legacy column, M2-01 bridge).
                    'timezone' => $timezone,
                    'terms_accepted_at' => now(),
                ]);
                $user->save();

                // The User::created hook already gave the parent a family (from
                // users.timezone); resolve it explicitly and make sure the
                // timezone is the one chosen at sign-up.
                $family = $this->families->ensureFamilyFor($user);
                if ($family->timezone !== $timezone) {
                    $family->timezone = $timezone;
                    $family->save();
                }

                $token = $user->createToken($deviceName, TokenAbility::abilitiesFor($user))->plainTextToken;

                return ['user' => $user, 'family' => $family, 'token' => $token];
            });
        } catch (UniqueConstraintViolationException) {
            // Lost a race against a concurrent sign-up with the same address.
            throw ValidationException::withMessages([
                'email' => [__('validation.unique', ['attribute' => 'email'])],
            ]);
        }
    }
}
