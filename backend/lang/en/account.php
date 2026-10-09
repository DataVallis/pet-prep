<?php

/*
 * Account deletion + data export (M2-08) — English (M1-18, reference language).
 */

return [
    'deletion' => [
        // The word the parent types to unlock a deletion (the app shows the word of its language).
        'confirm_word' => 'DELETE',
        'confirm_word_invalid' => 'Type :word to confirm the deletion.',
        'confirm_required' => 'Please confirm the deletion.',
        'password_required' => 'Enter your password.',
    ],

    'export' => [
        'about' => 'Family data export from the PetPrep app (GDPR Art. 15 and 20). '
            .'Times are in UTC (ISO 8601), dates (local_date) in the family’s time zone. '
            .'Links to the dogs’ images and videos are valid for a limited time (media[].expires_at, growth[] until growth_expires_at).',
        // M5-R06-06: the same text for a family with a cat ("pets" instead of "dogs"; Claude's draft, awaiting David).
        'about_pets' => 'Family data export from the PetPrep app (GDPR Art. 15 and 20). '
            .'Times are in UTC (ISO 8601), dates (local_date) in the family’s time zone. '
            .'Links to the pets’ images and videos are valid for a limited time (media[].expires_at, growth[] until growth_expires_at).',
        // Download file name: <prefix>-<family-local date>.json (ASCII only).
        'filename_prefix' => 'petprep-export',
    ],
];
