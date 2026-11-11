<?php

namespace App\Support;

/**
 * A fixed bcrypt hash (cost 12 = production) of a random value nobody knows, checked
 * when an e-mail is unknown or has no password, so every failed password sign-in costs
 * exactly one bcrypt run. Never computed per request: under PHP-FPM a static cache
 * resets each request and Hash::make would add a second bcrypt run (timing oracle).
 */
final class DummyPasswordHash
{
    public const VALUE = '$2y$12$PqIGHHrD8IgPhyvedW4l1eVpqm0MOn.wfkERm3ivWk.UxPiaIRb.m';
}
