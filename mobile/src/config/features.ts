import type { Species } from '@/types';

/**
 * Build-time product switches of the app (complement the server switches in
 * `config/petprep.php`).
 *
 * `CAT_UI_READY` (M5-R06-02, M5-R06_PLAN T4): this build can show and care for a cat.
 * Only then does the app declare the `species_cat` client feature — in the parent's
 * catalogue request and generate-pin, and in the child's pin-login. The server needs BOTH
 * `PETPREP_CATS_ENABLED` and this feature before a cat appears anywhere.
 * M5-R06-09 (David 2026-10-10): switched on — the cat HUD, mini-games and parent screens
 * are built (R06-08a–d). While the server flag is still off, the server offers dogs only and
 * the app behaves exactly as before (the feature is stored with a dog's profile and ignored).
 */
export const CAT_UI_READY: boolean = true;

/** The species this build can show (QA PR #94 m3): the picker drops any other species the server sends. */
export function showableSpecies(catUiReady: boolean = CAT_UI_READY): readonly Species[] {
  return catUiReady ? ['dog', 'cat'] : ['dog'];
}
