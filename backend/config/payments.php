<?php

/*
| Payments (M3-07 – M3-11, PAYMENTS_SPEC).
|
| `enforced` — kill switch for the payment lock (QA PR #67 B1). While false, an
| unpaid challenge pet stays playable: no payment lock (not at birth either —
| M3-13), no lock pushes, status stays "trial". Turn it on only when RevenueCat, the
| store products (`petprep_challenge_12w`), REVENUECAT_WEBHOOK_SECRET and an
| app build with the paywall are all live (DEPLOYMENT.md, release step).
*/

return [
    'enforced' => (bool) env('PAYMENTS_ENFORCED', false),
];
