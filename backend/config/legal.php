<?php

/*
| Legal texts the parent accepts at sign-up (M2-10a). The texts themselves
| (terms of use, privacy policy at petprep.si/pogoji and /zasebnost) are
| still pending — growth-marketer + lawyer before the beta. Bump the version
| whenever a published text changes; users.terms_version records which one
| a parent accepted (stored only, returned nowhere).
*/
return [
    'terms_version' => env('LEGAL_TERMS_VERSION', 'draft-2026-10'),
];
