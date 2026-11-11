<?php

// M6-S01: `message` texts of POST /api/gift/redeem (logs / debugging — the website maps `error`).
return [
    'invalid_request' => 'Please send the voucher code and a valid e-mail address.',
    'invalid_code' => 'This voucher code is not recognised.',
    'expired' => 'This voucher has expired (vouchers are valid for 12 months from purchase).',
    'refunded' => 'This voucher is no longer valid (refunded or cancelled).',
    'no_account' => 'There is no parent account with this e-mail address. The code has not been used.',
    'already_redeemed' => 'This voucher has already been redeemed in another family account.',
    'rate_limited' => 'Too many attempts. Please try again later.',
    'upstream_error' => 'The voucher cannot be checked right now. Nothing has been used.',
    'unavailable' => 'Voucher redemption is not available right now.',
];
