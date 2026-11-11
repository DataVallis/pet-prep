<?php

namespace App\Support;

/**
 * A constant bcrypt hash of a random, discarded password (cost 12 = production
 * BCRYPT_ROUNDS). Password checks run Hash::check against it when the account is
 * unknown or has no password, so every failure costs exactly ONE bcrypt run —
 * the same as a known account (QA M1 on #1062: a per-request Hash::make made
 * unknown e-mails measurably slower under PHP-FPM → account enumeration).
 * Never matches anything; never compute it at runtime.
 */
final class DummyPasswordHash
{
    public const VALUE = '$2y$12$y7HaKp0jImU6bJflH9AQyOcN4II4MmeqLXORtJALZ43aeURj3aLMS';
}
