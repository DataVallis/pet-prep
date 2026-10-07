# PetPrep — plačila, preizkus in brezplačni mešanček (M3-07 – M3-11)

> Odločil David 2026-10-07 (odgovori na vprašanja orkestratorja). Kanonično za kodo; spremembe samo z Davidovo odločitvijo.

## 1. Odločitve
| # | Odločitev |
|---|---|
| P1 | **Nakup = en 12-tedenski izziv za enega psa.** Vsak nov izziv (nov otrok ali nov pes) stane spet 49,99 €. V trgovinah je to **consumable** izdelek `petprep_challenge_12w` (prek RevenueCat); strežnik vodi, kateremu psu pripada. |
| P2 | **7-dnevni brezplačni preizkus se začne ob rojstvu psa** (ko otrok podpiše pogodbo in pes oživi), samo za pse na izzivu. |
| P3 | **Po preizkusu brez nakupa se igra ustavi:** pes čaka zaklenjen (kot hard stop — potrebe ne padajo, nič ne propade, napredek ostane), dokler starš ne kupi. |
| P4 | **Razmejitev kot v BUSINESS_MODEL §7:** mešanček je brezplačen za vedno (»peskovnik« brez 12-tedenskega programa); plačljiv izziv = 12-tedenski program, Border Collie, certifikat, celotna zgodovina, personaliziran AI pes. |

## 2. Pravila (tehnično, angleško za kodo)
- **Plan per pet:** `free` (mutt sandbox, forever) or `challenge` (12-week program). Chosen by the parent when creating the pet (dog picker): "Free mutt" or "12-week challenge — 7 days free". Border Collie (premium breeds) only with `challenge`. Join / relogin never change the plan.
- **Free plan:** breed mutt only; no "week N of 12", no challenge completion / certificate; parent dashboard history limited to 7 days; basic media tier (static video set). Everything else (needs, actions, steps, escalation, illness, game over, hard stop, quiet hours, training, behaviour) works the same.
- **Challenge status:** `trial` (born, unpaid, now < `trial_ends_at` = birth + 7 days) → `payment_required` (unpaid, now ≥ `trial_ends_at`) → `paid`. Before birth (contract not signed) the trial has not started (`trial_ends_at` null). Paying during the trial → `paid` immediately; the trial is not "extended", the challenge clock keeps running from birth.
- **`payment_required` = lock** (new lock reason, like hard stop): decay / escalation / illness counters / training decay paused; child sees a kind locked screen ("Starš mora odkleniti nadaljevanje"); parent sees the paywall for that pet. On payment the pet resumes where it stopped (pause time does not count against needs).
- **Credits:** each successful store purchase (RevenueCat NON_RENEWING_PURCHASE / INITIAL_PURCHASE of the consumable, idempotent by event id) creates one **challenge credit** for the buyer's family. A credit is assigned to exactly one pet. Assignment: the app calls `POST /api/parent/pets/{pet}/challenge/activate` after the purchase (idempotent; uses the oldest unassigned credit; 409 `no_credit` if none); the webhook auto-assigns when the family has exactly one unpaid challenge pet. Unassigned credits stay for later pets.
- **Refund** (CANCELLATION of the consumable / REFUND): the credit is revoked; if it was assigned and the challenge is not finished, the pet goes back to `payment_required` (or `trial` if still inside 7 days).
- **Existing pets** (created before this release, testers): `plan = challenge`, status `paid` with source `grandfathered` — nobody gets locked by the deploy.
- **Reminders:** parent push on trial day 6 ("Preizkus se izteče jutri") and when the lock starts (in the device language, M1-18). Child push only "Igra počaka na starša" when locked.
- **Purchases only in the parent app** (password-protected = parental gate); never in a child session.
