/** Wand v2 texts (`cat:wandV2`, GAMES_SPEC §5.4). Game-line texts are ≤ 32 characters (tested). */

import { t } from '@/i18n';
import { strings } from '@/i18n/strings';

export const WAND_V2_STRINGS = strings('cat', 'wandV2', {
  a11y: {
    progress: (done: number, total: number, stars: number) => t('cat:wandV2.a11y.progress', { done, total, stars }),
    stars: (stars: number, max: number) => t('cat:wandV2.a11y.stars', { stars, max }),
  },
});

/** Every text the hunt puts on the one game line (top bar). */
export function wandV2LineTexts(): string[] {
  const S = WAND_V2_STRINGS;
  return [S.preparing, S.ruleLine, S.freeze, S.fb.dodge, S.fb.caught, S.catchCue, S.saving];
}
