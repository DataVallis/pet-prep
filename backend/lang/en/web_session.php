<?php

// M6-S01b: `message` texts of POST / DELETE /api/web/parent-session (logs / debugging — the website maps `error`).
return [
    'invalid_request' => 'Please check the sign-in details.',
    'invalid_credentials' => 'The e-mail or password is not correct.',
    'invalid_token' => 'The sign-in could not be confirmed. Please try again.',
    'account_not_found' => 'There is no PetPrep account for this sign-in. Use the sign-in you use in the PetPrep app.',
    'not_parent' => 'Only a parent account can sign in here.',
    'rate_limited' => 'Too many attempts. Please try again later.',
    'provider_disabled' => 'This sign-in method is not available.',
    'provider_unavailable' => 'The sign-in provider could not be reached. Please try again.',
    'unavailable' => 'Signing in here is not available right now.',
    'token_scope' => 'This sign-in cannot be used here.',
];
