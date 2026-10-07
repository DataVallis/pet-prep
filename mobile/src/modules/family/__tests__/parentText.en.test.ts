/**
 * M1-18: parent texts (`family`, `parent`, `account`) in English, and the Slovenian
 * CLDR plural forms the old hand-written rules got wrong (101 / 102 / 103).
 */
import { act } from '@testing-library/react-native';

import { i18n } from '@/i18n';
import { ACCOUNT_STRINGS } from '@/components/parent/AccountCard';
import { DELETION_FORM_STRINGS } from '@/components/parent/DeletionConfirmForm';
import { canSubmitDeletion, exportFileName, isConfirmWord } from '@/modules/account/account';
import { breedLabel, devicesLabel, PET_STATUS_LABELS } from '@/modules/family/family';
import { expiryText, inviteShareMessage } from '@/modules/family/invite';
import {
  activityText,
  activityWhenText,
  dayLabel,
  illnessesText,
  LIGHT_LABELS,
  missedWhenText,
  progressText,
  reasonText,
  routinesOfText,
  scoreRoutinesText,
} from '@/modules/family/scoring';
import { formatSteps } from '@/screens/parent/ChildDetailScreen';
import { DASHBOARD_STRINGS } from '@/screens/parent/ParentDashboardScreen';
import { makeMissed } from '@/test-utils/fixtures';

const TZ = 'Europe/Ljubljana';

async function switchLanguage(language: 'en' | 'sl') {
  await act(async () => {
    await i18n.changeLanguage(language);
  });
}

afterEach(async () => {
  await switchLanguage('sl');
});

describe('parent texts in English', () => {
  beforeEach(async () => {
    await switchLanguage('en');
  });

  it('plurals: devices, routines, illnesses, family subtitle', () => {
    expect(devicesLabel(1)).toBe('1 device');
    expect(devicesLabel(3)).toBe('3 devices');
    expect(routinesOfText(1, 1)).toBe('1 of 1 routine');
    expect(routinesOfText(8, 9.5)).toBe('8 of 9.5 routines');
    expect(scoreRoutinesText({ done: 4, expected: 9.5, routines: 19 })).toBe('4 of 19 routines · fair share 9.5');
    expect(illnessesText(1)).toBe('1 illness');
    expect(illnessesText(2)).toBe('2 illnesses');
    expect(DASHBOARD_STRINGS.familySubtitle(2, 1)).toBe('2 children · 1 dog');
    expect(reasonText('missed_routines', 5)).toBe('5 routines already missed today.');
    expect(progressText({ started_at: '', days_elapsed: 90, week: 12, weeks_total: 12, completed: true })).toBe(
      'Challenge complete (12 weeks)',
    );
  });

  it('dates and times', () => {
    expect(dayLabel('2026-10-04')).toBe('Sun 4 Oct');
    expect(missedWhenText(makeMissed('clean', '2026-10-03T05:00:00Z', '2026-10-03T05:50:00Z', '2026-10-03'), TZ, '2026-10-04')).toBe(
      'Sat 3 Oct · due 07:50',
    );
    expect(activityWhenText('2026-10-03T18:00:00+02:00', TZ, '2026-10-04')).toBe('yesterday 18:00');
    expect(activityWhenText('2026-10-01T18:00:00+02:00', TZ, '2026-10-04')).toBe('1 Oct 18:00');
    expect(expiryText('2026-10-05T12:30:00Z', TZ)).toBe('5 Oct at 14:30');
    expect(formatSteps(12500)).toBe('12,500');
  });

  it('labels follow the language at read time', async () => {
    expect(LIGHT_LABELS.green).toBe('All good');
    expect(PET_STATUS_LABELS.ill).toBe('The dog is at the vet');
    expect(breedLabel('mutt')).toBe('Mixed breed');
    expect(activityText({ activity_type: 'fed_pet', actor_nickname: 'Mia' })).toBe('Mia fed the dog');
    expect(activityText({ activity_type: 'fed_pet', actor_nickname: null })).toBe('Fed the dog');
    expect(activityText({ activity_type: 'something_new', actor_nickname: null })).toBe('Something_new');
    await switchLanguage('sl');
    expect(LIGHT_LABELS.green).toBe('Vse v redu');
  });

  it('invite share message', () => {
    expect(inviteShareMessage('ABCDEFGH', '5 Oct at 14:30')).toBe(
      'Join our family in the PetPrep app. Choose "I\'m a parent", sign in and enter the family code in the Controls tab: ABCD EFGH. The code is valid until 5 Oct at 14:30.',
    );
  });

  it('account deletion: the confirmation word is "DELETE" in English', () => {
    expect(DELETION_FORM_STRINGS.confirmLabel).toBe('To confirm, type DELETE');
    expect(isConfirmWord(' delete ')).toBe(true);
    expect(isConfirmWord('IZBRIŠI')).toBe(false);
    expect(canSubmitDeletion('secret', 'DELETE')).toBe(true);
    expect(ACCOUNT_STRINGS.lastParent(1, 2)[1]).toBe('your account, 1 child profile and 2 dogs,');
    expect(ACCOUNT_STRINGS.otherParentStays).toHaveLength(2);
    expect(exportFileName({ generated_at: '2026-10-05T10:00:00Z' })).toBe('petprep-export-2026-10-05.json');
  });
});

describe('Slovenian plurals follow CLDR', () => {
  it('101 is "one", 102 "two", 103 / 104 "few"', () => {
    expect(devicesLabel(101)).toBe('101 naprava');
    expect(devicesLabel(102)).toBe('102 napravi');
    expect(devicesLabel(103)).toBe('103 naprave');
    expect(devicesLabel(105)).toBe('105 naprav');
    expect(DASHBOARD_STRINGS.familySubtitle(2, 3)).toBe('2 otroka · 3 kužki');
    expect(DASHBOARD_STRINGS.familySubtitle(5, 102)).toBe('5 otrok · 102 kužka');
    expect(ACCOUNT_STRINGS.lastParent(2, 3)[1]).toBe('vaš račun, 2 otroška profila in 3 psi,');
    expect(ACCOUNT_STRINGS.lastParent(1, 5)[1]).toBe('vaš račun, 1 otroški profil in 5 psov,');
    expect(reasonText('missed_routines', 3)).toBe('Danes so zamujene že 3 rutine.');
    expect(reasonText('missed_routines', 101)).toBe('Danes je zamujena že 101 rutina.');
  });

  it('the confirmation word is "IZBRIŠI" in Slovenian', () => {
    expect(DELETION_FORM_STRINGS.confirmLabel).toBe('Za potrditev vpišite IZBRIŠI');
    expect(isConfirmWord('DELETE')).toBe(false);
    expect(isConfirmWord('izbriši')).toBe(true);
  });
});
