/**
 * Texts of the cleaning games (GAMES_SPEC §6.2) — `child:messGame` (bag / cloth / box) and
 * `cat:scoop.game` (the litter scoop). Every game line is ≤ 32 characters (`GameLine`, tested).
 */

import { t } from '@/i18n';
import { strings } from '@/i18n/strings';

export const MESS_GAME_STRINGS = strings('child', 'messGame', {
  a11y: {
    progress: (done: number, total: number) => t('child:messGame.a11y.progress', { done, total }),
  },
});

export const SCOOP_GAME_STRINGS = strings('cat', 'scoop').game;
