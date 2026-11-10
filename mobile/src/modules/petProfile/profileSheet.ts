/**
 * M5-F05 — content of the child HUD's "about your pup" sheet. The HUD header has room for
 * one short line ("Border Collie · Puppy · 2 months · Bought from a breeder · Grows into …"
 * was cut off on a 375 pt phone), so tapping it opens a sheet with every fact in full:
 * breed, stage, age, origin, when the next stage starts, meals per day. Pure (texts are
 * read at call time — call while rendering). Never shows values the server marks as
 * unverified (`readPetProfile` already drops them → the row is simply missing).
 */

import { t } from '@/i18n';
import {
  PET_PROFILE_STRINGS,
  formatDogAge,
  mealsLine,
  nextStageLine,
  originLine,
  type PetProfileInfo,
} from '@/modules/petProfile/petProfile';

export type ProfileSheetRowKey = 'breed' | 'stage' | 'age' | 'origin' | 'nextStage' | 'meals' | 'challenge';

const CHALLENGE_WEEKS = 12;

export interface ProfileSheetRow {
  key: ProfileSheetRowKey;
  label: string;
  value: string;
}

/**
 * Rows in display order; a row whose value is unknown is left out (never "—" or a guess).
 * `challengeWeeks` (M6-U01): whole weeks of the 12-week challenge of a pet a parent upgraded
 * from the free plan (server `virtual_age_months`, counted from the purchase) → »Izziv: Teden N
 * od 12«; null = no such row (every other pet: the sheet is unchanged).
 */
export function profileSheetRows(breedLabel: string, profile: PetProfileInfo, challengeWeeks: number | null = null): ProfileSheetRow[] {
  const values: Record<ProfileSheetRowKey, string | null> = {
    breed: breedLabel.trim() === '' ? null : breedLabel,
    stage: profile.stage ? PET_PROFILE_STRINGS.stages[profile.stage] : null,
    age: formatDogAge(profile.ageMonths),
    origin: originLine(profile),
    nextStage: nextStageLine(profile, 'child'),
    meals: mealsLine(profile),
    challenge:
      challengeWeeks === null
        ? null
        : t('child:hud.profileSheet.challengeWeek', { week: Math.min(CHALLENGE_WEEKS, Math.max(0, Math.floor(challengeWeeks)) + 1), total: CHALLENGE_WEEKS }),
  };
  const order: readonly ProfileSheetRowKey[] = ['breed', 'stage', 'age', 'origin', 'nextStage', 'meals', 'challenge'];
  return order.flatMap((key) => {
    const value = values[key];
    return value === null ? [] : [{ key, label: t(`child:hud.profileSheet.labels.${key}`), value }];
  });
}

/** Screen-reader label of the tappable header: the whole profile in one sentence. */
export function profileHeaderA11y(rows: readonly ProfileSheetRow[]): string {
  return rows.map((r) => r.value).join(', ');
}
