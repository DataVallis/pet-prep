/** All user-visible strings of the family time zone setting (`parent:familyZone`, David 2026-11-08). */

import { t } from '@/i18n';
import { strings } from '@/i18n/strings';
import { whenText } from '@/modules/childPet/familyTime';
import type { TimezoneChangeError } from './familyZone';

export const FAMILY_ZONE_STRINGS = strings('parent', 'familyZone', {
  banner: (phone: string, family: string) => t('parent:familyZone.banner', { phone, family }),
  familyTimeNote: (city: string) => t('parent:familyZone.familyTimeNote', { city }),
  // Time zone per pet (David 2026-11-09).
  petChangeA11y: (pet: string) => t('parent:familyZone.petChangeA11y', { pet }),
  followFamilyOption: (city: string) => t('parent:familyZone.followFamilyOption', { city }),
  petSaved: (pet: string) => t('parent:familyZone.petSaved', { pet }),
  petBanner: (phone: string, pet: string, petZone: string) => t('parent:familyZone.petBanner', { phone, pet, petZone }),
  petBannerList: (phone: string) => t('parent:familyZone.petBannerList', { phone }),
  petBannerRow: (pet: string, petZone: string) => t('parent:familyZone.petBannerRow', { pet, petZone }),
  petTimeNote: (city: string) => t('parent:familyZone.petTimeNote', { city }),
});

/** The sheet's error line; »Znova jutri ob 19:30« read in the (current) family zone. */
export function timezoneErrorText(error: TimezoneChangeError, familyZone: string | null, nowMs: number): string {
  switch (error.kind) {
    case 'tooSoon': {
      const when = whenText(error.nextAllowedAt, new Date(nowMs).toISOString(), familyZone);
      return when
        ? t('parent:familyZone.errors.tooSoon', { when })
        : FAMILY_ZONE_STRINGS.errors.tooSoonNoTime;
    }
    case 'invalid':
      return FAMILY_ZONE_STRINGS.errors.invalid;
    case 'offline':
      return FAMILY_ZONE_STRINGS.errors.offline;
    default:
      return FAMILY_ZONE_STRINGS.errors.failed;
  }
}
