/**
 * Build-time product switches of the app (complement the server switches in
 * `config/petprep.php`).
 *
 * `CAT_UI_READY` (M5-R06-02, M5-R06_PLAN T4): this build can show and care for a cat.
 * Only then does the app declare the `species_cat` client feature — in the parent's
 * catalogue request and generate-pin, and in the child's pin-login. The cat HUD
 * (M5-R06-08) does not exist yet, so it stays `false`: a child app must never claim it
 * can show a cat. The server needs BOTH `PETPREP_CATS_ENABLED` and this feature before a
 * cat appears anywhere. Flip it only together with the cat HUD (R06-08) and David's
 * device test (R06-09).
 */
export const CAT_UI_READY: boolean = false;
