<?php

// M6-S01: `message` texts of POST /api/gift/redeem (logs / debugging — the website maps `error`).
return [
    'invalid_request' => 'Please send a valid voucher code.',
    'invalid_code' => 'This voucher code is not recognised.',
    'expired' => 'This voucher has expired (vouchers are valid for 12 months from purchase).',
    'refunded' => 'This voucher is no longer valid (refunded or cancelled).',
    'unauthenticated' => 'Please sign in again (the sign-in has expired or is not valid here). The code has not been used.',
    'not_parent' => 'Only a parent account with a family can redeem a voucher. The code has not been used.',
    'already_redeemed' => 'This voucher has already been redeemed in another family account.',
    'rate_limited' => 'Too many attempts. Please try again later.',
    'upstream_error' => 'The voucher cannot be checked right now. Nothing has been used.',
    'unavailable' => 'Voucher redemption is not available right now.',
];
